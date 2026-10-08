<?php

namespace App\Domain\Workforce\Services;

use App\Domain\Audit\Enums\AuditAction;
use App\Domain\Audit\Services\AuditRecorder;
use App\Domain\Identity\Models\User;
use App\Domain\Identity\Scopes\AccessScope;
use App\Domain\Organisation\Models\Company;
use App\Domain\Workflow\Models\Workflow;
use App\Domain\Workflow\Services\WorkflowEngine;
use App\Domain\Workforce\Events\WorkforceEvent;
use App\Domain\Workforce\Models\Position;
use App\Domain\Workforce\Models\WorkforcePlan;
use App\Domain\Workforce\Models\WorkforcePlanLine;
use App\Domain\Workforce\Models\WorkforcePlanVersion;
use App\Domain\Workforce\Models\WorkforceScenario;
use Carbon\CarbonInterface;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Phase 10 workforce plans: a plan (scope, owner) with versions. A version is prepared as a draft,
 * submitted, reviewed and approved by different people (separation of duties), optionally through a
 * configured workflow pinned to its version, then published as the plan's single active version
 * (the previous one is superseded). Approved content is checksummed and locked; corrections are new
 * versions. Plans never change live positions or employees — the only bridge is an explicit
 * "propose a position from this line", which creates a proposed position that still needs approval.
 */
final class WorkforcePlans
{
    use ChecksOrganisationScope;

    public function __construct(
        private readonly OrganisationDimensions $dimensions,
        private readonly Positions $positions,
        private readonly AuditRecorder $audit,
        private readonly WorkflowEngine $workflows,
    ) {}

    /** @param  array<string, mixed>  $data */
    public function create(array $data, User $actor): WorkforcePlan
    {
        $this->authorise($actor, 'workforce.plan');
        foreach (['code', 'name', 'period_type', 'period_start', 'period_end'] as $required) {
            if (blank($data[$required] ?? null)) {
                throw new RuntimeException("A workforce plan needs {$required}.");
            }
        }
        $scope = $this->scope($data);
        $this->assertDimensionsInScope($actor, $scope);

        try {
            return DB::transaction(function () use ($data, $scope, $actor) {
                $plan = WorkforcePlan::query()->create([...$scope, 'code' => $data['code'], 'name' => $data['name'], 'owner_user_id' => $data['owner_user_id'] ?? $actor->id, 'created_by' => $actor->id]);
                $this->createVersion($plan, $data, $actor);

                return $plan->refresh();
            });
        } catch (UniqueConstraintViolationException) {
            throw new RuntimeException("Plan code {$data['code']} is already used.");
        }
    }

    /**
     * A new draft version (a correction or the next period). Lines are copied from $copyFrom when given.
     *
     * @param  array<string, mixed>  $data
     */
    public function createVersion(WorkforcePlan $plan, array $data, User $actor, ?WorkforcePlanVersion $copyFrom = null): WorkforcePlanVersion
    {
        $this->authorise($actor, 'workforce.plan');

        $this->assertRecordInScope($actor, $plan);

        return DB::transaction(function () use ($plan, $data, $actor, $copyFrom) {
            WorkforcePlan::query()->withoutGlobalScope(AccessScope::class)->whereKey($plan->id)->lockForUpdate()->first();
            $versions = WorkforcePlanVersion::query()->where('workforce_plan_id', $plan->id)->lockForUpdate();
            if ((clone $versions)->where('status', 'draft')->exists()) {
                throw new RuntimeException('This plan already has a draft version.');
            }
            $scenarioId = $data['workforce_scenario_id'] ?? $copyFrom?->workforce_scenario_id;
            if ($scenarioId && WorkforceScenario::query()->whereKey($scenarioId)->value('status') === 'archived') {
                throw new RuntimeException('An archived scenario is not used for a new plan version.');
            }
            $version = WorkforcePlanVersion::query()->create([
                'workforce_plan_id' => $plan->id, 'version' => (int) (clone $versions)->max('version') + 1, 'workforce_scenario_id' => $scenarioId,
                'period_type' => $data['period_type'] ?? $copyFrom?->period_type, 'period_start' => $data['period_start'] ?? $copyFrom?->period_start, 'period_end' => $data['period_end'] ?? $copyFrom?->period_end,
                'currency' => strtoupper((string) ($data['currency'] ?? $copyFrom?->currency ?? Company::query()->whereKey($plan->company_id)->value('currency') ?? 'INR')),
                'notes' => $data['notes'] ?? null, 'supersedes_version_id' => $copyFrom?->id, 'created_by' => $actor->id,
            ]);
            if ($copyFrom !== null) {
                $copyFrom->lines()->get()->each(fn (WorkforcePlanLine $line) => WorkforcePlanLine::query()->create([...collect($line->getAttributes())->except(['id', 'workforce_plan_version_id', 'created_position_id', 'created_at', 'updated_at'])->all(), 'workforce_plan_version_id' => $version->id]));
            }

            return $version;
        });
    }

