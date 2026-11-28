<?php

namespace App\Domain\Experience\Services;

use App\Domain\Configuration\Models\CustomField;
use App\Domain\Configuration\Services\CustomFields;
use App\Domain\Configuration\Services\OptionLists;
use App\Domain\Employment\Models\Employee;
use App\Domain\Employment\Models\EmployeeBankAccount;
use App\Domain\Employment\Models\EmployeeStatutoryDetail;
use App\Domain\Employment\Models\ReportingRelationship;
use App\Domain\Identity\Models\User;
use App\Domain\Payroll\Models\EmployeePaymentMode;
use App\Domain\Payroll\Services\PaymentModes;
use App\Domain\People\Models\InsurancePolicy;
use App\Domain\People\Models\Person;
use App\Domain\People\Models\PersonAddress;
use App\Domain\People\Models\PersonEmergencyContact;
use App\Domain\People\Models\PersonExperience;
use App\Domain\People\Models\PersonFamilyMember;
use App\Domain\People\Models\PersonIdentityDocument;
use App\Domain\People\Models\PersonNomination;
use App\Domain\People\Models\PersonQualification;
use App\Domain\People\Services\InsuranceRecords;
use App\Domain\Platform\Services\SettingsRepository;
use App\Support\Geography\Countries;
use Carbon\CarbonInterface;
use Throwable;

/**
 * The Employee 360 "Profile" view (owner, 2026-11-27): every detail of one employee in one place, by area (personal,
 * contact and addresses, employment, statutory, government IDs, bank, family, education and experience, health
 * insurance, other details), read-only. Nothing here writes; the page puts an Edit beside each area.
 *
 * Every area follows the rule that already governs it on the record tabs: the record itself (`view`), the CA-6 profile
 * section matrix (ProfileSectionVisibility) and, for statutory numbers, government IDs, bank accounts and health
 * insurance, `viewSensitive`. Sensitive numbers are masked (last four) unless the page has revealed that area, which the
 * page does only after a stated purpose that it audits (SensitiveAccessAuditor).
 *
 * Key details (the ones an employee record needs: name, date of birth, gender, contact, addresses, placement, manager,
 * PAN, Aadhaar, UAN when PF applies, bank account when paid by transfer, …) are flagged, and an empty one is listed as
 * missing so HR can see at a glance what still has to be filled in.
 */
final class EmployeeProfileDetails
{
    /** Areas whose numbers can be revealed (area key => what is revealed). */
    public const REVEALABLE = ['statutory' => 'statutory numbers', 'identity' => 'government ID numbers', 'bank' => 'bank account numbers'];

    public function __construct(private readonly ProfileSectionVisibility $matrix) {}

    /**
     * @param  list<string>  $revealed  areas whose numbers are shown in full (statutory, identity, bank)
     * @return list<array{key: string, label: string, icon: string, sub: string, facts: list<array{label: string, value: ?string, key: bool}>, lists: list<array{title: string, columns: list<string>, rows: list<list<?string>>, empty: string}>, missing: list<string>, revealable: bool, revealed: bool, note: ?string}>
     */
    public function for(User $viewer, Employee $employee, array $revealed = []): array
    {
        if (! $viewer->can('view', $employee)) {
            return [];
        }
        $employee->loadMissing(['person', 'user', 'currentPosition.company', 'currentPosition.businessUnit', 'currentPosition.division', 'currentPosition.department',
            'currentPosition.team', 'currentPosition.location', 'currentPosition.designation', 'currentPosition.level', 'currentPosition.grade',
            'currentPosition.employmentType', 'currentPosition.employeeCategory', 'currentPosition.workMode', 'currentPosition.costCentre']);
        $sensitive = $viewer->can('viewSensitive', $employee);
        $allows = fn (string $section) => $this->matrix->allows($viewer, $employee, $section);

        $areas = [
            $this->personal($employee, $allows('personal'), $allows('family')),
            $allows('personal') ? $this->contact($employee) : null,
            $this->employment($employee),
            $sensitive ? $this->statutory($employee, in_array('statutory', $revealed, true)) : null,
            $sensitive && $allows('personal') ? $this->identity($employee, in_array('identity', $revealed, true)) : null,
            $sensitive && $allows('bank') ? $this->bank($employee, in_array('bank', $revealed, true)) : null,
            $allows('family') ? $this->family($employee) : null,
            $allows('professional') ? $this->professional($employee) : null,
            $sensitive && $allows('health_insurance') ? $this->safe(fn () => $this->insurance($employee)) : null,
            $allows('other_details') ? $this->safe(fn () => $this->otherDetails($employee)) : null,
        ];

        return array_values(array_filter($areas));
    }

