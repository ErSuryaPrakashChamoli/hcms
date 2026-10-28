<?php

namespace App\Domain\Analytics\Datasets;

use App\Domain\Compensation\Contracts\CompensationOutput;
use App\Domain\Employment\Models\Employee;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;

class EmployeesDataset extends Dataset
{
    /** @var array<int, float>|null annual CTC by employee, loaded once per dataset instance */
    private ?array $ctc = null;

    public function key(): string
    {
        return 'employees';
    }

    public function label(): string
    {
        return 'Employees (master, headcount, demographics, tenure, cost)';
    }

    public function category(): string
    {
        return 'People';
    }

    public function permissions(): array
    {
        return ['employee.view'];
    }

    public function sensitivePermission(): ?string
    {
        return 'employee.sensitive.view';
    }

    public function fields(): array
    {
        $pos = fn ($e) => $e->currentPosition;

        return [
            'employee_code' => ['label' => 'Employee code', 'type' => 'string', 'value' => fn ($e) => $e->employee_code],
            'name' => ['label' => 'Name', 'type' => 'string', 'value' => fn ($e) => $e->person?->full_name],
            'gender' => ['label' => 'Gender', 'type' => 'string', 'value' => fn ($e) => $e->person?->gender],
            'age' => ['label' => 'Age', 'type' => 'number', 'value' => fn ($e) => $e->person?->date_of_birth ? (int) $e->person->date_of_birth->diffInYears(now()) : null],
            'age_band' => ['label' => 'Age band', 'type' => 'string', 'value' => fn ($e) => $e->person?->date_of_birth ? self::band((int) $e->person->date_of_birth->diffInYears(now()), [25 => 'Under 25', 35 => '25–34', 45 => '35–44', 55 => '45–54'], '55 and over') : null],
            'date_of_birth' => ['label' => 'Date of birth', 'type' => 'date', 'sensitive' => true, 'value' => fn ($e) => $e->person?->date_of_birth],
            'birthday' => ['label' => 'Birthday (day and month)', 'type' => 'string', 'value' => fn ($e) => ($d = self::anniversary($e->person?->birthday_month, $e->person?->birthday_day, now()->year)) ? $d->format('d M') : null],
            'birthday_this_year' => ['label' => 'Birthday this year', 'type' => 'date', 'value' => fn ($e) => self::anniversary($e->person?->birthday_month, $e->person?->birthday_day, now()->year)],
            'next_birthday' => ['label' => 'Next birthday', 'type' => 'date', 'value' => fn ($e) => self::next($e->person?->birthday_month, $e->person?->birthday_day)],
            'marital_status' => ['label' => 'Marital status', 'type' => 'string', 'value' => fn ($e) => $e->person?->marital_status],
            'nationality' => ['label' => 'Nationality', 'type' => 'string', 'value' => fn ($e) => $e->person?->nationality],
            'blood_group' => ['label' => 'Blood group', 'type' => 'string', 'sensitive' => true, 'value' => fn ($e) => $e->person?->blood_group],
            'work_email' => ['label' => 'Work email', 'type' => 'string', 'value' => fn ($e) => $e->work_email],
            'work_phone' => ['label' => 'Work phone', 'type' => 'string', 'value' => fn ($e) => $e->work_phone],
            'personal_email' => ['label' => 'Personal email', 'type' => 'string', 'sensitive' => true, 'value' => fn ($e) => $e->person?->personal_email],
            'personal_phone' => ['label' => 'Personal phone', 'type' => 'string', 'sensitive' => true, 'value' => fn ($e) => $e->person?->personal_phone],
            'lifecycle_state' => ['label' => 'Lifecycle state', 'type' => 'string', 'value' => fn ($e) => $e->lifecycle_state],
            'source' => ['label' => 'Hire source', 'type' => 'string', 'value' => fn ($e) => $e->source],
            'expected_joining_date' => ['label' => 'Expected joining date', 'type' => 'date', 'value' => fn ($e) => $e->expected_joining_date],
            'joining_date' => ['label' => 'Joining date', 'type' => 'date', 'value' => fn ($e) => $e->joining_date],
            'joining_month' => ['label' => 'Joining month', 'type' => 'string', 'value' => fn ($e) => $e->joining_date?->format('Y-m')],
            'probation_end_date' => ['label' => 'Probation end date', 'type' => 'date', 'value' => fn ($e) => $e->probation_end_date],
            'confirmation_date' => ['label' => 'Confirmation date', 'type' => 'date', 'value' => fn ($e) => $e->confirmation_date],
            'exit_date' => ['label' => 'Exit date', 'type' => 'date', 'value' => fn ($e) => $e->exit_date],
            'tenure_months' => ['label' => 'Tenure (months)', 'type' => 'number', 'value' => fn ($e) => $e->joining_date ? (int) $e->joining_date->diffInMonths($e->exit_date ?? now()) : null],
            'years_of_service' => ['label' => 'Years of service', 'type' => 'number', 'value' => fn ($e) => $e->joining_date ? round($e->joining_date->diffInMonths($e->exit_date ?? now()) / 12, 1) : null],
            'tenure_band' => ['label' => 'Tenure band', 'type' => 'string', 'value' => fn ($e) => $e->joining_date ? self::band((int) $e->joining_date->diffInMonths($e->exit_date ?? now()), [12 => 'Under 1 year', 36 => '1–3 years', 60 => '3–5 years', 120 => '5–10 years'], '10 years and over') : null],
            'work_anniversary_this_year' => ['label' => 'Work anniversary this year', 'type' => 'date', 'value' => fn ($e) => $e->joining_date && $e->joining_date->year < now()->year ? self::anniversary($e->joining_date->month, $e->joining_date->day, now()->year) : null],
            'next_work_anniversary' => ['label' => 'Next work anniversary', 'type' => 'date', 'value' => fn ($e) => $e->joining_date ? self::next($e->joining_date->month, $e->joining_date->day, $e->joining_date) : null],
            'anniversary_years' => ['label' => 'Years completed this year', 'type' => 'number', 'value' => fn ($e) => $e->joining_date && $e->joining_date->year < now()->year ? now()->year - $e->joining_date->year : null],
            'completing_years' => ['label' => 'Years completed at next anniversary', 'type' => 'number', 'value' => fn ($e) => $e->joining_date ? self::next($e->joining_date->month, $e->joining_date->day, $e->joining_date)->year - $e->joining_date->year : null],
            'company' => ['label' => 'Company', 'type' => 'string', 'value' => fn ($e) => $pos($e)?->company?->name],
            'business_unit' => ['label' => 'Business unit', 'type' => 'string', 'value' => fn ($e) => $pos($e)?->businessUnit?->name],
            'division' => ['label' => 'Division', 'type' => 'string', 'value' => fn ($e) => $pos($e)?->division?->name],
            'location' => ['label' => 'Location', 'type' => 'string', 'value' => fn ($e) => $pos($e)?->location?->name],
            'department' => ['label' => 'Department', 'type' => 'string', 'value' => fn ($e) => $pos($e)?->department?->name],
            'team' => ['label' => 'Team', 'type' => 'string', 'value' => fn ($e) => $pos($e)?->team?->name],
            'designation' => ['label' => 'Designation', 'type' => 'string', 'value' => fn ($e) => $pos($e)?->designation?->name],
            'level' => ['label' => 'Level', 'type' => 'string', 'value' => fn ($e) => $pos($e)?->level?->name],
            'grade' => ['label' => 'Grade', 'type' => 'string', 'value' => fn ($e) => $pos($e)?->grade?->name],
            'employment_type' => ['label' => 'Employment type', 'type' => 'string', 'value' => fn ($e) => $pos($e)?->employmentType?->name],
            'employee_category' => ['label' => 'Employee category', 'type' => 'string', 'value' => fn ($e) => $pos($e)?->employeeCategory?->name],
            'work_mode' => ['label' => 'Work mode', 'type' => 'string', 'value' => fn ($e) => $pos($e)?->workMode?->name],
            'cost_centre' => ['label' => 'Cost centre', 'type' => 'string', 'value' => fn ($e) => $pos($e)?->costCentre?->name],
            'fte' => ['label' => 'FTE', 'type' => 'number', 'value' => fn ($e) => $pos($e)?->fte !== null ? (float) $pos($e)->fte : null],
            'manager' => ['label' => 'Manager', 'type' => 'string', 'value' => fn ($e) => $e->currentManager?->manager?->person?->full_name],
            'manager_code' => ['label' => 'Manager code', 'type' => 'string', 'value' => fn ($e) => $e->currentManager?->manager?->employee_code],
            'has_login' => ['label' => 'Has a login', 'type' => 'boolean', 'value' => fn ($e) => $e->user_id !== null],
            // Phase 11: approved compensation through the Compensation read contract (one query per report).
            'ctc_annual' => ['label' => 'Annual CTC', 'type' => 'number', 'sensitive' => true, 'permission' => 'compensation.view', 'value' => fn ($e) => ($this->ctc ??= app(CompensationOutput::class)->annualCtcOn())[$e->id] ?? null],
            'headcount' => ['label' => 'Headcount (1 per row)', 'type' => 'number', 'value' => fn ($e) => 1],
        ];
    }