    /** @param  array<string, mixed>  $data */
    public function updateVersion(WorkforcePlanVersion $version, array $data, User $actor): WorkforcePlanVersion
    {
        $this->authorise($actor, 'workforce.plan');
        $version->update(array_intersect_key($data, array_flip(['workforce_scenario_id', 'period_type', 'period_start', 'period_end', 'currency', 'notes'])));

        return $version;
    }

    /** @param  array<string, mixed>  $data */
    public function addLine(WorkforcePlanVersion $version, array $data, User $actor): WorkforcePlanLine
    {
        $this->authorise($actor, 'workforce.plan');
        if ((filled($data['planned_cost'] ?? null)) && ! $actor->hasPermission('workforce.costs')) {
            throw new RuntimeException('Planned costs are recorded with workforce.costs.');
        }
        $plan = $version->plan()->withoutGlobalScope(AccessScope::class)->firstOrFail();
        $this->assertRecordInScope($actor, $plan);
        if (filled($data['position_id'] ?? null) && (int) Position::query()->withoutGlobalScope(AccessScope::class)->whereKey($data['position_id'])->value('company_id') !== (int) $plan->company_id) {
            throw new RuntimeException('A plan line can only name a position of the plan\'s company.');
        }
        if (filled($data['organisation_node_id'] ?? null) && ($this->dimensions->forNode((int) $data['organisation_node_id'])['company_id'] ?? null) !== (int) $plan->company_id) {
            throw new RuntimeException('A plan line can only name an organisation unit of the plan\'s company.');
        }
        $date = Carbon::parse($data['effective_date'] ?? $version->period_start);
        if ($date->lt($version->period_start) || $date->gt($version->period_end)) {
            throw new RuntimeException('A plan line\'s effective date falls inside the planning period.');
        }

        return WorkforcePlanLine::query()->create([
            ...array_intersect_key($data, array_flip(['movement_type', 'position_id', 'organisation_node_id', 'location_id', 'job_family_id', 'designation_id', 'grade_id', 'employment_type_id', 'cost_centre_id', 'headcount', 'fte', 'planned_cost', 'cost_basis', 'notes'])),
            'workforce_plan_version_id' => $version->id, 'effective_date' => $date->toDateString(),
            'headcount' => (int) ($data['headcount'] ?? 0), 'fte' => (float) ($data['fte'] ?? $data['headcount'] ?? 0),
        ]);
    }

    public function removeLine(WorkforcePlanLine $line, User $actor): void
    {
        $this->authorise($actor, 'workforce.plan');
        $line->delete();
    }

    public function submit(WorkforcePlanVersion $version, User $actor): WorkforcePlanVersion
    {
        $this->authorise($actor, 'workforce.plan');

        return $this->move($version, ['draft'], 'submitted', $actor, function (WorkforcePlanVersion $current) use ($actor) {
            if (! $current->lines()->exists()) {
                throw new RuntimeException('A plan version needs at least one line before it is submitted.');
            }

            return ['submitted_by' => $actor->id, 'submitted_at' => now(), 'reviewed_by' => null, 'reviewed_at' => null];
        }, 'plan_submitted', function (WorkforcePlanVersion $submitted) use ($actor) {
            $key = config('peopleos.workforce.plan_workflow_key');
            $workflow = $key ? Workflow::query()->where('key', $key)->where('status', 'active')->first() : null;
            if ($workflow && $workflow->published()->exists()) {
                $instance = $this->workflows->start($workflow, $submitted, ['workforce_plan' => ['version' => $submitted->version]], $actor);
                $submitted->update(['workflow_instance_id' => $instance->id]);
            }
        });
    }

    public function startReview(WorkforcePlanVersion $version, User $actor): WorkforcePlanVersion
    {
        $this->authoriseAny($actor, ['workforce.review', 'workforce.approve']);

        return $this->move($version, ['submitted'], 'under_review', $actor, fn (WorkforcePlanVersion $current) => $this->assertNotSubmitter($current, $actor, 'review') + ['reviewed_by' => $actor->id, 'reviewed_at' => now()], 'plan_under_review');
    }

