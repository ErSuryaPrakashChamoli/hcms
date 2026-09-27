<?php

namespace App\Domain\Attendance\Services;

use App\Domain\Attendance\Models\Holiday;
use App\Domain\Attendance\Models\HolidayCalendar;
use App\Domain\Attendance\Models\HolidayCalendarRule;
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

    public function holidayOn(Employee $employee, Carbon $date): ?Holiday
    {
        $calendar = $this->calendarFor($employee, $date);

        return $calendar?->holidays()->whereDate('date', $date->toDateString())->first();
    }
}
