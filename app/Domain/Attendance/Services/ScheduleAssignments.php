<?php

namespace App\Domain\Attendance\Services;

use App\Domain\Attendance\Models\WorkSchedule;
use App\Domain\Attendance\Models\WorkScheduleAssignment;
use App\Domain\Employment\Models\Employee;
use App\Domain\Identity\Models\User;
use App\Domain\Identity\Services\AccessScopes;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * The one write path that gives an employee a work schedule (weekly pattern of shifts and weekly offs) from a date: the
 * Employee 360's "Assign work schedule" and, since Data import DI-2, the Employee settings upload. The open assignment
 * that started earlier ends the day before; the new one is audited with the reason. Needs attendance.manage within the
 * organisation scope.
 */
final class ScheduleAssignments
{
    public const PERMISSION = 'attendance.manage';

    public function assign(Employee $employee, WorkSchedule $schedule, CarbonInterface|string $from, ?string $reason, User $actor): WorkScheduleAssignment
    {
        if (! $actor->hasPermission(self::PERMISSION) || ! app(AccessScopes::class)->allows($actor, $employee)) {
            throw new RuntimeException('Assigning a work schedule needs attendance.manage for this employee.');
        }
        $from = Carbon::parse($from)->startOfDay();

        return DB::transaction(function () use ($employee, $schedule, $from, $reason) {
            $employee->scheduleAssignments()->whereNull('effective_to')->where('effective_from', '<', $from->toDateString())->get()
                ->each(fn (WorkScheduleAssignment $a) => $a->withAuditReason($reason)->update(['effective_to' => $from->copy()->subDay()]));

            $assignment = new WorkScheduleAssignment(['employee_id' => $employee->getKey(), 'work_schedule_id' => $schedule->getKey(), 'effective_from' => $from, 'reason' => $reason]);
            $assignment->withAuditReason($reason)->save();

            return $assignment;
        });
    }

    /** The assignment in force on a date, if any. */
    public function on(Employee $employee, CarbonInterface|string $date): ?WorkScheduleAssignment
    {
        return WorkScheduleAssignment::query()->where('employee_id', $employee->getKey())->effectiveOn($date)->orderByDesc('effective_from')->orderByDesc('id')->first();
    }
}
