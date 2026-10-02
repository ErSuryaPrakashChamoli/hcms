<?php

namespace App\Domain\ServiceDesk\Services;

use App\Domain\Audit\Enums\AuditAction;
use App\Domain\Audit\Services\AuditRecorder;
use App\Domain\Configuration\Enums\VersionStatus;
use App\Domain\Configuration\Models\FormVersion;
use App\Domain\Configuration\Services\EmployeeRuleContext;
use App\Domain\Configuration\Services\RuleEngine;
use App\Domain\Employment\Models\Employee;
use App\Domain\Identity\Models\Role;
use App\Domain\Identity\Models\User;
use App\Domain\Identity\Scopes\AccessScope;
use App\Domain\ServiceDesk\Exceptions\ServiceDeskRuleViolation;
use App\Domain\ServiceDesk\Models\ServiceDefinition;
use App\Domain\ServiceDesk\Models\ServiceDefinitionVersion;
use App\Domain\ServiceDesk\Models\ServiceSlaPolicy;
use App\Domain\ServiceDesk\Models\TicketCategory;
use App\Domain\Workflow\Models\Workflow;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Phase 12: the HR service catalogue. A service is versioned with the Phase 11 configuration
 * lifecycle:
 * - Draft → Pending approval → Approved (Scheduled for a later date, else Active) → Superseded, or
 *   Archived;
 * - prepared by a servicedesk.manage holder, approved by a different servicedesk.catalogue_approve
 *   holder;
 * - frozen from submission, effective-dated and checksummed.
 *
 * Requests pin the version they are raised under, so a catalogue change never rewrites an open case.
 */
final class ServiceCatalogue
{
    public function __construct(
        private readonly AuditRecorder $audit,
        private readonly DomainActions $actions,
        private readonly RuleEngine $rules,
        private readonly EmployeeRuleContext $context,
    ) {}

    /** @param  array<string, mixed>  $data  code, name, category (code or id), subcategory, version fields */
    public function create(array $data, User $actor): ServiceDefinition
    {
        $this->authorise($actor, 'servicedesk.manage');
        foreach (['code', 'name'] as $field) {
            if (blank($data[$field] ?? null)) {
                throw new ServiceDeskRuleViolation("A service needs a {$field}.");
            }
        }
        if (ServiceDefinition::query()->where('code', strtoupper(trim((string) $data['code'])))->exists()) {
            throw new ServiceDeskRuleViolation('A service with that code already exists.');
        }

        return DB::transaction(function () use ($data, $actor) {
            $service = ServiceDefinition::query()->create([
                'code' => $data['code'], 'name' => $data['name'], 'ticket_category_id' => $this->categoryId($data['category'] ?? null),
                'subcategory' => $data['subcategory'] ?? null, 'status' => 'active', 'sort_order' => (int) ($data['sort_order'] ?? 0),
            ]);
            $version = new ServiceDefinitionVersion(['service_definition_id' => $service->id, 'version' => 1, 'status' => 'draft', 'prepared_by' => $actor->id, 'effective_from' => $data['effective_from'] ?? Carbon::today()->toDateString()]);
            $version->fill($this->content($data))->save();

            return $service;
        });
    }

    public function newVersion(ServiceDefinition $service, User $actor, CarbonInterface|string|null $from = null): ServiceDefinitionVersion
    {
        $this->authorise($actor, 'servicedesk.manage');

        return DB::transaction(function () use ($service, $actor, $from) {
            ServiceDefinition::query()->whereKey($service->id)->lockForUpdate()->firstOrFail();
            if (ServiceDefinitionVersion::query()->where('service_definition_id', $service->id)->whereIn('status', ['draft', 'pending_approval'])->exists()) {
                throw new ServiceDeskRuleViolation('This service already has a version in preparation.');
            }
            $base = ServiceDefinitionVersion::query()->where('service_definition_id', $service->id)->orderByDesc('version')->first();
            $number = (int) ($base?->version ?? 0) + 1;
            $version = new ServiceDefinitionVersion(['service_definition_id' => $service->id, 'version' => $number, 'status' => 'draft', 'prepared_by' => $actor->id, 'effective_from' => Carbon::parse($from ?? Carbon::tomorrow())->toDateString()]);
            $version->fill($base ? $base->only(array_diff(ServiceDefinitionVersion::CONTENT, ['service_definition_id', 'version', 'effective_from', 'change_note'])) : [])->save();

            return $version;
        });
    }

