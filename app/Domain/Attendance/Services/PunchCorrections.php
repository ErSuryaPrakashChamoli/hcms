<?php

namespace App\Domain\Attendance\Services;

use App\Domain\Attendance\Models\AttendancePunch;
use App\Domain\Attendance\Models\AttendanceRecord;
use App\Domain\Audit\Enums\AuditAction;
use App\Domain\Audit\Services\AuditRecorder;
use App\Domain\Employment\Models\Employee;
use App\Domain\Employment\Models\ReportingRelationship;
use App\Domain\Identity\Models\User;
use App\Domain\Identity\Scopes\AccessScope;
use App\Domain\Identity\Services\AccessScopes;
use App\Domain\Platform\Services\SettingsRepository;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Throwable;

/**
 * HROne parity TX-7 (matrix 24 §24.5.1 #5–#7, §24.5.2 #3, §24.13 #7; owner decision D-TX5 — HROne "Add punch" / "Delete
 * punch"): punches are corrected, never deleted.
 * - exclude(): the punch keeps every field it arrived with (device data untouched) and becomes `excluded`, with the reason,
 *   who and when, and the processing status it had; processing skips it (PunchIngestion::punchesBetween) and the day it
 *   belongs to is recalculated at once.
 * - restore(): the status it had comes back and the day is recalculated with it again.
 * - add(): a punch recorded by hand (source manual, the reason as its note) through PunchIngestion::record, and its day
 *   recalculated — the records list's "Manual punch" with the refusals below.
 *
 * Who: holders of attendance.punch (TX-2; attendance.manage also grants it) for employees inside their access scope; with the
 * team option `team.punch_edit` (off by default) also a manager for the people who report to them today (any reporting
 * type, inside their access scope). Never one's own punches. Locked (finalized) days and days of a closed attendance month
 * follow the lock rule: refused unless `attendance.allow_corrections_after_lock` is on — then the day keeps its values and the
 * processor records the difference as an attendance arrear, as for every other correction.
 */
final class PunchCorrections
{
    public const DIRECTIONS = ['auto' => 'Auto', 'in' => 'In', 'out' => 'Out'];

    public function __construct(
        private readonly AttendanceProcessor $processor,
        private readonly PunchIngestion $ingestion,
        private readonly SettingsRepository $settings,
        private readonly AuditRecorder $audit,
        private readonly AccessScopes $scopes,
    ) {}

    /** Why $actor may not add, exclude or restore $employee's punches (null = they may). */
    public function refusal(?User $actor, Employee $employee): ?string
    {
        if ($actor === null) {
            return 'Sign in to change punches.';
        }
        if ($employee->user_id !== null && (int) $employee->user_id === (int) $actor->id) {
            return 'You cannot change your own punches.';
        }
        if (! $actor->hasPermission('attendance.punch')) {
            if (! $this->teamOption()) {
                return 'You may not change punches.';
            }
            if (! $actor->hasPermission('attendance.view') || ! $this->manages($actor, $employee)) {
                return 'You may change punches only for the people who report to you.';
            }
        }

        return $this->scopes->allows($actor, $employee) ? null : 'Outside your access scope.';
    }

    public function mayChange(?User $actor, Employee $employee): bool
    {
        return $this->refusal($actor, $employee) === null;
    }

    /** Managers may change their team's punches (Setup → Time office; off by default). */
    public function teamOption(): bool
    {
        return (bool) $this->settings->get('team.punch_edit', false);
    }

    /** Whether $employee reports to $actor today (any reporting type). */
    public function manages(User $actor, Employee $employee): bool
    {
        return ReportingRelationship::query()->withoutGlobalScope(AccessScope::class)->currentlyEffective()
            ->where('employee_id', $employee->id)
            ->whereIn('manager_id', Employee::query()->withoutGlobalScope(AccessScope::class)->select('id')->where('user_id', $actor->id))
            ->exists();
    }