    public function query(): Builder
    {
        return Employee::query()->with(['person', 'currentPosition.company', 'currentPosition.businessUnit', 'currentPosition.division', 'currentPosition.location', 'currentPosition.department', 'currentPosition.team', 'currentPosition.designation', 'currentPosition.level', 'currentPosition.grade', 'currentPosition.employmentType', 'currentPosition.employeeCategory', 'currentPosition.workMode', 'currentPosition.costCentre', 'currentManager.manager.person'])->orderBy('employee_code');
    }

    /** @param  array<int, string>  $bands  upper bound (exclusive) => label, ascending */
    private static function band(int $value, array $bands, string $last): string
    {
        foreach ($bands as $below => $label) {
            if ($value < $below) {
                return $label;
            }
        }

        return $last;
    }

    /** The day/month in $year; 29 February falls on the 28th in other years. */
    private static function anniversary(?int $month, ?int $day, int $year): ?Carbon
    {
        if (! $month || ! $day) {
            return null;
        }

        return Carbon::create($year, $month, min($day, Carbon::create($year, $month, 1)->daysInMonth))->startOfDay();
    }

    /** Next occurrence of the day/month on or after today (never before $from). */
    private static function next(?int $month, ?int $day, ?Carbon $from = null): ?Carbon
    {
        $date = self::anniversary($month, $day, now()->year);
        if ($date === null) {
            return null;
        }
        if ($date->lt(now()->startOfDay()) || ($from !== null && $date->lte($from))) {
            $date = self::anniversary($month, $day, $date->year + 1);
        }

        return $date;
    }
}