    public function approve(WorkforcePlanVersion $version, ?string $note, User $actor): WorkforcePlanVersion
    {
        $this->authorise($actor, 'workforce.approve');

        return $this->move($version, ['under_review'], 'approved', $actor, function (WorkforcePlanVersion $current) use ($actor, $note) {
            if ($current->workflow_instance_id) {
                throw new RuntimeException('This plan version is decided by its approval workflow.');
            }

            return $this->assertNotSubmitter($current, $actor, 'approve') + ['approved_by' => $actor->id, 'approved_at' => now(), 'decision_note' => $note, 'checksum' => $this->checksum($current)];
        }, 'plan_approved');
    }

    public function reject(WorkforcePlanVersion $version, string $note, User $actor): WorkforcePlanVersion
    {
        $this->authoriseAny($actor, ['workforce.review', 'workforce.approve']);
        if (trim($note) === '') {
            throw new RuntimeException('Rejecting a plan version needs a note.');
        }

        return $this->move($version, ['submitted', 'under_review'], 'rejected', $actor, fn (WorkforcePlanVersion $current) => $this->assertNotSubmitter($current, $actor, 'reject') + ['decision_note' => $note], 'plan_rejected');
    }

    /** Send back for changes (the version returns to draft and can be edited and resubmitted). */
    public function returnToDraft(WorkforcePlanVersion $version, string $note, User $actor): WorkforcePlanVersion
    {
        $this->authoriseAny($actor, ['workforce.review', 'workforce.approve']);
        if (trim($note) === '') {
            throw new RuntimeException('Returning a plan version needs a note.');
        }

        return $this->move($version, ['submitted', 'under_review'], 'draft', $actor, fn (WorkforcePlanVersion $current) => $this->assertNotSubmitter($current, $actor, 'return') + ['decision_note' => $note, 'submitted_by' => null, 'submitted_at' => null], 'plan_returned');
    }

    /**
     * Publish an approved version as the plan's active version from a date; the previous active one is
     * superseded. One active version per plan is enforced by a unique key and the plan row lock.
     */
    public function publish(WorkforcePlanVersion $version, CarbonInterface|string|null $effectiveFrom, User $actor): WorkforcePlanVersion
    {
        $this->authorise($actor, 'workforce.approve');
        $from = Carbon::parse($effectiveFrom ?? $version->period_start)->startOfDay();

        try {
            return DB::transaction(function () use ($version, $from, $actor) {
                $plan = WorkforcePlan::query()->withoutGlobalScope(AccessScope::class)->whereKey($version->workforce_plan_id)->lockForUpdate()->firstOrFail();
                $this->assertRecordInScope($actor, $plan);
                $current = WorkforcePlanVersion::query()->whereKey($version->id)->lockForUpdate()->firstOrFail();
                if ($current->status !== 'approved') {
                    throw new RuntimeException('Only an approved plan version is published.');
                }
                $previous = WorkforcePlanVersion::query()->where('workforce_plan_id', $plan->id)->where('status', 'active')->lockForUpdate()->first();
                if ($previous !== null) {
                    $previous->update(['status' => 'superseded', 'active_key' => null]);
                    $this->audit->record(AuditAction::StatusChange, 'workforce', $previous, [['field' => 'status', 'before' => 'active', 'after' => 'superseded']], "Superseded by version {$current->version}", actor: $actor, effectiveDate: $from, metadata: ['event' => 'plan_superseded']);
                    WorkforceEvent::dispatch('workforce.plan.superseded', null, $previous, ['plan' => $plan->code, 'version' => $previous->version]);
                }
                $version->setRawAttributes($current->getAttributes(), true);
                $version->update(['status' => 'active', 'active_key' => 'plan:'.$plan->id, 'effective_from' => $from->toDateString()]);
                $plan->update(['active_version_id' => $version->id]);
                $this->audit->record(AuditAction::StatusChange, 'workforce', $version, [['field' => 'status', 'before' => 'approved', 'after' => 'active']], null, actor: $actor, effectiveDate: $from, metadata: ['event' => 'plan_published']);
                WorkforceEvent::dispatch('workforce.plan.published', null, $version, ['plan' => $plan->code, 'version' => $version->version], array_filter([(int) $plan->owner_user_id]));

                return $version;
            });
        } catch (UniqueConstraintViolationException) {
            throw new RuntimeException('Another version of this plan was published at the same time; reload.');
        }
    }