    /** @return array<string, mixed> */
    private function personal(Employee $employee, bool $personalSection, bool $familySection): array
    {
        $person = $employee->person;
        $gender = $person?->gender;
        $marital = $person?->marital_status;
        $dob = $person?->date_of_birth;

        return $this->area('personal', 'Personal', 'heroicon-o-user', 'Name, date of birth and personal particulars.', [
            $this->fact('Salutation', $person?->salutationLabel()),
            $this->fact('First name', $person?->first_name, key: true),
            $this->fact('Middle name', $person?->middle_name),
            $this->fact('Last name', $person?->last_name, key: true),
            $this->fact('Preferred name', $person?->preferred_name),
            $this->fact('Date of birth', $dob ? $this->date($dob).' (age '.(int) $dob->diffInYears(today()).')' : null, key: true),
            $this->fact('Birthday celebrated', $person?->celebratesOtherDay() ? $person->birthdayLabel() : null, situational: true),
            // Whether colleagues see the day (it was only in the 360's former "All details").
            $this->fact('Birthday shown to colleagues', $person === null ? null : ($person->birthday_shared ? 'Yes' : 'No')),
            $this->fact('Gender', $gender ? (string) config("peopleos.people.genders.{$gender}", $gender) : null, key: true),
            ...($familySection ? [
                $this->fact('Marital status', $marital ? (string) config("peopleos.people.marital_statuses.{$marital}", $marital) : null, key: true),
                $this->fact('Wedding anniversary', ($label = $person?->weddingAnniversaryLabel()) === null ? null : $label.($person->wedding_anniversary_shared ? ' · shown to colleagues' : ' · private'), situational: $marital !== 'married'),
            ] : []),
            ...($personalSection ? [
                $this->fact('Nationality', $person?->nationality, key: true),
                $this->fact('Blood group', $person?->blood_group),
            ] : []),
        ]);
    }

    /** @return array<string, mixed> */
    private function contact(Employee $employee): array
    {
        $person = $employee->person;
        $types = (array) config('peopleos.people.address_types', []);
        $addresses = PersonAddress::query()->where('person_id', $employee->person_id)->currentlyEffective()->orderBy('type')->get();
        $address = fn (PersonAddress $a) => collect([$a->address_line_1, $a->address_line_2, $a->city, $this->stateName($a->state_code), $a->postal_code,
            $a->country_code && $a->country_code !== 'IN' ? Countries::name($a->country_code) : null])->filter()->implode(', ');
        $of = fn (string $type) => ($a = $addresses->firstWhere('type', $type)) ? $address($a) : null;
        $contacts = PersonEmergencyContact::query()->where('person_id', $employee->person_id)->orderBy('priority')->orderBy('id')->get();

        $area = $this->area('contact', 'Contact & addresses', 'heroicon-o-map-pin', 'How to reach this person, where they live and whom to call in an emergency.', [
            $this->fact('Work email', $employee->work_email),
            $this->fact('Work phone', $this->phone($employee->work_phone_country_code, $employee->work_phone)),
            $this->fact('Personal email', $person?->personal_email, key: true),
            $this->fact('Personal phone', $this->phone($person?->personal_phone_country_code, $person?->personal_phone), key: true),
            $this->fact('Present address', $of('current'), key: true),
            $this->fact('Permanent address', $of('permanent'), key: true),
            ...$addresses->reject(fn (PersonAddress $a) => in_array($a->type, ['current', 'permanent'], true))
                ->map(fn (PersonAddress $a) => $this->fact((string) ($types[$a->type] ?? ucfirst((string) $a->type)).' address', $address($a), situational: true))->values()->all(),
        ], [
            $this->table('Emergency contacts', ['Name', 'Relation', 'Phone', 'E-mail'], $contacts->map(fn (PersonEmergencyContact $c) => [
                $c->name, $c->relation ? ucfirst((string) $c->relation) : null,
                collect([$this->phone($c->phone_country_code, $c->phone), $c->alternate_phone])->filter()->implode(' / ') ?: null, $c->email,
            ])->all(), 'No emergency contact recorded.'),
        ]);
        if ($contacts->isEmpty()) {
            $area['missing'][] = 'Emergency contact';
        }

        return $area;
    }

