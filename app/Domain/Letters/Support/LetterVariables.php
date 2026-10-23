<?php

namespace App\Domain\Letters\Support;

use App\Domain\Configuration\Services\CustomFields;
use App\Domain\Employment\Models\Employee;
use App\Domain\Payroll\Models\SalaryComponent;

/**
 * Every {{ variable }} a letter template may use, in one place: the variables guide (view, copy, download), the
 * editor's variable picker and the template check all read it, and LetterContext fills exactly these paths, so the
 * guide never drifts from what a letter gets. The fixed list is below; the tenant's salary components
 * (salary.<code>.monthly / annual / name) and employee custom fields (custom.<key>) are added per tenant.
 * Anything else in {{ }} is an extra value typed in when the letter is generated (e.g. {{ purpose }}).
 */
final class LetterVariables
{
    /** Variables that insert a block (a table, an image) rather than a line of text. */
    public const BLOCKS = ['salary.annexure', 'signatory.signature'];

    /** Names under salary.* used by the totals: a component with one of these codes has no own variables. */
    public const SALARY_RESERVED = ['annexure', 'ctc', 'gross', 'employer_contributions', 'variable', 'currency', 'effective_from', 'structure'];

    /** group => [title, what it covers, [variable => [meaning, example]]] */
    public const GROUPS = [
        'employee' => ['Employee: personal', 'From the employee’s personal details. Pronouns and salutation follow the recorded gender (they / them / their and Mx when none is recorded).', [
            'employee.name' => ['Full name', 'Asha Rao'],
            'employee.first_name' => ['First name', 'Asha'],
            'employee.middle_name' => ['Middle name', ''],
            'employee.last_name' => ['Last name', 'Rao'],
            'employee.preferred_name' => ['Preferred name (first name when none)', 'Asha'],
            'employee.salutation' => ['Mr / Ms / Mx', 'Ms'],
            'employee.he_she' => ['he / she / they', 'she'],
            'employee.him_her' => ['him / her / them', 'her'],
            'employee.his_her' => ['his / her / their', 'her'],
            'employee.gender' => ['Gender', 'Female'],
            'employee.date_of_birth' => ['Date of birth', '14 Feb 1994'],
            'employee.age' => ['Age in years', '32'],
            'employee.marital_status' => ['Marital status', 'Married'],
            'employee.nationality' => ['Nationality', 'Indian'],
            'employee.blood_group' => ['Blood group', 'B+'],
            'employee.father_name' => ['Father’s name (family details)', 'Ravi Rao'],
            'employee.mother_name' => ['Mother’s name (family details)', 'Meena Rao'],
            'employee.spouse_name' => ['Spouse’s name (family details)', 'Kiran Rao'],
            'employee.personal_email' => ['Personal e-mail', 'asha@example.com'],
            'employee.personal_phone' => ['Personal phone', '+91 98450 00000'],
        ]],
        'job' => ['Employee: job and organisation', 'From the employee’s current position, reporting line and company.', [
            'employee.code' => ['Employee code', 'EMP00042'],
            'employee.status' => ['Employment status', 'Confirmed'],
            'employee.work_email' => ['Work e-mail', 'asha.rao@company.com'],
            'employee.work_phone' => ['Work phone', '+91 80 4000 0000'],
            'employee.designation' => ['Designation', 'Senior Engineer'],
            'employee.department' => ['Department', 'Engineering'],
            'employee.business_unit' => ['Business unit', 'Products'],
            'employee.division' => ['Division', 'Platform'],
            'employee.team' => ['Team', 'Payments'],
            'employee.level' => ['Level', 'L3'],
            'employee.grade' => ['Grade', 'G4'],
            'employee.employment_type' => ['Employment type', 'Permanent'],
            'employee.employee_category' => ['Employee category', 'Staff'],
            'employee.work_mode' => ['Work mode', 'Hybrid'],
            'employee.cost_centre' => ['Cost centre', 'CC-ENG-01'],
            'employee.location' => ['Work location', 'Bengaluru HQ'],
            'location.address' => ['Work location address, on one line', '12 MG Road, Bengaluru, KA 560001, IN'],
            'location.city' => ['Work location city', 'Bengaluru'],
            'location.state' => ['Work location state code', 'KA'],
            'manager.name' => ['Reporting manager’s name', 'Vikram Shah'],
            'manager.code' => ['Reporting manager’s employee code', 'EMP00007'],
            'manager.designation' => ['Reporting manager’s designation', 'Engineering Manager'],
            'manager.email' => ['Reporting manager’s work e-mail', 'vikram.shah@company.com'],
            'company.name' => ['Company legal name', 'Acme Technologies Private Limited'],
            'company.short_name' => ['Company short name', 'Acme'],
            'company.code' => ['Company code', 'ACME'],
        ]],
        'dates' => ['Dates and service', 'Dates print as 14 Feb 2026.', [
            'employee.joining_date' => ['Date of joining', '01 Apr 2023'],
            'employee.probation_end_date' => ['End of probation', '30 Sep 2023'],
            'employee.confirmation_date' => ['Date of confirmation', '01 Oct 2023'],
            'employee.exit_date' => ['Last working day (exit date)', '31 Oct 2026'],
            'employee.last_working_day' => ['Same as exit_date', '31 Oct 2026'],
            'employee.service_period' => ['Length of service, to the exit date or today', '3 years 6 months'],
            'employee.years_of_service' => ['Years of service as a number', '3.5'],
        ]],
        'address' => ['Address', 'From the employee’s addresses. permanent works the same way as current.', [
            'address.current.full' => ['Current address on one line', '12 Lake View, Indiranagar, Bengaluru, KA 560038, IN'],
            'address.current.line_1' => ['Current address line 1', '12 Lake View'],
            'address.current.line_2' => ['Current address line 2', 'Indiranagar'],
            'address.current.city' => ['Current city', 'Bengaluru'],
            'address.current.state' => ['Current state code', 'KA'],
            'address.current.postal_code' => ['Current PIN / postal code', '560038'],
            'address.current.country' => ['Current country code', 'IN'],
            'address.permanent.full' => ['Permanent address on one line', '4 Temple Street, Udupi, KA 576101, IN'],
            'address.permanent.line_1' => ['Permanent address line 1', '4 Temple Street'],
            'address.permanent.line_2' => ['Permanent address line 2', ''],
            'address.permanent.city' => ['Permanent city', 'Udupi'],
            'address.permanent.state' => ['Permanent state code', 'KA'],
            'address.permanent.postal_code' => ['Permanent PIN / postal code', '576101'],
            'address.permanent.country' => ['Permanent country code', 'IN'],
        ]],
        'statutory' => ['Statutory and bank', 'Aadhaar and the bank account number are only ever printed masked.', [
            'statutory.pan' => ['PAN', 'ABCDE1234F'],
            'statutory.uan' => ['UAN', '100200300400'],
            'statutory.pf_number' => ['PF number', 'KA/BLR/0012345/000/0000042'],
            'statutory.esic_number' => ['ESIC number', '3100123456'],
            'statutory.aadhaar_masked' => ['Aadhaar, last four digits only', 'XXXX XXXX 1234'],
            'statutory.tax_regime' => ['Income-tax regime', 'new'],
            'bank.name' => ['Bank name (primary account)', 'HDFC Bank'],
            'bank.branch' => ['Bank branch', 'Koramangala'],
            'bank.ifsc' => ['IFSC', 'HDFC0000123'],
            'bank.account_holder' => ['Account holder name', 'Asha Rao'],
            'bank.account_masked' => ['Account number, last four digits only', 'XXXXXX7890'],
        ]],
        'salary' => ['Salary: monthly and yearly', 'From the approved compensation in force on the letter date (the exit date for a leaver). Amounts print as 50,000.00. Each salary component of your structures has its own variables, listed below.', [
            'salary.annexure' => ['The whole salary annexure: a table of every component, monthly and yearly, with gross, employer contributions and CTC. Put it on a line of its own', '(table)'],
            'salary.ctc.monthly' => ['Cost to company, monthly', '50,000.00'],
            'salary.ctc.annual' => ['Cost to company, yearly', '600,000.00'],
            'salary.ctc.monthly_words' => ['Monthly CTC in words', 'Fifty thousand rupees only'],
            'salary.ctc.annual_words' => ['Yearly CTC in words', 'Six lakh rupees only'],
            'salary.gross.monthly' => ['Gross salary (earnings), monthly', '45,600.00'],
            'salary.gross.annual' => ['Gross salary (earnings), yearly', '547,200.00'],
            'salary.employer_contributions.monthly' => ['Employer contributions (PF, gratuity, …), monthly', '4,400.00'],
            'salary.employer_contributions.annual' => ['Employer contributions, yearly', '52,800.00'],
            'salary.variable.monthly' => ['Variable pay target, a twelfth of the yearly target', '5,000.00'],
            'salary.variable.annual' => ['Variable pay target, yearly', '60,000.00'],
            'salary.currency' => ['Currency', 'INR'],
            'salary.effective_from' => ['Date this salary took effect', '01 Apr 2026'],
            'salary.structure' => ['Salary structure code', 'STANDARD'],
            'employee.ctc_annual' => ['Older name for salary.ctc.annual', '600,000.00'],
            'employee.ctc_monthly' => ['Older name for salary.ctc.monthly', '50,000.00'],
        ]],
        'letter' => ['Letter and signing authority', 'The signatory is the one chosen when the letter is generated, else the template’s, else the default signatory.', [
            'letter.number' => ['Letter reference number', 'LTR-2026-00042'],
            'letter.date' => ['Date of the letter', '22 Oct 2026'],
            'today' => ['Today’s date', '22 Oct 2026'],
            'signatory.name' => ['Signing authority’s name (Authorised Signatory when none is set up)', 'Neha Kapoor'],
            'signatory.designation' => ['Signing authority’s designation', 'Head of HR'],
            'signatory.signature' => ['Signing authority’s signature image', '(signature)'],
        ]],
    ];