    public function exclude(AttendancePunch $punch, string $reason, ?User $actor = null): AttendancePunch
    {
        $actor ??= auth()->user();
        $reason = $this->reason($reason, 'Give the reason for excluding the punch.');
        $employee = $this->employeeOf($punch);
        $this->authorise($actor, $employee);
        if ($punch->processing_status === 'excluded') {
            throw new RuntimeException('This punch is already excluded.');
        }
        if ($punch->processing_status === 'rejected') {
            throw new RuntimeException('This punch was refused at check-in and is not counted.');
        }
        $days = $this->daysOf($employee, $punch->punched_at);
        $this->assertOpen($employee, $days);

        DB::transaction(function () use ($punch, $reason, $actor) {
            $locked = AttendancePunch::query()->whereKey($punch->id)->lockForUpdate()->first() ?? throw new RuntimeException('This punch no longer exists.');
            if (in_array($locked->processing_status, AttendancePunch::NOT_COUNTED, true)) {
                throw new RuntimeException('This punch is no longer counted.');
            }
            $before = (string) $locked->processing_status;
            $locked->forceFill(['processing_status' => 'excluded', 'status_before_exclusion' => $before, 'excluded_at' => now(), 'excluded_by' => $actor?->id, 'exclusion_reason' => $reason])->save();
            $this->audit->record(AuditAction::StatusChange, 'attendance', $locked, [['field' => 'processing_status', 'before' => $before, 'after' => 'excluded']],
                reason: $reason, metadata: $this->metadata($locked, 'excluded'), entityLabel: $this->label($locked), actor: $actor);
        });

        $this->reprocess($employee, $days);

        return $punch->refresh();
    }

    public function restore(AttendancePunch $punch, string $reason, ?User $actor = null): AttendancePunch
    {
        $actor ??= auth()->user();
        $reason = $this->reason($reason, 'Give the reason for restoring the punch.');
        $employee = $this->employeeOf($punch);
        $this->authorise($actor, $employee);
        if ($punch->processing_status !== 'excluded') {
            throw new RuntimeException('Only an excluded punch can be restored.');
        }
        $days = $this->daysOf($employee, $punch->punched_at);
        $this->assertOpen($employee, $days);

        DB::transaction(function () use ($punch, $reason, $actor) {
            $locked = AttendancePunch::query()->whereKey($punch->id)->lockForUpdate()->first() ?? throw new RuntimeException('This punch no longer exists.');
            if ($locked->processing_status !== 'excluded') {
                throw new RuntimeException('Only an excluded punch can be restored.');
            }
            $after = in_array($locked->status_before_exclusion, [null, '', 'excluded', 'rejected'], true) ? 'normalized' : (string) $locked->status_before_exclusion;
            $locked->forceFill(['processing_status' => $after, 'status_before_exclusion' => null, 'excluded_at' => null, 'excluded_by' => null, 'exclusion_reason' => null])->save();
            $this->audit->record(AuditAction::Restore, 'attendance', $locked, [['field' => 'processing_status', 'before' => 'excluded', 'after' => $after]],
                reason: $reason, metadata: $this->metadata($locked, 'restored'), entityLabel: $this->label($locked), actor: $actor);
        });

        $this->reprocess($employee, $days);

        return $punch->refresh();
    }