    /** @return array<string, mixed> */
    private function employment(Employee $employee): array
    {
        $p = $employee->currentPosition;
        $managers = ReportingRelationship::query()->with('manager.person')->where('employee_id', $employee->getKey())
            ->whereIn('type', ['line', 'functional', 'hrbp'])->currentlyEffective()->get()->keyBy('type');
        $manager = fn (string $type) => $managers->get($type)?->manager?->person?->display_name;
        $state = $employee->lifecycle_state;

        return $this->area('employment', 'Employment', 'heroicon-o-briefcase', 'Employee code, dates, position and reporting as they stand today.', [
            $this->fact('Employee code', $employee->employee_code, key: true),
            $this->fact('Status', $state?->getLabel()),
            $this->fact('Date of joining', $this->date($employee->joining_date), key: true),
            $this->fact('Expected joining date', $employee->joining_date === null ? $this->date($employee->expected_joining_date) : null, situational: true),
            $this->fact('Probation ends', $this->date($employee->probation_end_date)),
            $this->fact('Confirmed on', $this->date($employee->confirmation_date) ?? 'Not confirmed yet'),
            $this->fact('Exit date', $this->date($employee->exit_date), situational: true),
            $this->fact('Company', $p?->company?->name, key: true),
            $this->fact('Business unit', $p?->businessUnit?->name),
            $this->fact('Division', $p?->division?->name),
            $this->fact('Department', $p?->department?->name, key: true),
            $this->fact('Team', $p?->team?->name),
            $this->fact('Designation', $p?->designation?->name, key: true),
            $this->fact('Location', $p?->location?->name, key: true),
            $this->fact('Grade', $p?->grade?->name),
            $this->fact('Level', $p?->level?->name),
            $this->fact('Employment type', $p?->employmentType?->name, key: true),
            $this->fact('Employee category', $p?->employeeCategory?->name),
            $this->fact('Work mode', $p?->workMode?->name),
            $this->fact('Cost centre', $p?->costCentre?->name),
            $this->fact('In this role since', $this->date($p?->effective_from)),
            $this->fact('Reporting manager', $manager('line'), key: true),
            $this->fact('Functional manager', $manager('functional')),
            $this->fact('HR business partner', $manager('hrbp')),
            $this->fact('Biometric / device id', $employee->getAttributes()['device_user_id'] ?? null),
            $this->fact('Sign-in', $employee->user?->email),
            $this->fact('Source', filled($employee->source) ? ucfirst(str_replace('_', ' ', (string) $employee->source)) : null, situational: true),
        ]);
    }

    /** @return array<string, mixed> */
    private function statutory(Employee $employee, bool $revealed): array
    {
        $detail = EmployeeStatutoryDetail::query()->where('employee_id', $employee->getKey())->first();
        $number = fn (?string $value) => blank($value) ? null : ($revealed ? (string) $value : EmployeeStatutoryDetail::mask((string) $value));
        $yesNo = fn (?bool $value) => $detail === null ? null : ($value ? 'Yes' : 'No');
        $pf = (bool) ($detail?->pf_applicable ?? false);
        $esic = (bool) ($detail?->esic_applicable ?? false);
        $pt = (bool) ($detail?->pt_applicable ?? false);

        $area = $this->area('statutory', 'Statutory', 'heroicon-o-building-library', 'PAN, Aadhaar, provident fund, ESIC, professional tax and the tax regime.', [
            $this->fact('PAN', $number($detail?->pan), key: true),
            $this->fact('Aadhaar', $number($detail?->aadhaar_reference), key: true),
            $this->fact('PF applicable', $yesNo($detail?->pf_applicable)),
            $this->fact('UAN', $number($detail?->uan), key: $pf),
            $this->fact('PF number', $number($detail?->pf_number)),
            $this->fact('ESIC applicable', $yesNo($detail?->esic_applicable)),
            $this->fact('ESIC number', $number($detail?->esic_number), key: $esic),
            $this->fact('Professional tax applicable', $yesNo($detail?->pt_applicable)),
            $this->fact('Professional tax state', $this->stateName($detail?->pt_state_code), key: $pt),
            $this->fact('Tax regime', $detail?->tax_regime ? ucfirst((string) $detail->tax_regime).' regime' : null, key: true),
        ]);
        $area['revealable'] = $detail !== null && collect(['pan', 'aadhaar_reference', 'uan', 'pf_number', 'esic_number'])->contains(fn ($c) => filled($detail->getAttribute($c)));
        $area['revealed'] = $revealed;

        return $area;
    }

