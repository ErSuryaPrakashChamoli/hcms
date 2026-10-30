<?php

namespace App\Domain\Attendance\Services;

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
     */
    public function holidayOn(Employee $employee, Carbon $date): ?Holiday
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
