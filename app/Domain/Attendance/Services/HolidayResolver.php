<?php

namespace App\Domain\Attendance\Services;

use App\Domain\Attendance\Models\EmployeeHolidayChange;
use App\Domain\Attendance\Models\Holiday;
use App\Domain\Attendance\Models\HolidayCalendar;
use App\Domain\Attendance\Models\HolidayCalendarRule;
use App\Domain\Attendance\Models\TimeOfficeRequest;
use App\Domain\Configuration\Services\EmployeeRuleContext;
use App\Domain\Configuration\Services\RuleEngine;
use App\Domain\Employment\Models\Employee;
use Illuminate\Support\Carbon;

/** Which holiday calendar applies (§15), and is this date a holiday on it? */
final class HolidayResolver
{
    public function __construct(
        private readonly RuleEngine $rules,
        private readonly EmployeeRuleContext $context,
    ) {}

    public function calendarFor(Employee $employee, ?Carbon $on = null): ?HolidayCalendar
    {
        $context = $this->context->build($employee, $on);

        $rule = HolidayCalendarRule::query()
            ->with('calendar')
            ->where('status', 'active')
            ->orderBy('priority')->orderBy('id')
            ->get()
            ->first(fn (HolidayCalendarRule $r) => $this->rules->matches($r->conditions ?? [], $context, 'all'));

        $calendar = $rule?->calendar;

        return $calendar && $calendar->status->value === 'active' ? $calendar : null;
    }

    /**
     * The holiday on the date. On a calendar whose optional holidays need choosing (HP-1 restricted holidays),
     * an optional holiday is a holiday only for employees with an approved restricted-holiday request for it.
     * HROne parity TX-12: HR's holiday change for the employee on the date wins over the calendar (dayOn()).
     */
    public function holidayOn(Employee $employee, Carbon $date): ?Holiday
    {
        return $this->dayOn($employee, $date)['holiday'];
    }

    /**
     * HROne parity TX-12 (owner decision D-TX3): the employee's holiday on the date and the holiday change that decided it.
     * A live change of the employee's day (EmployeeHolidayChange) comes on top of the calendar and wins — an `add` row
     * makes the day that holiday, a `remove` row makes it an ordinary day (the calendar is then not asked). Without a change (every day by default) this is
     * the calendar's answer exactly as before, and the change is null.
     *
     * @return array{holiday: ?Holiday, change: ?EmployeeHolidayChange}
     */
    public function dayOn(Employee $employee, Carbon $date): array
    {
        $change = EmployeeHolidayChange::query()->where('employee_id', $employee->id)->live()->onDay($date)->orderByDesc('id')->first();
        if ($change !== null) {
            return ['holiday' => $change->adds() ? $change->asHoliday() : null, 'change' => $change];
        }

        return ['holiday' => $this->calendarHolidayOn($employee, $date), 'change' => null];
    }

    /** The holiday the employee's calendar gives on the date (restricted holidays only when chosen), before any change. */
    public function calendarHolidayOn(Employee $employee, Carbon $date): ?Holiday
    {
        $calendar = $this->calendarFor($employee, $date);
        $holiday = $calendar?->holidays()->whereDate('date', $date->toDateString())->first();

        if ($holiday !== null && $holiday->type === 'optional' && $calendar->optional_requires_selection) {
            $chosen = TimeOfficeRequest::query()->where('employee_id', $employee->id)->where('type', 'restricted_holiday')
                ->where('holiday_id', $holiday->id)->effective()->exists();

            return $chosen ? $holiday : null;
        }

        return $holiday;
    }
}
