<?php

namespace App\Domain\Employment\Services;

use App\Domain\Configuration\Services\OptionLists;
use App\Domain\Documents\Models\EmployeeDocument;
use App\Domain\Documents\Services\DocumentRules;
use App\Domain\Employment\Models\Employee;
use App\Domain\Employment\Models\EmployeePosition;
use App\Domain\Employment\Models\ReportingRelationship;
use App\Domain\Feedback\Services\FeedbackRequests;
use App\Domain\Identity\Enums\UserStatus;
use App\Domain\Identity\Models\User;
use App\Domain\Identity\Scopes\AccessScope;
use App\Domain\Identity\Services\AccessScopes;
use App\Domain\Lifecycle\Enums\LifecycleState;
use App\Domain\Lifecycle\Models\EmployeeLifecycleTransition;
use App\Domain\Lifecycle\Services\LifecycleEngine;
use App\Domain\Lifecycle\Services\Timeline;
use App\Domain\Onboarding\Models\OnboardingPlan;
use App\Domain\Onboarding\Models\OnboardingTask;
use App\Domain\Onboarding\Services\Onboarding;
use App\Domain\Onboarding\Support\ProfileSections;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use RuntimeException;

/**
 * HROne parity WO-4 (matrix 23 §23.3.1, "New hires (upcoming join)"): the people hired before their first day — employees in
 * the pre-joining states (pre-employee, pre-boarding) — and what HR does with them before day one.
 *
 * - The expected joining date is `expected_joining_date` (offers handed over by a recruitment system) or, for someone hired
 *   with a future joining date, `joining_date` (the planned date until "Mark as joined" sets the real one).
 * - "Change expected joining date" (employee.lifecycle and the record's view): only before joining, never earlier than today,
 *   with a reason. The date, the probation end, the hire's position and reporting lines that start on the old date (when they
 *   are the only ones) and the not-yet-started onboarding items (Onboarding::reschedule) move with it; on the timeline.
 * - "Mark as not joining" (owner decision WRK-1 / D-WO1, the recommended option): the end state not_joined, from the
 *   pre-joining states only, with a reason from the option list `not_joining_reasons`. The record is kept for retention and
 *   rehire checks (ReturningEmployees still finds it), the onboarding plans are cancelled (Onboarding::cancel), the planned
 *   position and reporting lines end (so no team, seat or organisation count holds them), the planned date moves to
 *   `expected_joining_date` (no joining date: joiner counts and joining-date reports never count them), a linked sign-in is
 *   suspended; nothing is deleted. Everywhere a population is chosen, not_joined counts as never employed.
 */
final class UpcomingJoiners
{
    public const OPTION_LIST = 'not_joining_reasons';

    /** The register's joining status filter (§23.3.1 #16). */
    public const JOINING_STATUSES = ['joining' => 'Joining', 'not_joining' => 'Not joining', 'all' => 'All'];

    public function __construct(
        private readonly LifecycleEngine $lifecycle,
        private readonly Onboarding $onboarding,
        private readonly Timeline $timeline,
        private readonly OptionLists $options,
        private readonly AccessScopes $scopes,
    ) {}

    /** @return list<string> */
    public static function preJoiningStates(): array
    {
        return [LifecycleState::PreEmployee->value, LifecycleState::Preboarding->value];
    }

    /** Upcoming joiners (joining), people marked as not joining, or both — inside the viewer's access scope. */
    public static function query(string $status = 'joining'): Builder
    {
        $states = match ($status) {
            'not_joining' => [LifecycleState::NotJoined->value],
            'all' => [...self::preJoiningStates(), LifecycleState::NotJoined->value],
            default => self::preJoiningStates(),
        };

        return Employee::query()->whereIn('employees.lifecycle_state', $states);
    }

    /** SQL for the expected joining date (the register sorts and filters on it). */
    public static function expectedDateSql(): string
    {
        return 'COALESCE(employees.expected_joining_date, employees.joining_date)';
    }

    public static function expectedDate(Employee $employee): ?Carbon
    {
        $date = $employee->expected_joining_date ?? $employee->joining_date;

        return $date === null ? null : Carbon::parse($date)->startOfDay();
    }

    /** Days from today to the expected joining date (negative once it has passed); null without a date. */
    public static function daysToJoin(Employee $employee, ?CarbonInterface $today = null): ?int
    {
        $date = self::expectedDate($employee);

        return $date === null ? null : (int) Carbon::parse($today ?? now())->startOfDay()->diffInDays($date, false);
    }

    /** @return array<string, string> code => label of the not-joining reasons offered now */
    public function reasons(): array
    {
        return $this->options->options(self::OPTION_LIST);
    }

    /** Why the actor may not change this person's joining date or mark them as not joining (null = may). */
    public function refusal(User $actor, Employee $employee): ?string
    {
        if (! $actor->hasPermission('employee.lifecycle') || Gate::forUser($actor)->denies('transition', $employee)) {
            return 'You cannot change joining details (needs the lifecycle right).';
        }
        if (Gate::forUser($actor)->denies('view', $employee) || ! $this->scopes->allows($actor, $employee)) {
            return 'This employee is outside your access.';
        }
        $state = $employee->lifecycle_state;
        if ($state === LifecycleState::NotJoined) {
            return $employee->auditLabel().' did not join.';
        }
        if (! $state->isPreJoining()) {
            return $employee->auditLabel().' has already joined ('.$state->getLabel().').';
        }

        return null;
    }

