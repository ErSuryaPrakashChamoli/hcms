<?php

namespace App\Domain\ServiceDesk\Services;

use App\Domain\Audit\Enums\AuditAction;
use App\Domain\Audit\Services\AuditRecorder;
use App\Domain\Employment\Models\Employee;
use App\Domain\Identity\Models\User;
use App\Domain\Identity\Scopes\AccessScope;
use App\Domain\Identity\Services\AccessScopes;
use App\Domain\Performance\Services\PerformanceRelationships;
use App\Domain\ServiceDesk\Models\ServiceDefinitionVersion;
use App\Domain\ServiceDesk\Models\Ticket;
use App\Domain\ServiceDesk\Models\TicketAccessGrant;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * Phase 12: who may see and work an HR request or case. The one access model behind the policy,
 * Filament queries, search, the API, comments, attachments and field security. The chain is
 * Auth → Tenant → Role → Permission → Organisation scope → Relationship scope → Field security →
 * Record.
 *
 * **Who sees what:**
 * - **Requester / employee:** their own requests that are visible to the employee. Drafts are visible
 *   only to whoever raised them.
 * - **Agents** (servicedesk.view to read, servicedesk.agent to work):
 *   - standard and sensitive cases of employees in their organisation scope (the ticket's
 *     ScopedByEmployee global scope applies first);
 *   - never their own case as its subject.
 * - **Restricted (confidential / employee-relations) cases:** only holders of servicedesk.confidential
 *   who are the assignee, the case owner, or explicitly granted. Never a generic "HR can see
 *   everything".
 * - **Managers** (servicedesk.team): standard cases of a manager-visible service, for the people they
 *   manage through the configured reporting relationships (PerformanceRelationships; never mentors,
 *   buddies or project leads). Status only: no comments, no form data.
 *
 * Every read of a sensitive or restricted case is audited (CONFIDENTIAL_CASE_VIEWED).
 */
final class CaseAccess
{
    /** @var array<int, ?int> */
    private array $employeeIds = [];

    /** @var array<int, Collection<int, int>> */
    private array $teams = [];

    public function __construct(
        private readonly AccessScopes $scopes,
        private readonly PerformanceRelationships $relationships,
        private readonly AuditRecorder $audit,
    ) {}

    // -- Query level -----------------------------------------------------------------------------

    /** Restrict a Ticket query to what the user may see (in SQL, never filtered in PHP). */
    public function visible(Builder $query, User $user): Builder
    {
        $own = $this->employeeId($user);
        $agent = $this->isAgent($user);
        $confidential = $user->hasPermission('servicedesk.confidential');
        $team = $this->teamIds($user);

        return $query->where(function (Builder $q) use ($user, $own, $agent, $confidential, $team) {
            $q->where(fn (Builder $r) => $r->where('tickets.raised_by', $user->id)->where('tickets.confidentiality', '!=', 'restricted'));
            if ($own !== null) {
                $q->orWhere(fn (Builder $r) => $r->where('tickets.employee_id', $own)->where('tickets.visible_to_employee', true)->where('tickets.status', '!=', 'draft'));
            }
            if ($agent) {
                $q->orWhere(fn (Builder $r) => $r->whereIn('tickets.confidentiality', ['standard', 'sensitive'])->where('tickets.status', '!=', 'draft')
                    ->where(fn (Builder $e) => $own === null ? $e : $e->where('tickets.employee_id', '!=', $own)));
            }
            $q->orWhere(fn (Builder $r) => $r->where('tickets.assignee_id', $user->id)->where('tickets.confidentiality', '!=', 'restricted'));
            if ($confidential) {
                $q->orWhere(fn (Builder $r) => $r->where('tickets.confidentiality', 'restricted')->where(fn (Builder $x) => $x->where('tickets.assignee_id', $user->id)->orWhere('tickets.owner_id', $user->id)
                    ->orWhereIn('tickets.id', TicketAccessGrant::query()->select('ticket_id')->where('user_id', $user->id)->whereNull('revoked_at'))));
            }
            if ($team->isNotEmpty()) {
                $q->orWhere(fn (Builder $r) => $r->whereIn('tickets.employee_id', $team->all())->where('tickets.confidentiality', 'standard')->where('tickets.status', '!=', 'draft')
                    ->whereIn('tickets.service_definition_version_id', ServiceDefinitionVersion::query()->select('id')->where('manager_visible', true)));
            }
        });
    }

