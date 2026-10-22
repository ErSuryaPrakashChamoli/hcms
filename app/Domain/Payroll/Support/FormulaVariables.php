<?php

namespace App\Domain\Payroll\Support;

use App\Domain\Employment\Models\Employee;
use App\Domain\Employment\Models\EmployeePosition;
use App\Domain\Payroll\Models\SalaryComponent;
use App\Domain\Payroll\Services\FormulaEngine;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;

/**
 * What a salary formula can use (§30), in one place for the payroll calculator, the structure check,
 * the breakup preview and the in-app formula guide. Besides these, a formula uses the lowercase codes
 * of the components above it (basic, hra) and the parameters of its structure version (basic_pct).
 */
final class FormulaVariables
{
    /** Pay and period values: name => [description, example]. */
    public const PAY = [
        'ctc_annual' => ['Annual CTC on the employee’s approved compensation', 'ctc_annual / 12'],
        'ctc_monthly' => ['Annual CTC ÷ 12', 'ctc_monthly * 0.50'],
        'variable_target_annual' => ['Annual variable-pay target on the compensation (0 when none)', 'variable_target_annual / 12'],
        'days_in_period' => ['Calendar days in the payroll month', 'basic / days_in_period'],
        'paid_days' => ['Days paid this month, after joining / exit dates and loss of pay', 'if(paid_days >= 20, 1500, 0)'],
        'lop_days' => ['Loss-of-pay days this month', 'if(lop_days == 0, 2000, 0)'],
        'overtime_hours' => ['Approved overtime this month, in hours', 'overtime_hours * basic / 26 / 8 * 2'],
        'overtime_minutes' => ['Approved overtime this month, in minutes', 'overtime_minutes / 60 * 250'],
        'pf_employer' => ['Estimated employer PF on the PF wages of the components above, for balancing to CTC', 'max(0, ctc_monthly - basic - hra - pf_employer)'],
    ];

    /** Employee values on the last day paid in the month: name => [number|text, description, example]. */
    public const EMPLOYEE = [
        'grade' => ['text', 'Grade code', 'lookup(grade, "G1", 1600, "G2", 2400, 3200)'],
        'grade_rank' => ['number', 'Rank of the grade (0 when none)', 'if(grade_rank >= 5, 5000, 2500)'],
        'level' => ['text', 'Level code', 'if(level == "L3", 3000, 0)'],
        'company' => ['text', 'Company code', 'if(company == "DEMO", 1000, 0)'],
        'location' => ['text', 'Work location code', 'if(location == "BLR-HQ", 1500, 0)'],
        'work_city' => ['text', 'City of the work location', 'basic * if(in(work_city, "Mumbai", "Delhi", "Kolkata", "Chennai"), 0.50, 0.40)'],
        'work_state' => ['text', 'State code of the work location', 'if(work_state == "KA", 500, 0)'],
        'department' => ['text', 'Department code', 'if(department == "SALES", 2000, 0)'],
        'designation' => ['text', 'Designation code', 'if(designation == "DRIVER", 1200, 0)'],
        'employment_type' => ['text', 'Employment type code', 'if(employment_type == "INTERN", 0, 1600)'],
        'employee_category' => ['text', 'Employee category code', 'if(employee_category == "FIELD", 2500, 0)'],
        'years_of_service' => ['number', 'Years since joining, to the last day paid (e.g. 2.5)', 'slab(years_of_service, 2, 0, 5, 1000, 2000)'],
    ];

