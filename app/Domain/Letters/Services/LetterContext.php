<?php

namespace App\Domain\Letters\Services;

use App\Domain\Compensation\Contracts\CompensationOutput;
use App\Domain\Compensation\Support\CompensationComponentLine;
use App\Domain\Employment\Models\Employee;
use App\Domain\Letters\Models\LetterSignatory;
use App\Domain\Letters\Support\LetterVariables;
use App\Domain\Letters\Support\SalaryAnnexure;
use App\Domain\Letters\Support\SignatureImage;
use App\Domain\Payroll\Models\SalaryComponent;
use App\Domain\Payroll\Services\Payslips;
use App\Domain\Payroll\Services\StructureFormulas;
use App\Domain\Payroll\Support\FormulaVariables;
use App\Domain\People\Models\PersonAddress;
use Carbon\CarbonInterface;
use Illuminate\Support\Arr;
use Illuminate\Support\Carbon;
use NumberFormatter;

/**
 * The values behind every LetterVariables path for one employee. Salary comes from the CompensationOutput contract (the
 * approved compensation in force on the letter date, or the exit date for a leaver) and its breakup from the structure's
 * formulas, as the structure preview works it out for a full month: an annexure, not a payslip, so statutory
 * deductions are not shown and employer PF is the payroll estimate used for balancing to CTC.
 */
final class LetterContext
{
    public function __construct(private readonly CompensationOutput $compensation, private readonly StructureFormulas $formulas) {}

    /** @return array<string, mixed> */
    public function build(Employee $employee, array $extra = [], ?LetterSignatory $signatory = null): array
    {
        $employee->loadMissing(['person.addresses', 'person.familyMembers', 'statutoryDetail', 'bankAccounts', 'currentManager.manager.person', 'currentManager.manager.currentPosition.designation']);
        $relations = ['designation', 'department', 'company', 'location', 'businessUnit', 'division', 'team', 'level', 'grade', 'employmentType', 'employeeCategory', 'workMode', 'costCentre'];
        $position = $employee->currentPosition()->with($relations)->first()
            ?? $employee->positions()->with($relations)->first();
        $person = $employee->person;
        $salaryOn = Carbon::parse($employee->exit_date ?? now())->startOfDay();
        $salary = $this->salary($employee, $salaryOn);
        $until = $employee->exit_date && $employee->exit_date->lt(now()) ? $employee->exit_date : now();
        $gender = (string) $person?->gender;
        [$salutation, $he, $him, $his] = match ($gender) {
            'male' => ['Mr', 'he', 'him', 'his'],
            'female' => ['Ms', 'she', 'her', 'her'],
            default => ['Mx', 'they', 'them', 'their'],
        };
        $family = fn (string $relation) => $person?->familyMembers->firstWhere('relation', $relation)?->name;
        $manager = $employee->currentManager?->manager;
        $location = $position?->location;
        $statutory = $employee->statutoryDetail;
        $bank = $employee->bankAccounts->first();
        $signatory ??= LetterSignatory::default();
        $image = $signatory?->signatureImage();

        return array_replace_recursive([
            'employee' => [
                'name' => $person?->full_name, 'first_name' => $person?->first_name, 'middle_name' => $person?->middle_name, 'last_name' => $person?->last_name,
                'preferred_name' => $person?->preferred_name ?: $person?->first_name, 'salutation' => $salutation, 'he_she' => $he, 'him_her' => $him, 'his_her' => $his,
                'gender' => config("peopleos.people.genders.{$gender}"), 'date_of_birth' => $person?->date_of_birth, 'age' => $person?->date_of_birth?->age,
                'marital_status' => config('peopleos.people.marital_statuses.'.$person?->marital_status), 'nationality' => $person?->nationality, 'blood_group' => $person?->blood_group,
                'father_name' => $family('father'), 'mother_name' => $family('mother'), 'spouse_name' => $family('spouse'),
                'personal_email' => $person?->personal_email, 'personal_phone' => $person?->personal_phone,
                'code' => $employee->employee_code, 'status' => $employee->lifecycle_state?->getLabel(), 'work_email' => $employee->work_email, 'work_phone' => $employee->work_phone,
                'designation' => $position?->designation?->name, 'department' => $position?->department?->name, 'business_unit' => $position?->businessUnit?->name,
                'division' => $position?->division?->name, 'team' => $position?->team?->name, 'level' => $position?->level?->name, 'grade' => $position?->grade?->name,
                'employment_type' => $position?->employmentType?->name, 'employee_category' => $position?->employeeCategory?->name, 'work_mode' => $position?->workMode?->name,
                'cost_centre' => $position?->costCentre?->name, 'location' => $location?->name,
                'joining_date' => $employee->joining_date, 'probation_end_date' => $employee->probation_end_date, 'confirmation_date' => $employee->confirmation_date,
                'exit_date' => $employee->exit_date, 'last_working_day' => $employee->exit_date,
                'service_period' => $employee->joining_date ? self::period($employee->joining_date, $until) : null,
                'years_of_service' => $employee->joining_date ? round($employee->joining_date->diffInMonths($until) / 12, 1) : null,
                'ctc_annual' => $salary['ctc']['annual'] ?? null, 'ctc_monthly' => $salary['ctc']['monthly'] ?? null,
            ],
            'location' => ['address' => self::line([$location?->address_line_1, $location?->address_line_2, $location?->city, trim($location?->state_code.' '.$location?->postal_code), $location?->country_code]),
                'city' => $location?->city, 'state' => $location?->state_code],
            'manager' => ['name' => $manager?->person?->full_name, 'code' => $manager?->employee_code, 'designation' => $manager?->currentPosition?->designation?->name, 'email' => $manager?->work_email],
            'company' => ['name' => $position?->company?->legal_name ?: $position?->company?->name, 'short_name' => $position?->company?->name, 'code' => $position?->company?->code],
            'address' => ['current' => self::address($person?->addresses, 'current'), 'permanent' => self::address($person?->addresses, 'permanent')],
            'statutory' => [
                'pan' => $statutory?->pan, 'uan' => $statutory?->uan, 'pf_number' => $statutory?->pf_number, 'esic_number' => $statutory?->esic_number,
                'aadhaar_masked' => filled($statutory?->aadhaar_reference) ? 'XXXX XXXX '.substr(preg_replace('/\D/', '', (string) $statutory->aadhaar_reference), -4) : null,
                'tax_regime' => $statutory?->tax_regime,
            ],
            'bank' => [
                'name' => $bank?->bank_name, 'branch' => $bank?->branch_name, 'ifsc' => $bank?->ifsc, 'account_holder' => $bank?->account_holder_name,
                'account_masked' => filled($bank?->account_number) ? str_repeat('X', max(0, strlen((string) $bank->account_number) - 4)).substr((string) $bank->account_number, -4) : null,
            ],
            'salary' => $salary,
            'custom' => array_intersect_key($employee->customFields(), LetterVariables::customFields()),
            'signatory' => ['name' => $signatory?->name ?? 'Authorised Signatory', 'designation' => $signatory?->designation,
                'signature' => $image ? new SignatureImage($image['bytes'], $image['mime'], $image['width'], $image['height']) : null],
            'today' => now(),
            'letter' => ['number' => null, 'date' => now()],
        ], $extra);
    }