    /** Only the manager-team rows (for the Team requests page). */
    public function team(Builder $query, User $user): Builder
    {
        $team = $this->teamIds($user);

        return $query->whereIn('tickets.employee_id', $team->isEmpty() ? [0] : $team->all())->where('tickets.confidentiality', 'standard')->where('tickets.status', '!=', 'draft')
            ->whereIn('tickets.service_definition_version_id', ServiceDefinitionVersion::query()->select('id')->where('manager_visible', true));
    }

    // -- Record level ----------------------------------------------------------------------------

    public function canView(User $user, Ticket $ticket): bool
    {
        if (! $this->inScope($user, $ticket) && ! $this->isOwn($user, $ticket) && ! $this->managesSubject($user, $ticket)) {
            return false;
        }
        if ($ticket->isRestricted()) {
            return $this->hasExplicitAccess($user, $ticket) || ($this->isOwn($user, $ticket) && $ticket->visible_to_employee && $ticket->status !== 'draft');
        }
        if ((int) $ticket->raised_by === (int) $user->id || ((int) $ticket->assignee_id === (int) $user->id)) {
            return true;
        }
        if ($this->isOwn($user, $ticket)) {
            return $ticket->visible_to_employee && $ticket->status !== 'draft';
        }
        if ($ticket->status === 'draft') {
            return false;
        }
        if ($this->isAgent($user)) {
            return true;
        }

        return $this->isTeamVisible($user, $ticket);
    }

    /** May act on the case as HR (respond, assign, move, resolve). Never on one's own case as its subject. */
    public function canWork(User $user, Ticket $ticket): bool
    {
        if (! $user->isActive() && ! $user->is_platform_admin) {
            return false;
        }
        if ($this->isOwn($user, $ticket) || $ticket->status === 'draft') {
            return false;
        }
        if ($ticket->isRestricted()) {
            return $this->hasExplicitAccess($user, $ticket);
        }

        return $user->hasPermission('servicedesk.agent') && $this->inScope($user, $ticket);
    }

    /** Eligible to be assigned the case: an active agent in scope (confidential permission for restricted). */
    public function eligibleAgent(User $candidate, Ticket $ticket): bool
    {
        return $candidate->isActive() && $candidate->hasPermission('servicedesk.agent') && ! $this->isOwn($candidate, $ticket) && $this->inScope($candidate, $ticket)
            && (! $ticket->isRestricted() || $candidate->hasPermission('servicedesk.confidential'));
    }

    public function isOwn(User $user, Ticket $ticket): bool
    {
        $own = $this->employeeId($user);

        return $own !== null && (int) $ticket->employee_id === $own;
    }

    public function hasExplicitAccess(User $user, Ticket $ticket): bool
    {
        if (! $user->hasPermission('servicedesk.confidential') || $this->isOwn($user, $ticket)) {
            return false;
        }

        return (int) $ticket->assignee_id === (int) $user->id || (int) $ticket->owner_id === (int) $user->id
            || TicketAccessGrant::query()->where('ticket_id', $ticket->id)->where('user_id', $user->id)->whereNull('revoked_at')->exists();
    }

    /** @return list<string> the comment visibilities the user may read */
    public function commentVisibilities(User $user, Ticket $ticket): array
    {
        if ($ticket->isRestricted() && $this->hasExplicitAccess($user, $ticket)) {
            return ['employee', 'internal', 'restricted'];
        }
        if ($this->canWork($user, $ticket) || ($this->isAgent($user) && ! $this->isOwn($user, $ticket) && ! $ticket->isRestricted() && $this->inScope($user, $ticket))) {
            return ['employee', 'internal'];
        }
        if ($this->isOwn($user, $ticket) || (int) $ticket->raised_by === (int) $user->id) {
            return ['employee'];
        }

        return [];
    }

    /** @return list<string> visibilities the user may write */
    public function commentWritable(User $user, Ticket $ticket): array
    {
        if ($ticket->isRestricted() && $this->hasExplicitAccess($user, $ticket)) {
            return ['employee', 'internal', 'restricted'];
        }
        if ($this->canWork($user, $ticket)) {
            return ['employee', 'internal'];
        }

        return $this->isOwn($user, $ticket) || (int) $ticket->raised_by === (int) $user->id ? ['employee'] : [];
    }