    /** @param  array<string, mixed>  $data */
    public function updateDraft(ServiceDefinitionVersion $version, array $data, User $actor): ServiceDefinitionVersion
    {
        $this->authorise($actor, 'servicedesk.manage');

        return $this->locked($version, ['draft'], function (ServiceDefinitionVersion $v) use ($data) {
            $v->fill($this->content($data) + (isset($data['effective_from']) ? ['effective_from' => Carbon::parse($data['effective_from'])->toDateString()] : []))->save();
        });
    }

    public function submit(ServiceDefinitionVersion $version, User $actor): ServiceDefinitionVersion
    {
        $this->authorise($actor, 'servicedesk.manage');

        return $this->locked($version, ['draft'], function (ServiceDefinitionVersion $v) use ($actor) {
            $this->assertComplete($v);
            $v->update(['status' => 'pending_approval', 'submitted_at' => now(), 'prepared_by' => $v->prepared_by ?? $actor->id, 'lock_version' => $v->lock_version + 1]);
            $this->audit->record(AuditAction::Submitted, 'servicedesk', $v, [['field' => 'status', 'before' => 'draft', 'after' => 'pending_approval']], $v->change_note, actor: $actor);
        });
    }

    public function approve(ServiceDefinitionVersion $version, User $actor, ?string $note = null): ServiceDefinitionVersion
    {
        $this->authorise($actor, 'servicedesk.catalogue_approve');

        return DB::transaction(function () use ($version, $actor, $note) {
            ServiceDefinition::query()->whereKey($version->service_definition_id)->lockForUpdate()->firstOrFail();
            $v = ServiceDefinitionVersion::query()->whereKey($version->id)->lockForUpdate()->firstOrFail();
            if ($v->status !== 'pending_approval') {
                throw new ServiceDeskRuleViolation('This service version is '.str_replace('_', ' ', $v->status).'; it cannot be approved.');
            }
            if ((int) $v->prepared_by === (int) $actor->id) {
                throw new ServiceDeskRuleViolation('The person who prepared a service version cannot approve it.');
            }
            $this->assertComplete($v);
            $from = Carbon::parse($v->effective_from)->startOfDay();
            $approved = ServiceDefinitionVersion::query()->where('service_definition_id', $v->service_definition_id)->whereIn('status', ServiceDefinitionVersion::APPROVED)->orderBy('effective_from')->lockForUpdate()->get();
            if ($approved->isNotEmpty() && ! $from->greaterThan($approved->last()->effective_from)) {
                throw new ServiceDeskRuleViolation('A service version must start after every approved version (latest from '.$approved->last()->effective_from->toDateString().').');
            }
            $dueNow = ! $from->isFuture();
            $previous = $approved->last();
            if ($previous !== null && $previous->effective_to === null) {
                $previous->update(['effective_to' => $from->copy()->subDay()->toDateString(), ...($dueNow && $previous->status === 'active' ? ['status' => 'superseded'] : [])]);
            }
            $v->update(['status' => $dueNow ? 'active' : 'scheduled', 'approved_by' => $actor->id, 'approved_at' => now(), 'decision_note' => $note ?: null, 'checksum' => $this->checksum($v), 'lock_version' => $v->lock_version + 1]);
            $this->audit->record(AuditAction::Approved, 'servicedesk', $v, [['field' => 'status', 'before' => 'pending_approval', 'after' => $v->status]], $note, actor: $actor, effectiveDate: $from);

            return $v;
        });
    }

    public function returnToDraft(ServiceDefinitionVersion $version, User $actor, string $note): ServiceDefinitionVersion
    {
        $this->authorise($actor, 'servicedesk.catalogue_approve');
        if (blank($note)) {
            throw new ServiceDeskRuleViolation('Returning a service version needs a note.');
        }

        return $this->locked($version, ['pending_approval'], function (ServiceDefinitionVersion $v) use ($actor, $note) {
            $v->update(['status' => 'draft', 'submitted_at' => null, 'decision_note' => $note, 'lock_version' => $v->lock_version + 1]);
            $this->audit->record(AuditAction::Rejected, 'servicedesk', $v, [['field' => 'status', 'before' => 'pending_approval', 'after' => 'draft']], $note, actor: $actor);
        });
    }