    /**
     * What a letter keeps of its context: the variables its template used, as printed (dates as Y-m-d, the annexure as
     * rows, never the signature image), plus the company and letter details the issued page shows.
     *
     * @param  list<string>  $used
     * @return array<string, mixed>
     */
    public static function snapshot(array $context, array $used): array
    {
        $values = ['company' => $context['company'] ?? [], 'letter' => $context['letter'] ?? []];
        foreach ($used as $path) {
            if (Arr::has($context, $path)) {
                Arr::set($values, $path, Arr::get($context, $path));
            }
        }

        $plain = function (mixed $value) use (&$plain): mixed {
            return match (true) {
                $value instanceof \DateTimeInterface => $value->format('Y-m-d'),
                $value instanceof SalaryAnnexure => $value->toArray(),
                $value instanceof SignatureImage => null,
                is_array($value) => array_map($plain, $value),
                default => $value,
            };
        };

        return $plain($values);
    }

    /** @return array<string, mixed> the salary.* subtree; empty when no approved compensation is in force */
    private function salary(Employee $employee, CarbonInterface $on): array
    {
        $segment = $this->compensation->forPayroll($employee, $on, $on)->last();
        if ($segment === null) {
            return [];
        }
        $pay = $segment->compensation;
        $lines = $segment->components->map(fn (CompensationComponentLine $line) => ['component' => $line->component, 'formula_override' => $line->formulaOverride, 'sort_order' => $line->sortOrder])->all();
        $preview = $lines === [] ? null : $this->formulas->preview($lines, $segment->parameters, [
            'ctc_annual' => $pay->ctcAnnual, 'variable_target_annual' => $pay->variableTargetAnnual, 'values' => $pay->componentValues,
            'attributes' => FormulaVariables::employeeAttributes($employee, $on), 'days' => 30,
            'pf_applies' => $employee->statutoryDetail?->pf_applicable ?? true, 'pf_restrict_to_ceiling' => true, 'on' => $on,
        ]);
        $byCode = collect($lines)->mapWithKeys(fn (array $line) => [$line['component']->code => $line['component']]);
        $pfEmployer = ($preview['totals']['pf_in_ctc'] ?? false) ? (float) $preview['totals']['pf_employer'] : 0.0;

        $components = [];
        $earnings = [];
        $employer = [];
        foreach ($preview['rows'] ?? [] as $row) {
            /** @var SalaryComponent $component */
            $component = $byCode[$row['code']];
            $monthly = $row['monthly'] ?? ($row['code'] === 'PF_ER' && $pfEmployer > 0 ? $pfEmployer : null);
            $components[strtolower($row['code'])] = ['name' => $row['name'], 'monthly' => self::amount($monthly), 'annual' => self::amount($monthly === null ? null : $monthly * 12)];
            if (! $monthly) {
                continue;
            }
            $line = ['kind' => 'line', 'label' => $row['name'], 'monthly' => $monthly, 'annual' => round($monthly * 12, 2)];
            if ($component->isEarning() || ($component->type === 'reimbursement' && $component->include_in_ctc)) {
                $earnings[] = $line;
            } elseif ($component->type === 'employer_contribution' && $component->include_in_ctc) {
                $employer[] = $line;
                $pfEmployer = $row['code'] === 'PF_ER' ? 0.0 : $pfEmployer;
            }
        }
        if ($pfEmployer > 0) {
            $employer[] = ['kind' => 'line', 'label' => 'Employer PF', 'monthly' => $pfEmployer, 'annual' => round($pfEmployer * 12, 2)];
        }

        $gross = round(array_sum(array_column($earnings, 'monthly')), 2);
        $employerTotal = round(array_sum(array_column($employer, 'monthly')), 2);
        $ctcMonthly = $pay->monthlyCtc();
        $rows = [
            ...($earnings ? [['kind' => 'heading', 'label' => 'Earnings', 'monthly' => null, 'annual' => null], ...$earnings, ['kind' => 'total', 'label' => 'Gross salary', 'monthly' => $gross, 'annual' => round($gross * 12, 2)]] : []),
            ...($employer ? [['kind' => 'heading', 'label' => 'Employer contributions', 'monthly' => null, 'annual' => null], ...$employer, ['kind' => 'total', 'label' => 'Total employer contributions', 'monthly' => $employerTotal, 'annual' => round($employerTotal * 12, 2)]] : []),
            ['kind' => 'total', 'label' => 'Cost to company (CTC)', 'monthly' => $ctcMonthly, 'annual' => round($pay->ctcAnnual, 2)],
        ];
        if ($pay->variableTargetAnnual) {
            $rows[] = ['kind' => 'line', 'label' => 'Variable pay (yearly target, paid as per policy)', 'monthly' => null, 'annual' => round($pay->variableTargetAnnual, 2)];
        }

        return [
            ...array_diff_key($components, array_flip(LetterVariables::SALARY_RESERVED)),
            'annexure' => new SalaryAnnexure($pay->currency, $rows),
            'ctc' => ['monthly' => self::amount($ctcMonthly), 'annual' => self::amount($pay->ctcAnnual),
                'monthly_words' => self::words($ctcMonthly, $pay->currency), 'annual_words' => self::words($pay->ctcAnnual, $pay->currency)],
            'gross' => ['monthly' => $earnings ? self::amount($gross) : null, 'annual' => $earnings ? self::amount($gross * 12) : null],
            'employer_contributions' => ['monthly' => $employer ? self::amount($employerTotal) : null, 'annual' => $employer ? self::amount($employerTotal * 12) : null],
            'variable' => ['monthly' => $pay->variableTargetAnnual ? self::amount($pay->variableTargetAnnual / 12) : null, 'annual' => $pay->variableTargetAnnual ? self::amount($pay->variableTargetAnnual) : null],
            'currency' => $pay->currency,
            'effective_from' => $pay->effectiveFrom,
            'structure' => $pay->structureCode,
        ];
    }