    /** @return array<string, mixed> */
    private function identity(Employee $employee, bool $revealed): array
    {
        $documents = PersonIdentityDocument::query()->where('person_id', $employee->person_id)->orderBy('kind')->get();

        $area = $this->area('identity', 'Government IDs', 'heroicon-o-identification', 'Passport, driving licence, voter ID and other government IDs.', [], [
            $this->table('Government IDs', ['ID', 'Number', 'Issued', 'Valid until', 'Issued in'], $documents->map(fn (PersonIdentityDocument $d) => [
                $d->kindLabel(), $revealed ? (string) $d->number : $d->maskedNumber(), $this->date($d->issued_on),
                $d->expires_on ? $this->date($d->expires_on).($d->expires_on->lt(today()) ? ' (expired)' : '') : null,
                collect([$d->place_of_issue, $d->issuing_country ? Countries::name($d->issuing_country) : null])->filter()->implode(', ') ?: null,
            ])->all(), 'No government ID recorded. PAN and Aadhaar are under Statutory.'),
        ]);
        $area['revealable'] = $documents->isNotEmpty();
        $area['revealed'] = $revealed;

        return $area;
    }

    /** @return array<string, mixed> */
    private function bank(Employee $employee, bool $revealed): array
    {
        $mode = $this->safe(fn () => app(PaymentModes::class)->for((int) $employee->getKey(), now()));
        $salaryMode = $mode?->salary_mode ?? 'online_transfer';
        // HROne setup parity BP-13: the IFSC and branch follow payroll.bank.show_ifsc_branch, as on the Bank tab.
        $ifscBranch = (bool) app(SettingsRepository::class)->get('payroll.bank.show_ifsc_branch', true);
        $accounts = EmployeeBankAccount::query()->where('employee_id', $employee->getKey())->orderByDesc('is_primary')->orderBy('id')->get();

        $area = $this->area('bank', 'Bank & payment', 'heroicon-o-banknotes', 'How salary is paid and the accounts it is paid into.', [
            $this->fact('Salary paid by', EmployeePaymentMode::MODES[$salaryMode] ?? $salaryMode),
            $this->fact('Reimbursements paid by', $mode?->reimbursement_mode ? (EmployeePaymentMode::MODES[$mode->reimbursement_mode] ?? $mode->reimbursement_mode) : 'As salary'),
        ], [
            $this->table('Bank accounts', ['Bank', 'Account holder', 'Account number', ...($ifscBranch ? ['IFSC', 'Branch'] : []), 'Type', 'Primary', 'Verified'],
                $accounts->map(fn (EmployeeBankAccount $a) => [
                    $a->bank_name, $a->account_holder_name, $revealed ? (string) $a->account_number : $a->maskedAccountNumber(),
                    ...($ifscBranch ? [$a->ifsc, $a->branch_name] : []),
                    $a->account_type ? ucfirst((string) $a->account_type) : null, $a->is_primary ? 'Yes' : 'No', $a->verified_at ? $this->date($a->verified_at) : 'Not yet',
                ])->all(), 'No bank account recorded.'),
        ]);
        if ($accounts->isEmpty() && $salaryMode === 'online_transfer') {
            $area['missing'][] = 'Salary bank account';
        }
        $area['revealable'] = $accounts->isNotEmpty();
        $area['revealed'] = $revealed;

        return $area;
    }