    /** A punch recorded by hand for $employee, its day recalculated. Null when an identical punch is already on record. */
    public function add(Employee $employee, Carbon|string $at, string $direction, string $reason, ?User $actor = null): ?AttendancePunch
    {
        $actor ??= auth()->user();
        $reason = mb_substr($this->reason($reason, 'Give the reason for recording the punch.'), 0, 255);
        try {
            $at = $at instanceof Carbon ? $at->copy() : Carbon::parse($at, config('app.timezone'));
        } catch (Throwable) {
            throw new RuntimeException('Give the date and time of the punch.');
        }
        if (! array_key_exists($direction, self::DIRECTIONS)) {
            throw new RuntimeException('Choose In, Out or Auto.');
        }
        $this->authorise($actor, $employee);
        if ($at->gt(now())) {
            throw new RuntimeException('A punch cannot be in the future.');
        }
        if ($employee->joining_date !== null && $at->copy()->startOfDay()->lt($employee->joining_date->copy()->startOfDay())) {
            throw new RuntimeException('Not employed on this day (joins '.$employee->joining_date->format('d M Y').').');
        }
        if ($employee->exit_date !== null && $at->copy()->startOfDay()->gt($employee->exit_date->copy()->startOfDay())) {
            throw new RuntimeException('Not employed on this day (left '.$employee->exit_date->format('d M Y').').');
        }
        $days = $this->daysOf($employee, $at);
        $this->assertOpen($employee, $days);

        $punch = $this->ingestion->record($employee, $at, $direction, 'manual', null, null, [], $reason);
        if ($punch === null) {
            return null;
        }
        $this->audit->record(AuditAction::Create, 'attendance', $punch, reason: $reason, metadata: $this->metadata($punch, 'added'), entityLabel: $this->label($punch), actor: $actor);
        // PunchIngestion queues the day when attendance.process_on_punch is on; the day is recalculated here either way.
        $this->reprocess($employee, $days);

        return $punch->refresh();
    }

    /**
     * The work days a punch belongs to: each day (the previous one or its own) whose punch window holds it — as
     * PunchIngestion attributes a punch — else its own date.
     *
     * @return list<Carbon>
     */
    public function daysOf(Employee $employee, Carbon $at): array
    {
        $days = [];
        foreach ([$at->copy()->subDay()->startOfDay(), $at->copy()->startOfDay()] as $candidate) {
            [$from, $to] = $this->processor->windowFor($employee, $candidate);
            if ($at->betweenIncluded($from, $to)) {
                $days[] = $candidate;
            }
        }

        return $days === [] ? [$at->copy()->startOfDay()] : $days;
    }

    /**
     * The lock rule on the punch's days: a day of a closed attendance month or a locked (finalized) day is refused unless
     * corrections after lock are allowed.
     *
     * @param  list<Carbon>  $days
     */
    public function assertOpen(Employee $employee, array $days): void
    {
        $closes = app(AttendanceMonthCloses::class);
        foreach ($days as $day) {
            $closes->assertOpen($employee, $day, $day, 'This day is in a closed attendance month.');
        }
        if ((bool) $this->settings->get('attendance.allow_corrections_after_lock', false)) {
            return;
        }
        foreach ($days as $day) {
            if (AttendanceRecord::query()->withoutGlobalScope(AccessScope::class)->where('employee_id', $employee->id)->whereDate('date', $day->toDateString())->where('is_locked', true)->exists()) {
                throw new RuntimeException('This day is locked (finalized days).');
            }
        }
    }

    private function authorise(?User $actor, Employee $employee): void
    {
        if (($refusal = $this->refusal($actor, $employee)) !== null) {
            throw new RuntimeException($refusal);
        }
    }

    private function employeeOf(AttendancePunch $punch): Employee
    {
        return $punch->employee_id === null ? throw new RuntimeException('This punch belongs to no known employee.')
            : (Employee::query()->withoutGlobalScope(AccessScope::class)->find($punch->employee_id) ?? throw new RuntimeException('This punch belongs to no known employee.'));
    }

    private function reason(string $reason, string $missing): string
    {
        $reason = mb_substr(trim($reason), 0, 500);

        return $reason === '' ? throw new RuntimeException($missing) : $reason;
    }

    /** @param  list<Carbon>  $days */
    private function reprocess(Employee $employee, array $days): void
    {
        foreach ($days as $day) {
            if ($day->lte(now())) {
                $this->processor->process($employee, $day);
            }
        }
    }

    /** @return array<string, mixed> */
    private function metadata(AttendancePunch $punch, string $what): array
    {
        return ['punch' => $what, 'employee_id' => (int) $punch->employee_id, 'punched_at' => $punch->punched_at?->toIso8601String(), 'direction' => $punch->direction, 'source_type' => $punch->source_type];
    }

    private function label(AttendancePunch $punch): string
    {
        return 'Punch '.$punch->punched_at?->format('d M Y H:i').' ('.$punch->direction.')';
    }
}
