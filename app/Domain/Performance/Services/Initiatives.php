<?php

namespace App\Domain\Performance\Services;

use App\Domain\Employment\Models\Employee;
use App\Domain\Employment\Models\ReportingRelationship;
use App\Domain\Identity\Models\User;
use App\Domain\Identity\Scopes\AccessScope;
use App\Domain\Identity\Services\AccessScopes;
use App\Domain\Performance\Events\PerformanceEvent;
use App\Domain\Performance\Models\Goal;
use App\Domain\Performance\Models\Initiative;
use App\Domain\Performance\Models\InitiativeFeedback;
use App\Domain\Performance\Models\PerformanceLookup;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * HROne setup parity HP-8j (Performance → Initiative): initiatives an employee takes on — of an initiative type from the
 * performance masters, optionally for one of their goals — and the feedback asked about them.
 *
 * Who: the employee themself (performance.initiatives); their performance managers (performance.initiatives and a
 * configured performance-manager relationship, PerformanceRelationships), who also assign initiatives to them; HR with
 * performance.initiatives_manage for everyone in their access scope (performance.view sees them all, read-only). A
 * colleague asked for feedback sees the initiative and answers once (or ignores the request). The employee is told when
 * someone assigns them an initiative, their managers when they create one; the colleague when feedback is asked, the
 * employee when it is given. Nothing is deleted: an initiative is completed or cancelled, feedback is kept. Every change
 * is audited by the models (Auditable; feedback text masked).
 */
final class Initiatives
{
    public function __construct(private readonly PerformanceRelationships $relationships, private readonly AccessScopes $scopes) {}

    // --- Access -------------------------------------------------------------------------------

    public function isSelf(User $user, int|string|null $employeeId): bool
    {
        $me = $this->relationships->forUser($user);

        return $me !== null && $employeeId !== null && (int) $me->id === (int) $employeeId && $user->hasPermission('performance.initiatives');
    }

    /** A performance manager of the employee (configured relationship types) holding performance.initiatives. */
    public function manages(User $user, int|string|null $employeeId): bool
    {
        return $user->hasPermission('performance.initiatives') && $this->relationships->manages($this->relationships->forUser($user), $employeeId);
    }

    /** HR: performance.initiatives_manage, within the access scope (never fails open on another scope). */
    public function administers(User $user, int|string|null $employeeId): bool
    {
        return $employeeId !== null && $user->hasPermission('performance.initiatives_manage') && $this->scopes->allowsEmployeeId($user, (int) $employeeId);
    }

    public function mayAssignTo(User $user, Employee $employee): bool
    {
        return $this->isSelf($user, $employee->id) || $this->manages($user, $employee->id) || $this->administers($user, $employee->id);
    }

    public function mayView(User $user, Initiative $initiative): bool
    {
        if ($this->isSelf($user, $initiative->employee_id) || $this->manages($user, $initiative->employee_id) || $this->administers($user, $initiative->employee_id)) {
            return true;
        }
        if ($user->hasPermission('performance.view') && $this->scopes->allowsEmployeeId($user, (int) $initiative->employee_id)) {
            return true;
        }

        return $this->isReviewer($user, $initiative);
    }

    /** Change, start, complete, cancel and ask feedback: the employee, their managers or HR, while the initiative is open. */
    public function mayEdit(User $user, Initiative $initiative): bool
    {
        return $initiative->isOpen() && $this->mayAssignTo($user, $initiative->employee()->withoutGlobalScope(AccessScope::class)->firstOrFail());
    }

    /** Give feedback without a request: the employee's managers, the person who assigned it, or HR — never the employee. */
    public function mayGiveFeedback(User $user, Initiative $initiative): bool
    {
        $me = $this->relationships->forUser($user);
        if ($me === null || (int) $me->id === (int) $initiative->employee_id || $initiative->status === 'cancelled') {
            return false;
        }

        return $this->manages($user, $initiative->employee_id) || $this->administers($user, $initiative->employee_id)
            || ($initiative->owner_id !== null && (int) $initiative->owner_id === (int) $me->id && $user->hasPermission('performance.initiatives'));
    }