    public function archive(ServiceDefinitionVersion $version, User $actor, string $reason): ServiceDefinitionVersion
    {
        $this->authorise($actor, 'servicedesk.manage');
        if (blank($reason)) {
            throw new ServiceDeskRuleViolation('Archiving a service version needs a reason.');
        }

        return $this->locked($version, ['draft', 'pending_approval', 'scheduled'], function (ServiceDefinitionVersion $v) use ($actor, $reason) {
            $before = $v->status;
            $v->update(['status' => 'archived', 'archived_at' => now(), 'lock_version' => $v->lock_version + 1]);
            $this->audit->record(AuditAction::Archive, 'servicedesk', $v, [['field' => 'status', 'before' => $before, 'after' => 'archived']], $reason, actor: $actor);
        });
    }

    /** Scheduler: scheduled versions whose date has come become active (each version locked, once). */
    public function promoteDue(CarbonInterface|string|null $on = null): int
    {
        $day = Carbon::parse($on ?? Carbon::today())->toDateString();
        $promoted = 0;
        ServiceDefinitionVersion::query()->where('status', 'scheduled')->where('effective_from', '<=', $day.' 23:59:59')->orderBy('effective_from')->pluck('id')
            ->each(function (int $id) use (&$promoted) {
                DB::transaction(function () use ($id, &$promoted) {
                    $v = ServiceDefinitionVersion::query()->whereKey($id)->lockForUpdate()->firstOrFail();
                    if ($v->status !== 'scheduled') {
                        return;
                    }
                    ServiceDefinitionVersion::query()->where('service_definition_id', $v->service_definition_id)->where('status', 'active')->where('effective_from', '<', $v->effective_from->toDateString())
                        ->get()->each(fn (ServiceDefinitionVersion $old) => $old->update(['status' => 'superseded']));
                    $v->update(['status' => 'active']);
                    $promoted++;
                });
            });

        return $promoted;
    }

    /** The approved version in force on a date (today by default). */
    public function versionOn(ServiceDefinition|int $service, CarbonInterface|string|null $date = null): ?ServiceDefinitionVersion
    {
        $day = Carbon::parse($date ?? Carbon::today())->toDateString();

        return ServiceDefinitionVersion::query()->with(['service', 'formVersion', 'slaPolicy'])->where('service_definition_id', $service instanceof ServiceDefinition ? $service->id : $service)
            ->whereIn('status', ServiceDefinitionVersion::APPROVED)->where('effective_from', '<=', $day.' 23:59:59')
            ->where(fn ($q) => $q->whereNull('effective_to')->orWhere('effective_to', '>=', $day))->orderByDesc('effective_from')->first();
    }

    /**
     * Services the user may request now, for themselves (employee audience), for a report (manager), or
     * on someone's behalf (hr), with each one's version in force.
     *
     * @return Collection<int, ServiceDefinitionVersion>
     */
    public function availableTo(User $user, ?Employee $employee, string $audience = 'employee'): Collection
    {
        $permission = match ($audience) {
            'manager' => 'servicedesk.team', 'hr' => 'servicedesk.agent', default => 'servicedesk.request',
        };
        if (! $user->hasPermission($permission)) {
            return collect();
        }

        return ServiceDefinition::query()->where('status', 'active')->orderBy('sort_order')->orderBy('name')->get()
            ->map(fn (ServiceDefinition $s) => $this->versionOn($s))
            ->filter(fn (?ServiceDefinitionVersion $v) => $v !== null && $v->listed && $v->availableTo($audience) && ($employee === null || $this->eligible($v, $employee)))
            ->values();
    }

    /** The employee meets the version's lifecycle states, organisation scope and eligibility rule. */
    public function eligible(ServiceDefinitionVersion $version, Employee $employee): bool
    {
        $states = $version->lifecycle_states ?? [];
        if ($states !== [] && ! in_array($employee->lifecycle_state?->value ?? (string) $employee->lifecycle_state, $states, true)) {
            return false;
        }
        $scope = array_filter((array) ($version->org_scope ?? []));
        if ($scope !== []) {
            $position = $employee->positions()->withoutGlobalScope(AccessScope::class)->effectiveOn(Carbon::today())->first();
            foreach (['company_ids' => 'company_id', 'department_ids' => 'department_id', 'location_ids' => 'location_id'] as $key => $column) {
                $ids = array_map('intval', (array) ($scope[$key] ?? []));
                if ($ids !== [] && ! in_array((int) $position?->{$column}, $ids, true)) {
                    return false;
                }
            }
        }

        return empty($version->eligibility) || $this->rules->matches($version->eligibility, $this->context->build($employee));
    }

    /** @param  array<string, mixed>  $data */
    private function content(array $data): array
    {
        return collect($data)->only(array_diff(ServiceDefinitionVersion::CONTENT, ['service_definition_id', 'version', 'effective_from']))->all();
    }