    public function archive(WorkforcePlanVersion $version, string $reason, User $actor): WorkforcePlanVersion
    {
        $this->authoriseAny($actor, ['workforce.plan', 'workforce.approve']);
        if (trim($reason) === '') {
            throw new RuntimeException('Archiving a plan version needs a reason.');
        }

        return $this->move($version, ['draft', 'approved', 'active', 'superseded', 'rejected'], 'archived', $actor, function (WorkforcePlanVersion $current) {
            if ($current->status === 'active') {
                WorkforcePlan::query()->withoutGlobalScope(AccessScope::class)->whereKey($current->workforce_plan_id)->update(['active_version_id' => null]);
            }

            return ['active_key' => null];
        }, 'plan_archived', reason: $reason);
    }

    /**
     * The explicit, controlled bridge from planning to live capacity: propose a position for a
     * new-position / expansion line of an approved or active plan. The position starts as proposed and
     * still needs a second person's approval; nothing is created silently.
     */
    public function proposePositionFromLine(WorkforcePlanLine $line, string $code, User $actor): Position
    {
        $this->authorise($actor, 'workforce.manage');
        $version = $line->planVersion()->firstOrFail();
        if (! in_array($version->status, ['approved', 'active'], true)) {
            throw new RuntimeException('Positions are proposed only from an approved or active plan.');
        }
        if (! in_array($line->movement_type, ['new_position', 'expansion'], true)) {
            throw new RuntimeException('Only new-position and expansion lines propose positions.');
        }
        if ($line->created_position_id) {
            throw new RuntimeException('A position was already proposed from this line.');
        }
        $plan = $version->plan()->withoutGlobalScope(AccessScope::class)->firstOrFail();

        return DB::transaction(function () use ($line, $code, $actor, $plan, $version) {
            $position = $this->positions->create([
                'code' => $code, 'company_id' => $plan->company_id, 'designation_id' => $line->designation_id, 'job_family_id' => $line->job_family_id,
                'organisation_node_id' => $line->organisation_node_id ?? $plan->organisation_node_id, 'location_id' => $line->location_id ?? $plan->location_id,
                'grade_id' => $line->grade_id, 'employment_type_id' => $line->employment_type_id, 'cost_centre_id' => $line->cost_centre_id,
                'occupancy_mode' => $line->headcount > 1 ? 'multiple' : 'single', 'headcount' => max(1, $line->headcount), 'fte' => $line->headcount > 0 ? round((float) $line->fte / max(1, $line->headcount), 2) : 1,
                'fte_capacity' => (float) $line->fte ?: 1, 'effective_from' => $line->effective_date->toDateString(), 'source_plan_line_id' => $line->id,
                'title' => $line->designation?->name ?? "Planned position ({$plan->code} v{$version->version})", 'reason' => "Proposed from plan {$plan->code} v{$version->version}",
            ], $actor);
            $this->positions->transition($position, 'proposed', null, $actor);
            $line->update(['created_position_id' => $position->id]);

            return $position->refresh();
        });
    }

    /**
     * Totals of a version: net planned headcount / FTE (signed by movement) and, with workforce.costs,
     * planned cost by cost basis — never mixing bases.
     *
     * @return array{headcount: int, fte: float, by_movement: array<string, array{headcount: int, fte: float}>, cost_by_basis: ?array<string, float>}
     */
    public function totals(WorkforcePlanVersion $version, ?User $viewer = null): array
    {
        $rows = WorkforcePlanLine::query()->where('workforce_plan_version_id', $version->id)
            ->selectRaw('movement_type, cost_basis, sum(headcount) as headcount, sum(fte) as fte, sum(planned_cost) as cost')->groupBy('movement_type', 'cost_basis')->get();
        $sign = fn (string $type) => (int) config("peopleos.workforce.movement_types.{$type}.sign", 0);

        return [
            'headcount' => (int) $rows->sum(fn ($r) => $sign($r->movement_type) * (int) $r->headcount),
            'fte' => round((float) $rows->sum(fn ($r) => $sign($r->movement_type) * (float) $r->fte), 2),
            'by_movement' => $rows->groupBy('movement_type')->map(fn ($g) => ['headcount' => (int) $g->sum('headcount'), 'fte' => round((float) $g->sum('fte'), 2)])->all(),
            'cost_by_basis' => $viewer?->hasPermission('workforce.costs') ? $rows->whereNotNull('cost_basis')->groupBy('cost_basis')
                ->map(fn ($g) => round((float) $g->sum(fn ($r) => $sign($r->movement_type) * (float) $r->cost), 2))->all() : null,
        ];
    }