    /**
     * The full catalogue for the current tenant: the fixed groups, the salary components and the custom fields.
     *
     * @return array<string, array{title: string, intro: string, variables: array<string, array{0: string, 1: string}>}>
     */
    public static function catalogue(): array
    {
        $groups = [];
        foreach (self::GROUPS as $key => [$title, $intro, $variables]) {
            $groups[$key] = ['title' => $title, 'intro' => $intro, 'variables' => $variables];
        }

        $components = [];
        foreach (self::components() as $code => $name) {
            $components["salary.{$code}.monthly"] = ["{$name}, monthly", '20,000.00'];
            $components["salary.{$code}.annual"] = ["{$name}, yearly", '240,000.00'];
            $components["salary.{$code}.name"] = ["Name of the component ({$name})", $name];
        }
        if ($components !== []) {
            $groups['components'] = ['title' => 'Salary components', 'intro' => 'One set per component of your salary structures. Empty when the employee’s structure does not have the component; statutory lines (PF, ESI, PT, TDS) are worked out in payroll, so only employer PF has an amount here.', 'variables' => $components];
        }

        $custom = [];
        foreach (self::customFields() as $key => $label) {
            $custom["custom.{$key}"] = [$label, ''];
        }
        if ($custom !== []) {
            $groups['custom'] = ['title' => 'Custom fields', 'intro' => 'Your organisation’s own employee fields.', 'variables' => $custom];
        }

        return $groups;
    }

