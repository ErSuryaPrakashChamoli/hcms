<?php

namespace App\Domain\Expense\Support;

use App\Domain\Attendance\Models\TimeOfficeRequest;
use App\Domain\Attendance\Services\AttendanceOutput;
use App\Domain\Attendance\Support\AttendanceDay;
use App\Domain\Employment\Models\Employee;
use App\Domain\Expense\Models\ExpenseCategory;
use App\Domain\Identity\Scopes\AccessScope;
use Carbon\CarbonInterface;

/**
 * HROne setup parity EX-C: the attendance side of an expense category's rules, read only (the attendance output contract
 * and approved on-duty requests; nothing is written). "Present" means a day counted as worked (present, half day, work
 * from home, on duty, field duty); "on duty" an on-duty or field-duty day, or an approved on-duty request covering the
 * date — of the named on-duty types when the category names them.
 */
final class ExpenseAttendance
{
    private const WORKED = ['present', 'half_day', 'wfh', 'on_duty', 'field_duty'];

    public function __construct(private readonly AttendanceOutput $output) {}

    public function day(Employee $employee, CarbonInterface $date): ?AttendanceDay
    {
        return $this->output->forEmployee($employee, $date->toDateString(), $date->toDateString())->first();
    }

    /** Minutes worked on the date, or null when the day is not processed. */
    public function workedMinutes(Employee $employee, CarbonInterface $date): ?int
    {
        $day = $this->day($employee, $date);

        return $day === null || $day->status === 'not_processed' ? null : $day->workedMinutes;
    }

    /** Why the category's attendance rule refuses this date, or null when it allows it. */
    public function refusal(Employee $employee, ExpenseCategory $category, CarbonInterface $date): ?string
    {
        $rule = $category->attendance_rule;
        if (! is_array($rule) || blank($rule['mode'] ?? null)) {
            return null;
        }
        $day = $this->day($employee, $date);
        $on = $date->format('j M Y');

        return match ($rule['mode']) {
            'present' => $day !== null && in_array($day->status, self::WORKED, true) ? null : "{$category->name} can be claimed only for a day you were present; your attendance on {$on} does not show you present.",
            'min_hours' => $day !== null && $day->workedMinutes >= (int) ($rule['min_minutes'] ?? 0) ? null
                : "{$category->name} needs at least ".self::hours((int) ($rule['min_minutes'] ?? 0)).' worked that day; your attendance on '.$on.' shows '.self::hours((int) ($day?->workedMinutes ?? 0)).'.',
            'on_duty' => $this->onDuty($employee, $date, $day, ($rule['on_duty'] ?? 'any') === 'specific' ? (array) ($rule['on_duty_types'] ?? []) : [])
                ? null : "{$category->name} can be claimed only for a day you were on duty".(($rule['on_duty'] ?? 'any') === 'specific' ? ' ('.implode(', ', (array) $rule['on_duty_types']).')' : '')." and {$on} is not one.",
            default => null,
        };
    }

    /** @param  list<string>  $types  named on-duty types (empty = any) */
    private function onDuty(Employee $employee, CarbonInterface $date, ?AttendanceDay $day, array $types): bool
    {
        $wanted = array_map(fn ($t) => mb_strtolower(trim((string) $t)), $types);
        if ($day !== null && in_array($day->status, ['on_duty', 'field_duty'], true) && ($wanted === [] || in_array($day->status, $wanted, true) || in_array(str_replace('_', ' ', $day->status), $wanted, true))) {
            return true;
        }

        return TimeOfficeRequest::query()->withoutGlobalScope(AccessScope::class)->where('employee_id', $employee->getKey())->where('type', 'on_duty')->where('status', 'approved')
            ->whereDate('from_date', '<=', $date->toDateString())->whereDate('to_date', '>=', $date->toDateString())
            ->get(['id', 'category'])
            ->contains(fn (TimeOfficeRequest $r) => $wanted === [] || in_array(mb_strtolower(trim((string) $r->category)), $wanted, true));
    }

    private static function hours(int $minutes): string
    {
        return intdiv($minutes, 60).'h '.str_pad((string) ($minutes % 60), 2, '0', STR_PAD_LEFT).'m';
    }
}