    /** Functions: name => [signature, what it does, example]. Every FormulaEngine function is listed. */
    public const FUNCTIONS = [
        'min' => ['min(a, b, …)', 'The smallest value: a cap', 'min(basic * 0.12, 1800)'],
        'max' => ['max(a, b, …)', 'The largest value: a floor. max(0, …) keeps a balancing component from going negative', 'max(0, ctc_monthly - basic - hra)'],
        'clamp' => ['clamp(x, low, high)', 'x kept between low and high', 'clamp(ctc_monthly * 0.50, 15000, 150000)'],
        'round' => ['round(x, places)', 'Rounded to decimal places; negative places round to tens (-1) or hundreds (-2)', 'round(ctc_monthly * 0.4, -2)'],
        'round_to' => ['round_to(x, step)', 'Rounded to the nearest multiple of step', 'round_to(ctc_monthly * 0.4, 50)'],
        'ceil_to' => ['ceil_to(x, step)', 'Rounded up to a multiple of step', 'ceil_to(basic * 0.5, 100)'],
        'floor_to' => ['floor_to(x, step)', 'Rounded down to a multiple of step', 'floor_to(basic * 0.5, 100)'],
        'floor' => ['floor(x)', 'Rounded down to a whole number', 'floor(ctc_monthly * 0.4)'],
        'ceil' => ['ceil(x)', 'Rounded up to a whole number', 'ceil(ctc_monthly * 0.4)'],
        'abs' => ['abs(x)', 'The value without its sign', 'abs(ctc_monthly - 50000)'],
        'if' => ['if(condition, then, otherwise)', 'One of two values', 'if(ctc_monthly > 50000, 3000, 1600)'],
        'in' => ['in(x, a, b, …)', '1 when x equals any of the values, else 0 (text ignores case)', 'if(in(grade, "M1", "M2"), 5000, 0)'],
        'between' => ['between(x, low, high)', '1 when low ≤ x ≤ high, else 0', 'if(between(years_of_service, 3, 5), 1000, 0)'],
        'lookup' => ['lookup(key, k1, v1, k2, v2, …, otherwise)', 'The value listed for key, e.g. an amount per grade. Without the final otherwise, an unlisted key stops payroll with an error', 'lookup(grade, "G1", 1500, "G2", 2500, "G3", 4000, 1000)'],
        'slab' => ['slab(x, up_to1, v1, up_to2, v2, …, above)', 'The value of the first band whose limit x does not exceed (limits in ascending order)', 'slab(ctc_monthly, 25000, 1000, 50000, 2000, 3500)'],
    ];

    /** Ready-made patterns for the guide: [what for, formula, where it goes]. */
    public const RECIPES = [
        ['Basic as a share of CTC, tunable per structure', 'ctc_monthly * basic_pct / 100', 'BASIC; parameter basic_pct = 50'],
        ['Basic with a minimum', 'max(ctc_monthly * basic_pct / 100, min_basic)', 'BASIC; parameters basic_pct, min_basic'],
        ['HRA: 50% of basic in metro cities, 40% elsewhere', 'basic * if(in(work_city, "Mumbai", "Delhi", "Kolkata", "Chennai"), 0.50, 0.40)', 'HRA, below BASIC'],
        ['An allowance per grade', 'lookup(grade, "G1", 1600, "G2", 2400, 3200)', 'any allowance'],
        ['An allowance by CTC band', 'slab(ctc_monthly, 25000, 1000, 50000, 2000, 3500)', 'any allowance'],
        ['Round to the nearest 50', 'round_to(ctc_monthly * 0.40, 50)', 'any component'],
        ['Service-based allowance', 'if(years_of_service >= 5, 1000, 0)', 'any allowance'],
        ['Monthly share of the variable-pay target', 'variable_target_annual / 12', 'a variable component'],
        ['Overtime at twice the hourly basic (26 days × 8 hours)', 'overtime_hours * basic / 26 / 8 * 2', 'OT, below BASIC; not prorated'],
        ['Balance to CTC (put it last)', 'max(0, ctc_monthly - basic - hra - conv - pf_employer)', 'SPECIAL, last in the structure'],
    ];

    /** Names a component code or structure parameter may not take. */
    public static function reserved(): array
    {
        return [...array_keys(self::PAY), ...array_keys(self::EMPLOYEE), ...array_keys(FormulaEngine::FUNCTIONS)];
    }

    /** Statutory codes as formulas would write them; statutory lines are never formula inputs. */
    public static function statutory(): array
    {
        return array_map('strtolower', SalaryComponent::STATUTORY_CODES);
    }

    /** The EMPLOYEE values for an employee on a date (text empty and numbers 0 when not recorded). */
    public static function employeeAttributes(Employee $employee, CarbonInterface $on): array
    {
        $position = EmployeePosition::query()->where('employee_id', $employee->id)->effectiveOn($on)
            ->orderByDesc('effective_from')->orderByDesc('id')
            ->with(['grade', 'level', 'company', 'location', 'department', 'designation', 'employmentType', 'employeeCategory'])
            ->first();
        $joined = $employee->joining_date?->copy()->startOfDay();
        $day = Carbon::parse($on)->startOfDay();

        return [
            'grade' => (string) $position?->grade?->code,
            'grade_rank' => (float) ($position?->grade?->rank ?? 0),
            'level' => (string) $position?->level?->code,
            'company' => (string) $position?->company?->code,
            'location' => (string) $position?->location?->code,
            'work_city' => (string) $position?->location?->city,
            'work_state' => (string) $position?->location?->state_code,
            'department' => (string) $position?->department?->code,
            'designation' => (string) $position?->designation?->code,
            'employment_type' => (string) $position?->employmentType?->code,
            'employee_category' => (string) $position?->employeeCategory?->code,
            'years_of_service' => $joined && $joined->lte($day) ? round($joined->diffInDays($day) / 365.25, 2) : 0.0,
        ];
    }
}
