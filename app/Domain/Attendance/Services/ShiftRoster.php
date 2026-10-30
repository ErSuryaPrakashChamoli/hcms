<?php

namespace App\Domain\Attendance\Services;

use App\Domain\Attendance\Models\AttendanceRecord;
use App\Domain\Attendance\Models\Shift;
use App\Domain\Attendance\Models\ShiftOverride;
use App\Domain\Audit\Services\AuditRecorder;
use App\Domain\Employment\Models\Employee;
use App\Domain\Identity\Models\User;
use App\Domain\Platform\Services\SettingsRepository;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * The day-level roster (HROne parity HP-1): HR puts employees on a shift (or a weekly off) for given dates,
 * over whatever their schedule says. One bulk action is one audited operation; past days are recalculated.
 */
final class ShiftRoster
{
    public function __construct(
        private readonly AttendanceProcessor $processor,
        private readonly SettingsRepository $settings,
        private readonly AuditRecorder $audit,
    ) {}

    /**
     * @param  iterable<Employee>  $employees
     * @return int overrides written
     */
    public function assign(iterable $employees, Carbon|string $from, Carbon|string $to, ?Shift $shift, string $reason, ?User $actor = null, string $source = 'roster'): int
    {
        $from = Carbon::parse($from)->startOfDay();
        $to = Carbon::parse($to)->startOfDay();
        if ($to->lt($from)) {
            throw new RuntimeException('The end date must not be before the start date.');
        }
        if ($from->diffInDays($to) > 92) {
            throw new RuntimeException('Roster at most three months at a time.');
        }
        $actor ??= auth()->user();
        $written = 0;

        $this->audit->operation('attendance', ($shift ? "Roster {$shift->name}" : 'Roster weekly off').' '.$from->toDateString().' – '.$to->toDateString(), function () use ($employees, $from, $to, $shift, $reason, $actor, $source, &$written) {
            foreach ($employees as $employee) {
                DB::transaction(function () use ($employee, $from, $to, $shift, $reason, $actor, $source, &$written) {
                    $this->assertUnlocked($employee, $from, $to);
                    for ($day = $from->copy(); $day->lte($to); $day->addDay()) {
                        $override = ShiftOverride::query()->where('employee_id', $employee->id)->whereDate('date', $day->toDateString())->lockForUpdate()->first()
                            ?? new ShiftOverride(['employee_id' => $employee->id, 'date' => $day->copy()]);
                        $override->fill(['shift_id' => $shift?->id, 'source' => $source, 'time_office_request_id' => null, 'reason' => $reason, 'created_by' => $actor?->id])
                            ->withAuditReason($reason)->save();
                        $written++;
                    }
                });
                $this->reprocess($employee, $from, $to);
            }

            return ['succeeded' => $written];
        }, entityType: ShiftOverride::class);

        return $written;
    }

    /** @param  iterable<Employee>  $employees */
    public function clear(iterable $employees, Carbon|string $from, Carbon|string $to, string $reason): int
    {
        $from = Carbon::parse($from)->startOfDay();
        $to = Carbon::parse($to)->startOfDay();
        $removed = 0;

        foreach ($employees as $employee) {
            $this->assertUnlocked($employee, $from, $to);
            ShiftOverride::query()->where('employee_id', $employee->id)->whereDate('date', '>=', $from->toDateString())->whereDate('date', '<=', $to->toDateString())
                ->get()->each(function (ShiftOverride $override) use ($reason, &$removed) {
                    $override->withAuditReason($reason)->delete();
                    $removed++;
                });
            $this->reprocess($employee, $from, $to);
        }

        return $removed;
    }

    private function reprocess(Employee $employee, Carbon $from, Carbon $to): void
    {
        $end = $to->copy()->min(now()->startOfDay());
        for ($day = $from->copy(); $day->lte($end); $day->addDay()) {
            $this->processor->process($employee, $day->copy());
        }
    }

    private function assertUnlocked(Employee $employee, Carbon $from, Carbon $to): void
    {
        if ((bool) $this->settings->get('attendance.allow_corrections_after_lock', false)) {
            return;
        }
        if (AttendanceRecord::query()->where('employee_id', $employee->id)->where('is_locked', true)->whereDate('date', '>=', $from->toDateString())->whereDate('date', '<=', $to->toDateString())->exists()) {
            throw new RuntimeException("Part of this period is locked for payroll for {$employee->display_name}.");
        }
    }
}
