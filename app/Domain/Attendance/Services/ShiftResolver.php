<?php

namespace App\Domain\Attendance\Services;

use App\Domain\Attendance\Models\Shift;
use App\Domain\Attendance\Models\WorkSchedule;
use App\Domain\Attendance\Models\WorkScheduleAssignment;
use App\Domain\Attendance\Models\WorkScheduleRule;
use App\Domain\Configuration\Services\EmployeeRuleContext;
use App\Domain\Configuration\Services\RuleEngine;
use App\Domain\Employment\Models\Employee;
use Illuminate\Support\Carbon;

/**
 * Which shift does this employee work on this date? Individual assignment first, then the
 * highest-priority schedule rule. Returns ['schedule' => ?WorkSchedule, 'shift' => ?Shift,
 * 'weekly_off' => bool]. No schedule at all -> shift null and weekly_off false ("no shift").
 */
final class ShiftResolver
{
    public function __construct(
        private readonly RuleEngine $rules,
        private readonly EmployeeRuleContext $context,
    ) {}

    /** @return array{schedule: ?WorkSchedule, shift: ?Shift, weekly_off: bool, anchor: ?Carbon} */
    public function resolve(Employee $employee, Carbon $date): array
    {
        [$schedule, $anchor] = $this->scheduleFor($employee, $date);

        if ($schedule === null) {
            return ['schedule' => null, 'shift' => null, 'weekly_off' => false, 'anchor' => null];
        }

        $shiftId = $schedule->shiftIdFor($date, $anchor);
        $shift = $shiftId ? Shift::query()->find($shiftId) : null;

        return ['schedule' => $schedule, 'shift' => $shift, 'weekly_off' => $shiftId === null, 'anchor' => $anchor];
    }

    /** @return array{0: ?WorkSchedule, 1: ?Carbon} */
    public function scheduleFor(Employee $employee, Carbon $date): array
    {
        $assignment = WorkScheduleAssignment::query()
            ->with('schedule')
            ->where('employee_id', $employee->id)
            ->effectiveOn($date)
            ->orderByDesc('effective_from')->orderByDesc('id')
            ->first();

        if ($assignment && $assignment->schedule?->status->value === 'active') {
            return [$assignment->schedule, $assignment->effective_from];
        }

        $context = $this->context->build($employee, $date);

        $rule = WorkScheduleRule::query()
            ->with('schedule')
            ->where('status', 'active')
            ->effectiveOn($date)
            ->orderBy('priority')->orderBy('id')
            ->get()
            ->first(fn (WorkScheduleRule $r) => $this->rules->matches($r->conditions ?? [], $context, 'all'));

        return $rule?->schedule ? [$rule->schedule, $rule->schedule->effective_from ?? $rule->effective_from] : [null, null];
    }
}