    /**
     * The form data the user may see, field by field. Classification comes from the pinned service
     * version and the domain action (ServiceForms):
     * - **standard:** everyone who sees the form;
     * - **sensitive:** the requester (masked), and agents holding the domain action's view permission;
     * - **restricted:** explicit case access only.
     *
     * Fields marked not employee-visible are never shown to the employee. Managers see no form data.
     *
     * @return array<string, array{label: string, value: mixed, masked: bool, class: string}>
     */
    public function formDataFor(User $user, Ticket $ticket): array
    {
        $fields = app(ServiceForms::class)->fieldsFor($ticket);
        $data = (array) ($ticket->form_data ?? []);
        $own = $this->isOwn($user, $ticket) || (int) $ticket->raised_by === (int) $user->id;
        $worker = $this->canWork($user, $ticket) || ($this->isAgent($user) && ! $this->isOwn($user, $ticket) && $this->inScope($user, $ticket));
        if (! $own && ! $worker && ! $this->hasExplicitAccess($user, $ticket)) {
            return [];
        }
        $out = [];
        foreach ($fields as $key => $field) {
            if (! array_key_exists($key, $data)) {
                continue;
            }
            $class = $field['class'];
            $visible = match ($class) {
                'restricted' => $this->hasExplicitAccess($user, $ticket),
                'sensitive' => $worker && $user->hasPermission($field['view_permission'] ?? 'employee.sensitive.view'),
                default => true,
            };
            if ($own && ! $worker && ! ($field['employee_visible'] ?? true)) {
                continue;
            }
            if ($own && ! $worker && $class === 'restricted') {
                continue;
            }
            $out[$key] = ['label' => $field['label'], 'value' => $visible ? $data[$key] : self::mask($data[$key]), 'masked' => ! $visible, 'class' => $class];
        }

        return $out;
    }

    /** Standard, employee-visible fields only — what an integration (API) may read. */
    public function apiFormData(Ticket $ticket): array
    {
        if ($ticket->confidentiality !== 'standard') {
            return [];
        }
        $data = (array) ($ticket->form_data ?? []);

        return collect(app(ServiceForms::class)->fieldsFor($ticket))
            ->filter(fn (array $f, string $key) => $f['class'] === 'standard' && ($f['employee_visible'] ?? true) && array_key_exists($key, $data))
            ->map(fn (array $f, string $key) => $data[$key])->all();
    }

    /** Audit the read of a sensitive or restricted case (CONFIDENTIAL_CASE_VIEWED). */
    public function recordSensitiveRead(User $user, Ticket $ticket, string $purpose = 'view'): void
    {
        if ($ticket->confidentiality === 'standard') {
            return;
        }
        $this->audit->record(AuditAction::ConfidentialCaseViewed, 'servicedesk', $ticket, [], null, actor: $user, metadata: ['confidentiality' => $ticket->confidentiality, 'purpose' => $purpose, 'sensitive' => true]);
    }

    public static function mask(mixed $value): string
    {
        $value = is_scalar($value) ? (string) $value : '';

        return mb_strlen($value) > 6 ? '••••'.mb_substr($value, -4) : '••••';
    }

    public function isAgent(User $user): bool
    {
        return $user->hasPermission('servicedesk.view') || $user->hasPermission('servicedesk.agent');
    }

    public function employeeId(User $user): ?int
    {
        if (! array_key_exists($user->id, $this->employeeIds)) {
            $id = Employee::query()->withoutGlobalScope(AccessScope::class)->where('user_id', $user->id)->value('id');
            $this->employeeIds[$user->id] = $id === null ? null : (int) $id;
        }

        return $this->employeeIds[$user->id];
    }

    /** @return Collection<int, int> */
    public function teamIds(User $user): Collection
    {
        if (! $user->hasPermission('servicedesk.team')) {
            return collect();
        }

        return $this->teams[$user->id] ??= $this->relationships->reportIds($this->relationships->forUser($user))->map(fn ($id) => (int) $id)->values();
    }

    private function managesSubject(User $user, Ticket $ticket): bool
    {
        return $this->teamIds($user)->contains((int) $ticket->employee_id);
    }

    private function isTeamVisible(User $user, Ticket $ticket): bool
    {
        return $ticket->confidentiality === 'standard' && $this->managesSubject($user, $ticket)
            && $ticket->service_definition_version_id !== null
            && ServiceDefinitionVersion::query()->whereKey($ticket->service_definition_version_id)->where('manager_visible', true)->exists();
    }

    private function inScope(User $user, Ticket $ticket): bool
    {
        $employee = Employee::query()->withoutGlobalScope(AccessScope::class)->find($ticket->employee_id);

        return $employee !== null && $this->scopes->allows($user, $employee);
    }
}