    private function isReviewer(User $user, Initiative $initiative): bool
    {
        $me = $this->relationships->forUser($user);

        return $me !== null && InitiativeFeedback::query()->where('initiative_id', $initiative->id)->where('reviewer_id', $me->id)->exists();
    }

    /** The initiatives a user may list: HR all in scope; others their own, their reports' and those they were asked about. */
    public function visibleTo(User $user): Builder
    {
        $query = Initiative::query();
        if ($user->hasPermission('performance.view') || $user->hasPermission('performance.initiatives_manage')) {
            return $query;
        }
        $me = $this->relationships->forUser($user);
        if ($me === null) {
            return $query->whereRaw('1 = 0');
        }
        $employees = $user->hasPermission('performance.initiatives') ? [$me->id, ...$this->relationships->reportIds($me)->all()] : [];
        $asked = InitiativeFeedback::query()->where('reviewer_id', $me->id)->pluck('initiative_id')->all();

        return $query->where(fn (Builder $q) => $q->whereIn('employee_id', $employees)->orWhereIn('id', $asked));
    }

    /**
     * Feedback on an initiative the user may read: everything for the employee, their managers and HR; a colleague who was
     * asked sees only their own.
     *
     * @return Collection<int, InitiativeFeedback>
     */
    public function feedbackVisibleTo(User $user, Initiative $initiative): Collection
    {
        $all = $initiative->feedback()->with('reviewer.person')->get();
        if ($this->isSelf($user, $initiative->employee_id) || $this->manages($user, $initiative->employee_id) || $this->administers($user, $initiative->employee_id)
            || ($user->hasPermission('performance.view') && $this->scopes->allowsEmployeeId($user, (int) $initiative->employee_id))) {
            return $all;
        }
        $me = $this->relationships->forUser($user);

        return $me === null ? collect() : $all->where('reviewer_id', $me->id)->values();
    }

    // --- Initiatives --------------------------------------------------------------------------

    /**
     * @param  array{title: string, description?: ?string, initiative_type_id?: int|string|null, goal_id?: int|string|null, start_date?: ?string, due_date?: ?string}  $data
     */
    public function create(Employee $employee, array $data, User $actor): Initiative
    {
        if (! $this->mayAssignTo($actor, $employee)) {
            throw new RuntimeException('You cannot create an initiative for this employee.');
        }
        if (! Employee::query()->withoutGlobalScope(AccessScope::class)->employed()->whereKey($employee->id)->exists()) {
            throw new RuntimeException('Initiatives are for employees working here now.');
        }
        $values = $this->validated($employee, $data);
        $me = $this->relationships->forUser($actor);
        $self = $me !== null && (int) $me->id === (int) $employee->id;

        return DB::transaction(function () use ($employee, $values, $actor, $me, $self) {
            $initiative = Initiative::query()->create($values + [
                'employee_id' => $employee->id, 'owner_id' => $self ? null : $me?->id, 'status' => 'planned', 'progress' => 0, 'created_by' => $actor->id,
            ]);
            $context = ['title' => $initiative->title, 'by' => $actor->name, 'for' => $employee->person?->full_name];
            $self
                ? PerformanceEvent::dispatch('performance.initiative.created', $employee, $initiative, $context, $this->managerIds($employee))
                : PerformanceEvent::dispatch('performance.initiative.assigned', $employee, $initiative, $context, [$employee->id]);

            return $initiative;
        });
    }