    /**
     * @param  list<string>  $from
     * @param  callable(WorkforcePlanVersion): array<string, mixed>  $changes
     */
    private function move(WorkforcePlanVersion $version, array $from, string $to, User $actor, callable $changes, string $event, ?callable $after = null, ?string $reason = null): WorkforcePlanVersion
    {
        return DB::transaction(function () use ($version, $from, $to, $actor, $changes, $event, $after, $reason) {
            $current = WorkforcePlanVersion::query()->whereKey($version->id)->lockForUpdate()->firstOrFail();
            if ((int) $current->lock_version !== (int) $version->lock_version) {
                throw new RuntimeException('The plan version was changed meanwhile. Reload and try again.');
            }
            if (! in_array($current->status, $from, true)) {
                throw new RuntimeException("A plan version that is {$current->status} cannot become {$to}.");
            }
            $this->assertRecordInScope($actor, WorkforcePlan::query()->withoutGlobalScope(AccessScope::class)->findOrFail($current->workforce_plan_id));
            $before = $current->status;
            $extra = $changes($current);
            $version->setRawAttributes($current->getAttributes(), true);
            $version->withAuditReason($reason)->update(['status' => $to, ...$extra]);
            $action = match ($to) {
                'approved' => AuditAction::Approved,
                'rejected' => AuditAction::Rejected,
                'submitted' => AuditAction::Submitted,
                default => AuditAction::StatusChange,
            };
            $this->audit->record($action, 'workforce', $version, [['field' => 'status', 'before' => $before, 'after' => $to]], $reason ?? ($extra['decision_note'] ?? null), actor: $actor, metadata: ['event' => $event]);
            $plan = $version->plan()->withoutGlobalScope(AccessScope::class)->first();
            $recipients = array_values(array_filter(array_unique([(int) $plan?->owner_user_id, (int) $version->submitted_by])));
            WorkforceEvent::dispatch('workforce.'.preg_replace('/^plan_/', 'plan.', $event), null, $version, ['plan' => $plan?->code, 'version' => $version->version, 'status' => $to], $recipients);
            if ($after) {
                $after($version);
            }

            return $version;
        });
    }

    /** Separation of duties: whoever submitted a version never reviews, approves, rejects or returns it. */
    private function assertNotSubmitter(WorkforcePlanVersion $version, User $actor, string $verb): array
    {
        if ($version->submitted_by !== null && (int) $version->submitted_by === (int) $actor->id) {
            throw new RuntimeException("The person who submitted a plan version cannot {$verb} it.");
        }

        return [];
    }

    private function checksum(WorkforcePlanVersion $version): string
    {
        $lines = WorkforcePlanLine::query()->where('workforce_plan_version_id', $version->id)->orderBy('id')->get()
            ->map(fn ($l) => collect($l->getAttributes())->except(['id', 'created_at', 'updated_at', 'created_position_id'])->all())->all();

        return hash('sha256', (string) json_encode([collect($version->getAttributes())->only(WorkforcePlanVersion::CONTENT)->all(), $lines]));
    }

    /** @param  array<string, mixed>  $data */
    private function scope(array $data): array
    {
        $node = $this->dimensions->forNode(filled($data['organisation_node_id'] ?? null) ? (int) $data['organisation_node_id'] : null);
        $companyId = $data['company_id'] ?? $node['company_id'] ?? null;
        if (! $companyId) {
            throw new RuntimeException('A workforce plan belongs to a company (choose a company or an organisation unit).');
        }
        if ($node['company_id'] && (int) $node['company_id'] !== (int) $companyId) {
            throw new RuntimeException('The organisation unit belongs to another company.');
        }
        $location = filled($data['location_id'] ?? null) ? (int) $data['location_id'] : $node['location_id'];
        $establishment = $this->dimensions->forLocation($location, filled($data['establishment_id'] ?? null) ? (int) $data['establishment_id'] : null);

        return [
            'company_id' => (int) $companyId, 'organisation_node_id' => $data['organisation_node_id'] ?? null, 'location_id' => $location,
            'establishment_id' => $establishment['establishment_id'], 'legal_entity_id' => $data['legal_entity_id'] ?? $establishment['legal_entity_id'],
            ...array_intersect_key($node, array_flip(OrganisationDimensions::UNIT_COLUMNS)),
        ];
    }

    private function authorise(User $actor, string $permission): void
    {
        if (! $actor->hasPermission($permission)) {
            throw new RuntimeException("This needs {$permission}.");
        }
    }

    /** @param  list<string>  $permissions */
    private function authoriseAny(User $actor, array $permissions): void
    {
        foreach ($permissions as $permission) {
            if ($actor->hasPermission($permission)) {
                return;
            }
        }
        throw new RuntimeException('This needs '.implode(' or ', $permissions).'.');
    }
}