    /**
     * "Change expected joining date" (§23.3.1 #13).
     *
     * @return array{employee: Employee, onboarding_items: int, rows_moved: int}
     */
    public function changeJoiningDate(Employee $employee, CarbonInterface|string $date, string $reason, User $actor): array
    {
        $employee->refresh();
        if (($refusal = $this->refusal($actor, $employee)) !== null) {
            throw new RuntimeException($refusal);
        }
        $reason = trim($reason);
        if ($reason === '' || mb_strlen($reason) > 500) {
            throw new RuntimeException('Give a reason of at most 500 characters.');
        }
        $new = Carbon::parse($date)->startOfDay();
        if ($new->lt(now()->startOfDay())) {
            throw new RuntimeException('The new joining date cannot be earlier than today.');
        }
        $old = self::expectedDate($employee);
        if ($old !== null && $old->equalTo($new)) {
            throw new RuntimeException('That is already the expected joining date.');
        }

        return DB::transaction(function () use ($employee, $new, $old, $reason) {
            $attributes = ['expected_joining_date' => $new];
            if ($employee->joining_date !== null) {
                $attributes['joining_date'] = $new;
            }
            if ($old !== null && $employee->probation_end_date !== null) {
                $end = $employee->probation_end_date->copy()->startOfDay();
                $months = (int) $old->diffInMonths($end);
                $attributes['probation_end_date'] = $old->copy()->addMonthsNoOverflow($months)->isSameDay($end)
                    ? $new->copy()->addMonthsNoOverflow($months)
                    : $end->copy()->addDays((int) $old->diffInDays($new, false));
            }
            $employee->withAuditReason($reason)->update($attributes);

            $moved = $old === null ? 0 : $this->redateHireRows($employee, $old, $new, $reason);
            $items = 0;
            OnboardingPlan::query()->where('employee_id', $employee->id)->where('status', 'in_progress')->whereIn('kind', ['onboarding', 'induction'])->orderBy('id')->get()
                ->each(function (OnboardingPlan $plan) use ($new, $reason, &$items): void {
                    $items += $this->onboarding->reschedule($plan, $new, $reason);
                });

            $this->timeline->record($employee, 'lifecycle', 'Expected joining date changed', now(),
                ($old?->format('d M Y') ?? 'none').' → '.$new->format('d M Y')."\nReason: {$reason}", null,
                ['from' => $old?->toDateString(), 'to' => $new->toDateString(), 'onboarding_items' => $items]);

            return ['employee' => $employee->refresh(), 'onboarding_items' => $items, 'rows_moved' => $moved];
        });
    }

    /**
     * "Mark as not joining" (§23.3.1 #14; D-WO1). $note is required for the reason "other".
     */
    public function markNotJoining(Employee $employee, string $reasonCode, ?string $note, User $actor, CarbonInterface|string|null $on = null): Employee
    {
        $employee->refresh();
        if (($refusal = $this->refusal($actor, $employee)) !== null) {
            throw new RuntimeException($refusal);
        }
        $reasons = $this->reasons();
        if (! array_key_exists($reasonCode, $reasons)) {
            throw new RuntimeException('Choose why they are not joining.');
        }
        $note = trim((string) $note);
        if (mb_strlen($note) > 500) {
            throw new RuntimeException('Keep the note to 500 characters.');
        }
        if ($reasonCode === 'other' && $note === '') {
            throw new RuntimeException('Say why in the note.');
        }
        $day = Carbon::parse($on ?? now())->startOfDay();
        if ($day->gt(now()->startOfDay())) {
            throw new RuntimeException('The not-joining date cannot be in the future.');
        }
        $reasonText = 'Not joining: '.$reasons[$reasonCode].($note !== '' ? ' — '.$note : '');
        $planned = self::expectedDate($employee);

        return DB::transaction(function () use ($employee, $reasonCode, $note, $reasonText, $planned, $day) {
            // The onboarding (and induction / issued items) stop: pending items are closed as skipped, never deleted.
            OnboardingPlan::query()->where('employee_id', $employee->id)->where('status', 'in_progress')->orderBy('id')->get()
                ->each(function (OnboardingPlan $plan) use ($reasonText): void {
                    $plan->tasks()->where('status', 'pending')->where('type', 'feedback')->get()
                        ->each(fn (OnboardingTask $task) => app(FeedbackRequests::class)->cancelFor($task, $reasonText));
                    $this->onboarding->cancel($plan, $reasonText);
                });

            // The planned position and reporting lines end: no team, seat or organisation count holds them any more.
            $this->endOpenRows(EmployeePosition::query(), $employee, $day, $reasonText);
            $this->endOpenRows(ReportingRelationship::query(), $employee, $day, $reasonText);

            // Never joined: no joining date (the planned one is kept as the expected joining date).
            $employee->withAuditReason($reasonText)->update(['joining_date' => null, 'expected_joining_date' => $planned]);

            $this->lifecycle->transition($employee, LifecycleState::NotJoined, $day, $reasonText, array_filter([
                'not_joining_reason' => $reasonCode,
                'note' => $note !== '' ? $note : null,
                'expected_joining_date' => $planned?->toDateString(),
            ], fn ($v) => $v !== null));

            $user = $employee->user_id === null ? null : User::forCurrentTenant()->find($employee->user_id);
            if ($user !== null && ! $user->isPlatformAdmin() && in_array($user->status, [UserStatus::Active, UserStatus::Invited], true)) {
                $user->forceFill(['status' => UserStatus::Suspended])->withAuditReason('Did not join: sign-in turned off')->save();
            }

            return $employee->refresh();
        });
    }