    /** @param  array<string, mixed>  $data  title, description, initiative_type_id, goal_id, start_date, due_date, progress */
    public function update(Initiative $initiative, array $data, User $actor): Initiative
    {
        if (! $this->mayEdit($actor, $initiative)) {
            throw new RuntimeException('You cannot change this initiative.');
        }
        $employee = $initiative->employee()->withoutGlobalScope(AccessScope::class)->firstOrFail();
        $values = $this->validated($employee, $data + $initiative->only(['title', 'description', 'initiative_type_id', 'goal_id']) + [
            'start_date' => $initiative->start_date?->toDateString(), 'due_date' => $initiative->due_date?->toDateString(),
        ], $initiative);
        if (array_key_exists('progress', $data) && $data['progress'] !== null && $data['progress'] !== '') {
            $values['progress'] = $this->progress($data['progress']);
            // Progress means work has started.
            if ($values['progress'] > 0 && $initiative->status === 'planned') {
                $values['status'] = 'in_progress';
            }
        }

        return DB::transaction(function () use ($initiative, $values) {
            $this->lock($initiative);
            $initiative->update($values);

            return $initiative->refresh();
        });
    }

    public function start(Initiative $initiative, User $actor): Initiative
    {
        if (! $this->mayEdit($actor, $initiative) || $initiative->status !== 'planned') {
            throw new RuntimeException('Only a planned initiative is started, by the employee, their manager or HR.');
        }

        return DB::transaction(function () use ($initiative) {
            $this->lock($initiative);
            $initiative->update(['status' => 'in_progress']);

            return $initiative->refresh();
        });
    }

    public function complete(Initiative $initiative, ?string $outcome, User $actor): Initiative
    {
        if (! $this->mayEdit($actor, $initiative)) {
            throw new RuntimeException('You cannot complete this initiative.');
        }

        return DB::transaction(function () use ($initiative, $outcome, $actor) {
            $this->lock($initiative);
            $initiative->update(['status' => 'completed', 'progress' => 100, 'completed_at' => now(), 'outcome' => filled($outcome) ? trim((string) $outcome) : null]);
            $employee = $initiative->employee()->withoutGlobalScope(AccessScope::class)->firstOrFail();
            $me = $this->relationships->forUser($actor);
            $recipients = $me !== null && (int) $me->id === (int) $employee->id
                ? ($initiative->owner_id ? [(int) $initiative->owner_id] : $this->managerIds($employee))
                : [(int) $employee->id];
            PerformanceEvent::dispatch('performance.initiative.completed', $employee, $initiative, ['title' => $initiative->title, 'for' => $employee->person?->full_name], $recipients);

            return $initiative->refresh();
        });
    }

    public function cancel(Initiative $initiative, string $reason, User $actor): Initiative
    {
        if (! $this->mayEdit($actor, $initiative)) {
            throw new RuntimeException('You cannot cancel this initiative.');
        }
        if (trim($reason) === '') {
            throw new RuntimeException('Give a reason for cancelling the initiative.');
        }

        return DB::transaction(function () use ($initiative, $reason) {
            $this->lock($initiative);
            $initiative->update(['status' => 'cancelled', 'cancel_reason' => trim($reason)]);

            return $initiative->refresh();
        });
    }

    // --- Feedback -----------------------------------------------------------------------------

    /** Ask a colleague for feedback on an initiative (once while a request to them is open). */
    public function requestFeedback(Initiative $initiative, Employee $from, User $actor): InitiativeFeedback
    {
        if (! $this->mayAssignTo($actor, $initiative->employee()->withoutGlobalScope(AccessScope::class)->firstOrFail()) || $initiative->status === 'cancelled') {
            throw new RuntimeException('You cannot ask for feedback on this initiative.');
        }
        if ((int) $from->id === (int) $initiative->employee_id) {
            throw new RuntimeException('Ask someone other than the employee for feedback.');
        }
        if (! Employee::query()->withoutGlobalScope(AccessScope::class)->employed()->whereKey($from->id)->exists()) {
            throw new RuntimeException('Feedback is asked of someone working here now.');
        }

        return DB::transaction(function () use ($initiative, $from, $actor) {
            $this->lock($initiative);
            if (InitiativeFeedback::query()->where('initiative_id', $initiative->id)->where('reviewer_id', $from->id)->where('status', 'requested')->exists()) {
                throw new RuntimeException(($from->person?->full_name ?? 'This colleague').' was already asked and has not answered yet.');
            }
            $feedback = InitiativeFeedback::query()->create([
                'initiative_id' => $initiative->id, 'reviewer_id' => $from->id, 'requested_by' => $actor->id, 'status' => 'requested', 'requested_at' => now(),
            ]);
            $employee = $initiative->employee()->withoutGlobalScope(AccessScope::class)->first();
            PerformanceEvent::dispatch('performance.initiative.feedback_requested', $employee, $feedback, ['title' => $initiative->title, 'about' => $employee?->person?->full_name], [(int) $from->id]);

            return $feedback;
        });
    }