    /** @return array<string, mixed> */
    private function family(Employee $employee): array
    {
        $lists = app(OptionLists::class);
        $members = PersonFamilyMember::query()->where('person_id', $employee->person_id)->orderBy('id')->get();
        $nominations = PersonNomination::query()->with('familyMember')->where('person_id', $employee->person_id)->orderBy('benefit')->orderBy('id')->get();
        $share = fn ($value) => $value === null ? null : rtrim(rtrim((string) $value, '0'), '.').'%';

        return $this->area('family', 'Family & nominees', 'heroicon-o-users', 'Family members, dependants and nominations for each benefit.', [], [
            $this->table('Family members', ['Name', 'Relation', 'Date of birth', 'Phone', 'Dependant', 'Nominee', 'Notes'], $members->map(fn (PersonFamilyMember $m) => [
                $m->name, $lists->label('family_relations', $m->relation), $this->date($m->date_of_birth), $m->phone, $m->is_dependent ? 'Yes' : 'No',
                $m->is_nominee ? ($share($m->nominee_share) ?? 'Yes') : 'No',
                collect([$m->occupation, $m->in_school ? 'In school' : null, $m->in_hostel ? 'In a hostel' : null, $m->remarks])->filter()->implode(' · ') ?: null,
            ])->all(), 'No family member recorded.'),
            $this->table('Nominations', ['Benefit', 'Nominee', 'Share', 'Guardian'], $nominations->map(fn (PersonNomination $n) => [
                $n->benefitLabel(), $n->familyMember?->name, $share($n->share),
                $n->guardian_name ? $n->guardian_name.($n->guardian_relation ? ' ('.$n->guardian_relation.')' : '') : null,
            ])->all(), 'No nomination recorded.'),
        ]);
    }

    /** @return array<string, mixed> */
    private function professional(Employee $employee): array
    {
        $lists = app(OptionLists::class);
        $education = PersonQualification::query()->where('person_id', $employee->person_id)->orderByDesc('is_highest')->orderByDesc('year_of_completion')->get();
        $experience = PersonExperience::query()->where('person_id', $employee->person_id)->orderByDesc('from_date')->get();
        $fresher = (bool) Person::query()->whereKey($employee->person_id)->value('is_fresher');
        $months = (int) $experience->sum(fn (PersonExperience $x) => $x->from_date ? (int) $x->from_date->diffInMonths($x->to_date ?? now()) : 0);

        return $this->area('professional', 'Education & experience', 'heroicon-o-academic-cap', 'Qualifications and earlier employment.', [
            $this->fact('Highest qualification', ($q = $education->firstWhere('is_highest', true) ?? $education->first()) ? collect([$q->qualification, $q->specialisation])->filter()->implode(', ') : null),
            $this->fact('Earlier experience', $fresher ? 'None (fresher)' : ($months > 0 ? $this->duration($months) : null)),
        ], [
            $this->table('Education', ['Qualification', 'Specialisation', 'Institution', 'Board / university', 'Years', 'Grade', 'Mode'], $education->map(fn (PersonQualification $q) => [
                $q->qualification.($q->is_highest ? ' (highest)' : ''), $q->specialisation, $q->institution, $q->board_or_university,
                $q->year_of_completion ? ($q->year_started ? $q->year_started.' – ' : '').$q->year_of_completion : ($q->year_started ? 'from '.$q->year_started : null),
                $q->grade_or_score, $q->mode ? $lists->label('qualification_modes', $q->mode) : null,
            ])->all(), 'No qualification recorded.'),
            $this->table('Previous employment', ['Employer', 'Designation', 'From', 'To', 'Location', 'Reason for leaving'], $experience->map(fn (PersonExperience $x) => [
                $x->employer.($x->is_verified ? ' (verified)' : ''), $x->designation, $x->from_date?->format('M Y'), $x->to_date?->format('M Y') ?? 'Present', $x->location, $x->reason_for_leaving,
            ])->all(), $fresher ? 'None: this person is a fresher.' : 'No previous employment recorded.'),
        ]);
    }

    /** @return array<string, mixed>|null */
    private function insurance(Employee $employee): ?array
    {
        $policies = InsuranceRecords::policiesOf($employee);
        if ($policies->isEmpty()) {
            return null;
        }

        return $this->area('insurance', 'Health insurance', 'heroicon-o-heart', 'Policies on file and the people they cover.', [], [
            $this->table('Policies', ['Insurer', 'Policy number', 'Sum assured', 'Issued', 'Valid until', 'Covers'], $policies->map(fn (InsurancePolicy $p) => [
                $p->insurer_name, $p->policy_number, $p->sum_assured === null ? null : number_format((float) $p->sum_assured, 2), $this->date($p->issued_on),
                $p->valid_until ? $this->date($p->valid_until).($p->valid_until->lt(today()) ? ' (expired)' : '') : null,
                $p->members->map(fn ($m) => $m->name.' ('.$m->relationLabel().')')->implode(', ') ?: null,
            ])->all(), 'No policy recorded.'),
        ]);
    }