    private function assertComplete(ServiceDefinitionVersion $v): void
    {
        if ($v->form_version_id !== null) {
            $form = FormVersion::query()->find($v->form_version_id);
            if ($form === null || $form->status !== VersionStatus::Published) {
                throw new ServiceDeskRuleViolation('A service form must be a published form version.');
            }
        }
        $handler = null;
        if ($v->domain_action !== null) {
            $handler = $this->actions->find($v->domain_action) ?? throw new ServiceDeskRuleViolation("Unknown domain action [{$v->domain_action}].");
        }
        if ($v->approval_required) {
            $workflow = $v->workflow_key ? Workflow::query()->where('key', $v->workflow_key)->where('status', 'active')->first() : null;
            if ($workflow === null || ! $workflow->published()->exists()) {
                throw new ServiceDeskRuleViolation('A service that needs approval needs an active, published approval workflow.');
            }
            if ($handler !== null && $handler->timing() !== 'after_approval') {
                throw new ServiceDeskRuleViolation($handler->label().' is approved in its own domain; the service must not add a second approval.');
            }
        }
        if ($v->sla_policy_id !== null && ! ServiceSlaPolicy::query()->whereKey($v->sla_policy_id)->where('status', 'active')->exists()) {
            throw new ServiceDeskRuleViolation('The SLA policy is not active.');
        }
        foreach ([['confidentiality', 'peopleos.servicedesk.confidentiality'], ['attachment_rule', 'peopleos.servicedesk.attachment_rules'], ['default_priority', 'peopleos.servicedesk.priorities']] as [$field, $config]) {
            if (! array_key_exists((string) $v->{$field}, config($config))) {
                throw new ServiceDeskRuleViolation("Unknown {$field} [{$v->{$field}}].");
            }
        }
        foreach ((array) ($v->field_security ?? []) as $key => $rule) {
            if (! array_key_exists((string) ($rule['class'] ?? 'standard'), config('peopleos.servicedesk.field_classes'))) {
                throw new ServiceDeskRuleViolation("Unknown classification for field [{$key}].");
            }
        }
        $roleIds = array_filter([(int) data_get($v->assignment, 'role_id')]);
        if ($roleIds !== [] && Role::query()->whereIn('id', $roleIds)->count() !== count($roleIds)) {
            throw new ServiceDeskRuleViolation('The assignment team (role) does not exist.');
        }
        $audiences = array_keys(array_filter((array) ($v->availability ?? ['employee' => true, 'hr' => true])));
        if ($audiences === []) {
            throw new ServiceDeskRuleViolation('A service must be available to employees, managers or HR.');
        }
        if ($handler !== null) {
            $sources = $handler->sources();
            foreach ($audiences as $audience) {
                $source = ['employee' => 'web', 'manager' => 'manager', 'hr' => 'hr'][$audience] ?? $audience;
                if (! in_array($source, $sources, true)) {
                    throw new ServiceDeskRuleViolation($handler->label().' cannot be offered to '.($audience === 'employee' ? 'employees (self-service)' : $audience.'s').'.');
                }
            }
        }
    }

    private function checksum(ServiceDefinitionVersion $v): string
    {
        return hash('sha256', json_encode(collect($v->only(ServiceDefinitionVersion::CONTENT))->map(fn ($x) => $x instanceof \DateTimeInterface ? $x->format('Y-m-d') : $x)->all()));
    }

    private function categoryId(mixed $category): ?int
    {
        if ($category === null || $category === '') {
            return null;
        }

        return (int) (is_numeric($category) ? TicketCategory::query()->whereKey((int) $category)->value('id') : TicketCategory::query()->where('code', strtoupper((string) $category))->value('id'))
            ?: throw new ServiceDeskRuleViolation('Unknown request category.');
    }

    /** @param  list<string>  $from */
    private function locked(ServiceDefinitionVersion $version, array $from, callable $callback): ServiceDefinitionVersion
    {
        return DB::transaction(function () use ($version, $from, $callback) {
            $v = ServiceDefinitionVersion::query()->whereKey($version->id)->lockForUpdate()->firstOrFail();
            if (! in_array($v->status, $from, true)) {
                throw new ServiceDeskRuleViolation('This service version is '.str_replace('_', ' ', $v->status).'; that step is not available.');
            }
            $callback($v);

            return $v->refresh();
        });
    }

    private function authorise(User $actor, string $permission): void
    {
        if (! $actor->hasPermission($permission)) {
            throw new ServiceDeskRuleViolation("This needs {$permission}.");
        }
    }
}