    private static function amount(?float $value): ?string
    {
        return $value === null ? null : number_format(round($value, 2), 2);
    }

    private static function words(float $amount, string $currency): string
    {
        $whole = (int) round($amount);
        if (strtoupper($currency) === 'INR') {
            return Payslips::inWords($whole).' rupees only';
        }

        return ucfirst((string) (new NumberFormatter('en', NumberFormatter::SPELLOUT))->format($whole)).' '.strtoupper($currency).' only';
    }

    private static function period(CarbonInterface $from, CarbonInterface $to): string
    {
        $diff = $from->diff($to);
        $parts = array_filter([$diff->y ? $diff->y.' '.str('year')->plural($diff->y) : null, $diff->m ? $diff->m.' '.str('month')->plural($diff->m) : null]);

        return $parts ? implode(' ', $parts) : 'less than a month';
    }

    /** @return array<string, ?string> */
    private static function address(mixed $addresses, string $type): array
    {
        /** @var PersonAddress|null $address */
        $address = collect($addresses)->where('type', $type)
            ->filter(fn (PersonAddress $a) => $a->effective_to === null || $a->effective_to->gte(today()))
            ->sortByDesc(fn (PersonAddress $a) => $a->effective_from?->timestamp ?? 0)->first();

        return [
            'full' => $address ? self::line([$address->address_line_1, $address->address_line_2, $address->city, trim($address->state_code.' '.$address->postal_code), $address->country_code]) : null,
            'line_1' => $address?->address_line_1, 'line_2' => $address?->address_line_2, 'city' => $address?->city,
            'state' => $address?->state_code, 'postal_code' => $address?->postal_code, 'country' => $address?->country_code,
        ];
    }

    /** @param  list<?string>  $parts */
    private static function line(array $parts): ?string
    {
        $parts = array_filter(array_map(fn ($p) => trim((string) $p), $parts), 'strlen');

        return $parts ? implode(', ', $parts) : null;
    }
}