    /** The colleague answers the request (once). */
    public function respond(InitiativeFeedback $feedback, string $text, User $actor): InitiativeFeedback
    {
        return $this->answer($feedback, $actor, 'given', $text);
    }

    /** The colleague declines the request. */
    public function ignore(InitiativeFeedback $feedback, User $actor): InitiativeFeedback
    {
        return $this->answer($feedback, $actor, 'ignored', null);
    }

    private function answer(InitiativeFeedback $feedback, User $actor, string $status, ?string $text): InitiativeFeedback
    {
        $me = $this->relationships->forUser($actor);
        if ($me === null || (int) $me->id !== (int) $feedback->reviewer_id) {
            throw new RuntimeException('Only the colleague who was asked answers this request.');
        }
        if ($status === 'given' && trim((string) $text) === '') {
            throw new RuntimeException('Write your feedback.');
        }

        return DB::transaction(function () use ($feedback, $status, $text) {
            $current = InitiativeFeedback::query()->whereKey($feedback->id)->lockForUpdate()->firstOrFail();
            if ($current->status !== 'requested') {
                throw new RuntimeException('This request was already answered.');
            }
            $initiative = Initiative::query()->withoutGlobalScope(AccessScope::class)->findOrFail($current->initiative_id);
            if ($initiative->status === 'cancelled') {
                throw new RuntimeException('The initiative was cancelled.');
            }
            $feedback->setRawAttributes($current->getAttributes(), true);
            $feedback->update(['status' => $status, 'feedback' => $status === 'given' ? trim((string) $text) : null, 'responded_at' => now()]);
            if ($status === 'given') {
                $this->announceFeedback($initiative, $feedback);
            }

            return $feedback->refresh();
        });
    }

    /** A manager, the person who assigned the initiative or HR gives feedback without a request. */
    public function giveFeedback(Initiative $initiative, string $text, User $actor): InitiativeFeedback
    {
        if (! $this->mayGiveFeedback($actor, $initiative)) {
            throw new RuntimeException('You cannot give feedback on this initiative.');
        }
        if (trim($text) === '') {
            throw new RuntimeException('Write your feedback.');
        }
        $me = $this->relationships->forUser($actor);

        return DB::transaction(function () use ($initiative, $text, $me) {
            $this->lock($initiative);
            $feedback = InitiativeFeedback::query()->create([
                'initiative_id' => $initiative->id, 'reviewer_id' => $me->id, 'requested_by' => null, 'status' => 'given', 'feedback' => trim($text), 'responded_at' => now(),
            ]);
            $this->announceFeedback($initiative, $feedback);

            return $feedback;
        });
    }

    private function announceFeedback(Initiative $initiative, InitiativeFeedback $feedback): void
    {
        $employee = $initiative->employee()->withoutGlobalScope(AccessScope::class)->first();
        $reviewer = $feedback->reviewer()->withoutGlobalScope(AccessScope::class)->with('person')->first();
        $requester = $feedback->requested_by ? Employee::query()->withoutGlobalScope(AccessScope::class)->where('user_id', $feedback->requested_by)->value('id') : null;
        $recipients = collect([(int) $initiative->employee_id, $requester === null ? null : (int) $requester])->filter()->reject(fn (int $id) => $id === (int) $feedback->reviewer_id)->unique()->values()->all();
        // The feedback text stays on the record: notifications carry only who gave it and on what.
        PerformanceEvent::dispatch('performance.initiative.feedback_given', $employee, $feedback, ['title' => $initiative->title, 'from' => $reviewer?->person?->full_name], $recipients);
    }