    /** @return array<string, string> variable => label, for the editor's variable picker (the label is the variable itself) */
    public static function mergeTags(): array
    {
        $tags = [];
        foreach (self::catalogue() as $group) {
            foreach (array_keys($group['variables']) as $variable) {
                $tags[$variable] = $variable;
            }
        }

        return $tags;
    }

    /** @return list<string> */
    public static function known(): array
    {
        return array_keys(self::mergeTags());
    }

    /** A variable as it is written in a letter: {{ employee.name }}. */
    public static function tag(string $variable): string
    {
        return '{'.'{ '.$variable.' }'.'}';
    }

    /** @return list<string> the {{ variables }} written in a text, in order of first use */
    public static function usedIn(string $text): array
    {
        preg_match_all('/\{\{\s*([a-zA-Z0-9_.]+)\s*\}\}/', $text, $m);
        preg_match_all('/data-type="mergeTag"[^>]*data-id="([a-zA-Z0-9_.]+)"/', $text, $tags);

        return array_values(array_unique([...$m[1], ...$tags[1]]));
    }

    /**
     * Variables a template uses that are not in the catalogue: they print only when typed in at generation.
     *
     * @param  list<string>  $used
     * @return list<string>
     */
    public static function extras(array $used): array
    {
        $known = array_flip(self::known());

        return array_values(array_filter($used, fn (string $v) => ! isset($known[$v])));
    }

    /** @return array<string, string> lower-case component code => name, for codes the salary totals do not use */
    public static function components(): array
    {
        return SalaryComponent::query()->orderBy('name')->get(['code', 'name'])
            ->mapWithKeys(fn (SalaryComponent $c) => [strtolower((string) $c->code) => (string) $c->name])
            ->reject(fn (string $name, string $code) => in_array($code, self::SALARY_RESERVED, true) || ! preg_match('/^[a-z0-9_]+$/', $code))
            ->all();
    }

    /** @return array<string, string> custom field key => label */
    public static function customFields(): array
    {
        return app(CustomFields::class)->definitionsFor(Employee::class)
            ->filter(fn ($field) => preg_match('/^[a-zA-Z0-9_]+$/', (string) $field->key))
            ->mapWithKeys(fn ($field) => [(string) $field->key => (string) $field->label])->all();
    }
}