    /** @return array{code: string, label: string, note: ?string, on: ?string, expected_joining_date: ?string}|null */
    public function notJoining(Employee $employee): ?array
    {
        $transition = EmployeeLifecycleTransition::query()->where('employee_id', $employee->id)->where('to_state', LifecycleState::NotJoined->value)->latest('id')->first();
        if ($transition === null) {
            return null;
        }
        $meta = (array) ($transition->metadata ?? []);
        $code = (string) ($meta['not_joining_reason'] ?? '');

        return ['code' => $code, 'label' => (string) ($this->options->label(self::OPTION_LIST, $code) ?? $code), 'note' => $meta['note'] ?? null,
            'on' => $transition->effective_date?->toDateString(), 'expected_joining_date' => $meta['expected_joining_date'] ?? null];
    }

    /** People set to report to this upcoming joiner (their manager must be changed if the joiner does not come). */
    public function reportees(Employee $employee): int
    {
        return ReportingRelationship::query()->withoutGlobalScope(AccessScope::class)->where('manager_id', $employee->id)
            ->where(fn (Builder $q) => $q->whereNull('effective_to')->orWhere('effective_to', '>=', now()->toDateString()))->count();
    }

    /**
     * Documents still needed before joining: types mandatory for onboarding without a current document, and documents
     * uploaded and waiting for verification.
     *
     * @return array{missing: list<string>, to_verify: int}
     */
    public function documentsPending(Employee $employee): array
    {
        return [
            'missing' => app(DocumentRules::class)->missing($employee, 'onboarding')->pluck('name')->map(fn ($n) => (string) $n)->values()->all(),
            'to_verify' => EmployeeDocument::query()->withoutGlobalScope(AccessScope::class)->where('employee_id', $employee->id)->where('status', 'pending')->count(),
        ];
    }

    /** @return array<string, string> section key => label of the profile sections the joiner has not filled in yet */
    public function missingProfileSections(Employee $employee): array
    {
        $missing = [];
        foreach ((array) config('peopleos.lifecycle.joiners.profile_sections', []) as $section) {
            if (! ProfileSections::filled($employee, (string) $section)) {
                $missing[(string) $section] = ProfileSections::label((string) $section);
            }
        }

        return $missing;
    }

    /**
     * The hire's position row and each reporting line (per type) starting on the old date move to the new date — only when
     * it is the employee's only row of its kind and still open (a later change means the history was edited: left alone).
     */
    private function redateHireRows(Employee $employee, Carbon $old, Carbon $new, string $reason): int
    {
        $moved = 0;
        $positions = EmployeePosition::query()->withoutGlobalScope(AccessScope::class)->where('employee_id', $employee->id)->get();
        $groups = [$positions, ...ReportingRelationship::query()->withoutGlobalScope(AccessScope::class)->where('employee_id', $employee->id)->get()->groupBy('type')->values()->all()];
        foreach ($groups as $rows) {
            $row = $rows->count() === 1 ? $rows->first() : null;
            if ($row !== null && $row->effective_to === null && $row->effective_from !== null && $row->effective_from->isSameDay($old)) {
                $row->withAuditReason($reason)->update(['effective_from' => $new]);
                $moved++;
            }
        }

        return $moved;
    }

    /**
     * Ends the employee's open rows of an effective-dated kind on the not-joining day: the day before it, or — for a row that
     * starts on or after that day — the day before the row starts (an empty range, as a same-day change leaves one).
     */
    private function endOpenRows(Builder $query, Employee $employee, Carbon $day, string $reason): void
    {
        $query->withoutGlobalScope(AccessScope::class)->where('employee_id', $employee->id)
            ->where(fn (Builder $q) => $q->whereNull('effective_to')->orWhere('effective_to', '>=', $day->toDateString()))
            ->orderBy('id')->get()
            ->each(function ($row) use ($day, $reason): void {
                $end = $day->copy()->subDay();
                if ($row->effective_from !== null && $row->effective_from->copy()->startOfDay()->gte($day)) {
                    $end = $row->effective_from->copy()->startOfDay()->subDay();
                }
                $row->withAuditReason($reason)->update(['effective_to' => $end]);
            });
    }
}