    /** @return Collection<int, InitiativeFeedback> requests waiting for the employee's answer (My tasks) */
    public function pendingFeedbackFor(Employee $employee): Collection
    {
        return InitiativeFeedback::query()->withoutGlobalScope(AccessScope::class)->with(['initiative' => fn ($q) => $q->withoutGlobalScope(AccessScope::class)->with('employee.person')])
            ->where('reviewer_id', $employee->id)->where('status', 'requested')
            ->whereHas('initiative', fn ($q) => $q->withoutGlobalScope(AccessScope::class)->where('status', '!=', 'cancelled'))
            ->orderBy('requested_at')->get();
    }

    // --- Helpers ------------------------------------------------------------------------------

    /** @return array<string, mixed> */
    private function validated(Employee $employee, array $data, ?Initiative $initiative = null): array
    {
        $title = trim((string) ($data['title'] ?? ''));
        if ($title === '' || mb_strlen($title) > 200) {
            throw new RuntimeException('An initiative needs a title of up to 200 characters.');
        }
        $typeId = filled($data['initiative_type_id'] ?? null) ? (int) $data['initiative_type_id'] : null;
        if ($typeId !== null) {
            $type = PerformanceLookup::query()->ofKind('initiative_type')->find($typeId);
            if ($type === null || ($type->status !== 'active' && (int) $initiative?->initiative_type_id !== $typeId)) {
                throw new RuntimeException('Choose an active initiative type.');
            }
        } elseif (PerformanceLookup::query()->ofKind('initiative_type')->where('status', 'active')->exists()) {
            throw new RuntimeException('Choose the initiative type.');
        }
        $goalId = filled($data['goal_id'] ?? null) ? (int) $data['goal_id'] : null;
        if ($goalId !== null && (int) $initiative?->goal_id !== $goalId) {
            $goal = Goal::query()->withoutGlobalScope(AccessScope::class)->find($goalId);
            if ($goal === null || (int) $goal->employee_id !== (int) $employee->id || $goal->status === 'cancelled') {
                throw new RuntimeException('Link one of the employee\'s own goals.');
            }
        }
        $start = filled($data['start_date'] ?? null) ? (string) $data['start_date'] : null;
        $due = filled($data['due_date'] ?? null) ? (string) $data['due_date'] : null;
        if ($start !== null && $due !== null && $due < $start) {
            throw new RuntimeException('An initiative cannot be due before it starts.');
        }
        $description = trim((string) ($data['description'] ?? ''));

        return ['title' => $title, 'description' => $description === '' ? null : $description, 'initiative_type_id' => $typeId, 'goal_id' => $goalId, 'start_date' => $start, 'due_date' => $due];
    }

    private function progress(mixed $value): int
    {
        if (! is_numeric($value) || (int) $value < 0 || (int) $value > 100) {
            throw new RuntimeException('Progress is between 0 and 100.');
        }

        return (int) $value;
    }

    /** Lock the initiative's row and refuse a change to one that was completed or cancelled meanwhile. */
    private function lock(Initiative $initiative): void
    {
        $current = Initiative::query()->withoutGlobalScope(AccessScope::class)->whereKey($initiative->id)->lockForUpdate()->firstOrFail();
        if (! $current->isOpen() && $current->status !== $initiative->status) {
            throw new RuntimeException('The initiative was '.$current->status.' meanwhile. Reload and try again.');
        }
        $initiative->setRawAttributes($current->getAttributes(), true);
    }

    /** @return list<int> the employee's performance managers */
    private function managerIds(Employee $employee): array
    {
        return ReportingRelationship::query()->where('employee_id', $employee->id)->whereIn('type', $this->relationships->managerTypes())
            ->currentlyEffective()->pluck('manager_id')->map(fn ($id) => (int) $id)->unique()->values()->all();
    }
}