    /** @return array<string, mixed>|null */
    private function otherDetails(Employee $employee): ?array
    {
        $custom = app(CustomFields::class);
        $fields = $custom->definitionsFor(Employee::class);
        if ($fields->isEmpty()) {
            return null;
        }
        $values = $custom->valuesFor($employee);

        return $this->area('other', 'Other details', 'heroicon-o-squares-plus', 'The additional fields your organisation records.', $fields->map(function (CustomField $f) use ($values) {
            $value = $values[$f->key] ?? null;
            $options = $f->optionMap();

            return $this->fact((string) $f->label, match (true) {
                $value === null || $value === '' || $value === [] => null,
                is_bool($value) => $value ? 'Yes' : 'No',
                is_array($value) => collect($value)->map(fn ($v) => $options[$v] ?? $v)->implode(', '),
                default => (string) ($options[$value] ?? $value),
            }, key: (bool) $f->is_required);
        })->values()->all());
    }

    /**
     * @param  list<array{label: string, value: ?string, key: bool}>  $facts
     * @param  list<array<string, mixed>>  $lists
     * @return array<string, mixed>
     */
    private function area(string $key, string $label, string $icon, string $sub, array $facts, array $lists = []): array
    {
        // Every field shows (a blank one as "—", a blank key detail as not recorded), except situational ones with no value.
        $facts = array_values(array_filter($facts, fn (array $f) => ! $f['situational'] || $f['value'] !== null));

        return ['key' => $key, 'label' => $label, 'icon' => $icon, 'sub' => $sub, 'facts' => $facts, 'lists' => $lists,
            'missing' => array_values(array_map(fn (array $f) => $f['label'], array_filter($facts, fn (array $f) => $f['key'] && $f['value'] === null))),
            'revealable' => false, 'revealed' => false, 'note' => null];
    }

    /**
     * @param  bool  $situational  shown only when it has a value (an exit date, a celebrated birthday that differs, …)
     * @return array{label: string, value: ?string, key: bool, situational: bool}
     */
    private function fact(string $label, mixed $value, bool $key = false, bool $situational = false): array
    {
        $value = $value === null ? null : trim((string) $value);

        return ['label' => $label, 'value' => $value === '' ? null : $value, 'key' => $key, 'situational' => $situational];
    }

    /**
     * @param  list<string>  $columns
     * @param  list<list<mixed>>  $rows
     * @return array{title: string, columns: list<string>, rows: list<list<?string>>, empty: string}
     */
    private function table(string $title, array $columns, array $rows, string $empty): array
    {
        return ['title' => $title, 'columns' => $columns, 'empty' => $empty,
            'rows' => array_map(fn (array $row) => array_map(fn ($v) => $v === null || $v === '' ? null : (string) $v, array_values($row)), array_values($rows))];
    }

    private function date(mixed $date): ?string
    {
        return $date instanceof CarbonInterface ? $date->format('j M Y') : null;
    }

    private function phone(?string $code, ?string $number): ?string
    {
        return blank($number) ? null : (filled($code) && ! str_starts_with((string) $number, '+') ? '+'.ltrim((string) $code, '+').' '.$number : (string) $number);
    }

    private function stateName(?string $code): ?string
    {
        return blank($code) ? null : (string) (config('peopleos.compliance.states')[strtoupper((string) $code)] ?? $code);
    }

    private function duration(int $months): string
    {
        $years = intdiv($months, 12);
        $rest = $months % 12;

        return collect([$years ? $years.' '.($years === 1 ? 'year' : 'years') : null, $rest ? $rest.' '.($rest === 1 ? 'month' : 'months') : null])->filter()->implode(' ') ?: 'Under a month';
    }

    private function safe(callable $callback): mixed
    {
        try {
            return $callback();
        } catch (Throwable $e) {
            report($e);

            return null;
        }
    }
}
