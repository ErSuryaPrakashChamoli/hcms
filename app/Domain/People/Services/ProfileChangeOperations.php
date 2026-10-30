<?php

namespace App\Domain\People\Services;

use App\Domain\Configuration\Models\CustomField;
use App\Domain\Configuration\Services\CustomFields;
use App\Domain\Configuration\Services\OptionLists;
use App\Domain\Employment\Actions\ChangeBankAccountAction;
use App\Domain\Employment\Actions\ChangeStatutoryIdentityAction;
use App\Domain\Employment\Exceptions\ProfileChangeRefused;
use App\Domain\Employment\Models\Employee;
use App\Domain\Employment\Models\EmployeeBankAccount;
use App\Domain\Employment\Models\EmployeeStatutoryDetail;
use App\Domain\Employment\Services\BankAccountRules;
use App\Domain\Employment\Services\ProfileChangeGuard;
use App\Domain\Employment\Support\ChangeOrigin;
use App\Domain\Identity\Models\User;
use App\Domain\Identity\Scopes\AccessScope;
use App\Domain\People\Actions\ChangeAddressAction;
use App\Domain\People\Actions\ChangeEmergencyContactAction;
use App\Domain\People\Actions\ChangeExperienceAction;
use App\Domain\People\Actions\ChangeFamilyMemberAction;
use App\Domain\People\Actions\ChangePersonRecordAction;
use App\Domain\People\Actions\ChangeProfileSkillAction;
use App\Domain\People\Actions\ChangeQualificationAction;
use App\Domain\People\Actions\UpdatePersonAction;
use App\Domain\People\Models\Person;
use App\Domain\People\Models\PersonAddress;
use App\Domain\People\Models\PersonEmergencyContact;
use App\Domain\People\Models\PersonExperience;
use App\Domain\People\Models\PersonFamilyMember;
use App\Domain\People\Models\PersonQualification;
use App\Domain\People\Models\PersonSkill;
use App\Domain\People\Models\Qualification;
use App\Domain\People\Models\QualificationSpecialisation;
use App\Domain\People\Models\Skill;
use App\Domain\Skills\Support\SkillProficiencies;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * HROne parity MS-3: turns what an employee typed into a section's form into OPERATIONS — one per record that changes —
 * and applies them through the owning actions. An operation is
 * `['kind' => …, 'record_id' => ?int, 'values' => [...], 'changed' => [field, …]]`:
 *
 * - `values` are what the action is given (whole records for the record actions, whose rules need every required
 *   field; only the changed fields for the person, statutory identifiers and custom fields, which change partially);
 * - `changed` are the fields that actually differ from the record as it is (nothing changed = no operation).
 *
 * Validation here is the owning action's own rule set (the action checks again when it runs). Records named by id must
 * be the employee's own. Apply runs the People / Employment action for each operation: ChangeAddressAction,
 * ChangeEmergencyContactAction, ChangeFamilyMemberAction, ChangeQualificationAction, ChangeExperienceAction,
 * ChangeProfileSkillAction, ChangeStatutoryIdentityAction, ChangeBankAccountAction, UpdatePersonAction (only the fields
 * of the section) and CustomFields::store (only fields HR marked editable by the employee) — the last two after
 * ProfileChangeGuard::authorize, like the others. Self-declared education and experience are stored unverified.
 */
final class ProfileChangeOperations
{
    /** Record kinds: action, model, label. */
    public const RECORDS = [
        'address' => [ChangeAddressAction::class, PersonAddress::class, 'Address'],
        'emergency_contact' => [ChangeEmergencyContactAction::class, PersonEmergencyContact::class, 'Emergency contact'],
        'family_member' => [ChangeFamilyMemberAction::class, PersonFamilyMember::class, 'Family member'],
        'qualification' => [ChangeQualificationAction::class, PersonQualification::class, 'Education'],
        'experience' => [ChangeExperienceAction::class, PersonExperience::class, 'Work experience'],
        'skill' => [ChangeProfileSkillAction::class, PersonSkill::class, 'Skill'],
    ];

    /** Person fields per section (UpdatePersonAction). */
    public const PERSON_FIELDS = [
        'blood_group' => ['blood_group'],
        'contact' => ['personal_email', 'personal_phone'],
        'family' => ['marital_status'],
        'legal_identity' => ['first_name', 'middle_name', 'last_name', 'date_of_birth'],
    ];

    /** The statutory identifiers an employee may give (the Aadhaar reference only, as today). */
    public const IDENTIFIERS = ['pan', 'aadhaar_reference', 'uan'];

    /** Fields whose values are masked for anyone without employee.sensitive.view. */
    public const SENSITIVE = ['statutory' => ['pan', 'aadhaar_reference', 'uan'], 'bank_account' => ['account_number']];

    private const LABELS = [
        'blood_group' => 'Blood group', 'personal_email' => 'Personal e-mail', 'personal_phone' => 'Personal phone', 'marital_status' => 'Marital status',
        'first_name' => 'First name', 'middle_name' => 'Middle name', 'last_name' => 'Last name', 'date_of_birth' => 'Date of birth',
        'type' => 'Type', 'country_code' => 'Country', 'address_line_1' => 'Address line 1', 'address_line_2' => 'Address line 2', 'city' => 'City',
        'state_code' => 'State', 'postal_code' => 'PIN / postal code', 'name' => 'Name', 'relation' => 'Relation', 'phone' => 'Phone',
        'alternate_phone' => 'Alternate phone', 'email' => 'E-mail', 'priority' => 'Priority', 'gender' => 'Gender', 'is_dependent' => 'Dependent',
        'is_nominee' => 'Nominee', 'nominee_share' => 'Nominee share (%)', 'qualification_id' => 'Qualification', 'specialisation_id' => 'Specialisation',
        'qualification' => 'Qualification', 'specialisation' => 'Specialisation', 'institution' => 'Institute', 'board_or_university' => 'Board / university',
        'year_of_completion' => 'Year of passing', 'grade_or_score' => 'Grade', 'employer' => 'Company', 'designation' => 'Designation', 'from_date' => 'From',
        'to_date' => 'To', 'location' => 'Location', 'responsibilities' => 'Job description', 'reason_for_leaving' => 'Reason for leaving',
        'skill_id' => 'Skill', 'proficiency' => 'Proficiency', 'years_of_experience' => 'Years of experience', 'last_used_on' => 'Last used on',
        'pan' => 'PAN', 'aadhaar_reference' => 'Aadhaar reference', 'uan' => 'UAN', 'account_holder_name' => 'Account holder', 'bank_name' => 'Bank',
        'branch_name' => 'Branch', 'ifsc' => 'IFSC', 'account_number' => 'Account number', 'account_type' => 'Account type', 'is_primary' => 'Salary account',
    ];

    /** Self-declared entries that HR verifies: stored unverified. */
    private const UNVERIFIED = ['qualification', 'experience'];

    /** Fields never taken from the employee (HR's own). */
    private const NOT_FROM_EMPLOYEE = ['is_verified', 'effective_from', 'effective_to', 'verified_at'];

    public function __construct(private readonly ProfileChangeGuard $guard) {}

    // -- building -------------------------------------------------------------------------------------------------

    /**
     * @param  array<string, mixed>  $input  the section form's data
     * @return list<array{kind: string, record_id: ?int, values: array<string, mixed>, changed: list<string>}>
     */
    public function build(Employee $employee, string $section, array $input): array
    {
        return match ($section) {
            'blood_group', 'contact', 'legal_identity' => $this->person($employee, $section, $input),
            'addresses' => $this->addresses($employee, $input),
            'emergency_contacts' => $this->records($employee, 'emergency_contact', (array) ($input['entries'] ?? [])),
            'identifiers' => $this->statutory($employee, $input),
            'family' => [...$this->person($employee, 'family', $input), ...$this->records($employee, 'family_member', (array) ($input['entries'] ?? []))],
            'education' => $this->records($employee, 'qualification', (array) ($input['entries'] ?? [])),
            'experience' => $this->records($employee, 'experience', (array) ($input['entries'] ?? [])),
            'skills' => $this->records($employee, 'skill', (array) ($input['entries'] ?? [])),
            'other_details' => $this->customFields($employee, (array) ($input['values'] ?? [])),
            'bank' => $this->bank($employee, $input),
            default => throw new ProfileChangeRefused('That part of the profile cannot be changed here.'),
        };
    }

    /** @return list<array{kind: string, record_id: ?int, values: array<string, mixed>, changed: list<string>}> */
    private function person(Employee $employee, string $section, array $input): array
    {
        $fields = self::PERSON_FIELDS[$section];
        $person = $employee->relationLoaded('person') && $employee->person !== null ? $employee->person : Person::query()->findOrFail($employee->person_id);
        $values = $this->clean(array_intersect_key($input, array_flip($fields)));
        $rules = [
            'blood_group' => ['nullable', 'in:'.implode(',', (array) config('peopleos.people.blood_groups', []))],
            'personal_email' => ['nullable', 'email', 'max:255'],
            'personal_phone' => ['nullable', 'string', 'max:32'],
            'marital_status' => ['nullable', 'in:'.implode(',', array_keys((array) config('peopleos.people.marital_statuses', [])))],
            'first_name' => ['required', 'string', 'max:255'],
            'middle_name' => ['nullable', 'string', 'max:255'],
            'last_name' => ['nullable', 'string', 'max:255'],
            'date_of_birth' => ['required', 'date', 'before:today'],
        ];
        $this->validate($values, array_intersect_key($rules, array_flip($fields)), $section === 'legal_identity' ? $fields : array_keys($values));
        $changed = array_values(array_filter(array_keys($values), fn (string $f) => $this->norm($values[$f]) !== $this->norm($person->getAttribute($f))));

        return $changed === [] ? [] : [['kind' => 'person', 'record_id' => null, 'values' => array_intersect_key($values, array_flip($changed)), 'changed' => $changed]];
    }

    /** Current and permanent addresses ("Same as present" copies the current one). */
    private function addresses(Employee $employee, array $input): array
    {
        $current = (array) ($input['current'] ?? []);
        $permanent = ! empty($input['same_as_current']) ? $current : (array) ($input['permanent'] ?? []);
        $ops = [];
        foreach (['current' => $current, 'permanent' => $permanent] as $type => $values) {
            $values = $this->clean(array_intersect_key($values, array_flip(['country_code', 'address_line_1', 'address_line_2', 'city', 'state_code', 'postal_code'])));
            $existing = PersonAddress::query()->where('person_id', $employee->person_id)->where('type', $type)->currentlyEffective()->latest('id')->first();
            if ($existing === null && array_filter($values, fn ($v) => $v !== null) === []) {
                continue;
            }
            $entry = ['record_id' => $existing?->getKey(), 'type' => $type] + $values;
            array_push($ops, ...$this->records($employee, 'address', [$entry]));
        }

        return $ops;
    }

    /**
     * Rows of one record kind: a row with `record_id` changes that (own) record, a row without one adds a record.
     *
     * @param  list<array<string, mixed>>  $entries
     */
    private function records(Employee $employee, string $kind, array $entries): array
    {
        [$actionClass, $model] = self::RECORDS[$kind];
        /** @var ChangePersonRecordAction $action */
        $action = app($actionClass);
        $rules = $action->rules();
        $keys = array_values(array_diff(array_keys($rules), self::NOT_FROM_EMPLOYEE));
        $ops = [];
        $seen = [];
        foreach (array_values($entries) as $entry) {
            $entry = (array) $entry;
            $recordId = filled($entry['record_id'] ?? null) ? (int) $entry['record_id'] : null;
            $values = $this->clean(array_intersect_key($entry, array_flip($keys)));
            if ($recordId === null && array_filter($values, fn ($v) => $v !== null && $v !== false) === []) {
                continue; // an empty new row
            }
            if ($recordId !== null) {
                if (isset($seen[$recordId])) {
                    throw new ProfileChangeRefused('The same entry appears twice.');
                }
                $seen[$recordId] = true;
                $record = $model::query()->whereKey($recordId)->where('person_id', $employee->person_id)->first()
                    ?? throw new ProfileChangeRefused('That entry is not on your profile.');
                $current = $this->clean(array_intersect_key($record->getAttributes(), array_flip($keys)));
                $merged = $values + $current;
                $changed = array_values(array_filter($keys, fn (string $f) => array_key_exists($f, $values) && $this->norm($values[$f]) !== $this->norm($record->getAttribute($f))));
                if ($changed === []) {
                    continue;
                }
            } else {
                $merged = $values;
                $changed = array_keys(array_filter($values, fn ($v) => $v !== null && $v !== ''));
            }
            $this->validate($merged, $rules, array_keys($rules));
            if (in_array($kind, self::UNVERIFIED, true)) {
                $merged['is_verified'] = false;
            }
            $ops[] = ['kind' => $kind, 'record_id' => $recordId, 'values' => $merged, 'changed' => $changed];
        }

        return $ops;
    }

    private function statutory(Employee $employee, array $input): array
    {
        $values = array_filter($this->clean(array_intersect_key($input, array_flip(self::IDENTIFIERS))), fn ($v) => $v !== null);
        if (isset($values['pan'])) {
            $values['pan'] = mb_strtoupper((string) $values['pan']);
        }
        $this->validate($values, [
            'pan' => ['nullable', 'string', 'size:10', 'regex:/^[A-Z]{5}[0-9]{4}[A-Z]$/'],
            'aadhaar_reference' => ['nullable', 'string', 'max:16'],
            'uan' => ['nullable', 'string', 'max:12'],
        ], array_keys($values));
        $detail = EmployeeStatutoryDetail::query()->withoutGlobalScope(AccessScope::class)->where('employee_id', $employee->getKey())->first();
        $changed = array_values(array_filter(array_keys($values), fn (string $f) => (string) $values[$f] !== (string) ($detail?->getAttribute($f) ?? '')));

        return $changed === [] ? [] : [['kind' => 'statutory', 'record_id' => null, 'values' => array_intersect_key($values, array_flip($changed)), 'changed' => $changed]];
    }

    /** A new salary account, or a change to one of the employee's own accounts (a blank number keeps it). */
    private function bank(Employee $employee, array $input): array
    {
        $recordId = filled($input['record_id'] ?? null) ? (int) $input['record_id'] : null;
        $keys = ['account_holder_name', 'bank_name', 'branch_name', 'ifsc', 'account_number', 'account_type', 'is_primary'];
        $values = $this->clean(array_intersect_key($input, array_flip($keys)));
        if (isset($values['ifsc'])) {
            $values['ifsc'] = mb_strtoupper((string) $values['ifsc']);
        }
        $number = $values['account_number'] ?? null;
        if ($number !== null && (string) $number !== trim((string) ($input['account_number_confirmation'] ?? ''))) {
            throw new ProfileChangeRefused('Account numbers do not match.');
        }
        $rules = app(ChangeBankAccountAction::class)->rules($recordId === null);
        if ($recordId === null) {
            $this->validate($values, $rules, array_keys($rules));
            $this->assertBankRule($values['account_number'] ?? null, $values['ifsc'] ?? null, $values['bank_name'] ?? null);
            $changed = array_keys(array_filter($values, fn ($v) => $v !== null && $v !== ''));

            return [['kind' => 'bank_account', 'record_id' => null, 'values' => $values, 'changed' => $changed]];
        }
        $account = EmployeeBankAccount::query()->withoutGlobalScope(AccessScope::class)->whereKey($recordId)->where('employee_id', $employee->getKey())->first()
            ?? throw new ProfileChangeRefused('That account is not on your profile.');
        // Changing an account: a blank field keeps what is on file (the number too).
        $values = array_filter($values, fn ($v) => $v !== null);
        $current = ['account_holder_name' => $account->account_holder_name, 'bank_name' => $account->bank_name, 'branch_name' => $account->branch_name,
            'ifsc' => $account->ifsc, 'account_type' => $account->account_type, 'is_primary' => (bool) $account->is_primary];
        $merged = $values + $current;
        $this->validate($merged, $rules, array_keys($rules));
        $changed = array_values(array_filter(array_keys($values), fn (string $f) => $f === 'account_number'
            ? (string) $values[$f] !== (string) $account->account_number
            : $this->norm($values[$f]) !== $this->norm($current[$f] ?? null)));
        if ($changed === []) {
            return [];
        }
        if (array_intersect($changed, ['account_number', 'ifsc', 'bank_name']) !== []) {
            $this->assertBankRule($merged['account_number'] ?? $account->account_number, $merged['ifsc'] ?? null, $merged['bank_name'] ?? null);
        }

        return [['kind' => 'bank_account', 'record_id' => $recordId, 'values' => $merged, 'changed' => $changed]];
    }

    /** Only the custom fields HR lets employees see and change (custom_fields.editable_by_employee). */
    private function customFields(Employee $employee, array $input): array
    {
        $fields = self::editableCustomFields()->keyBy('key');
        $values = array_intersect_key($input, $fields->all());
        if ($values === []) {
            return [];
        }
        $this->validate($values, $fields->intersectByKeys($values)->map(fn (CustomField $f) => $f->rules())->all(), array_keys($values));
        $current = app(CustomFields::class)->valuesFor($employee);
        $changed = array_values(array_filter(array_keys($values), fn (string $k) => $this->norm($values[$k]) !== $this->norm($current[$k] ?? null)));

        return $changed === [] ? [] : [['kind' => 'custom_fields', 'record_id' => null, 'values' => array_intersect_key($values, array_flip($changed)), 'changed' => $changed]];
    }

    /** @return Collection<int, CustomField> */
    public static function editableCustomFields(): Collection
    {
        return app(CustomFields::class)->definitionsFor(Employee::class)
            ->filter(fn (CustomField $f) => (bool) $f->getAttribute('editable_by_employee') && (bool) $f->visible_to_employee)->values();
    }

    // -- rights -----------------------------------------------------------------------------------------------------

    /**
     * The rights the operations' actions ask of whoever applies them (the approver), as the actions check them.
     *
     * @param  list<array<string, mixed>>  $operations
     * @return list<string>
     */
    public function permissions(array $operations): array
    {
        $out = [];
        foreach ($operations as $op) {
            $out[] = match ($op['kind']) {
                'statutory' => 'employee.sensitive.update',
                'bank_account' => ChangeBankAccountAction::PERMISSION,
                'person', 'custom_fields' => 'employee.update',
                default => $op['record_id'] === null ? 'employee.create' : 'employee.update',
            };
        }

        return array_values(array_unique($out));
    }

    // -- applying ---------------------------------------------------------------------------------------------------

    /** Apply one operation through its owning action; returns the record changed (referenced, never copied). */
    public function apply(array $op, Employee $employee, User $actor, string $reason, ChangeOrigin $origin): Model
    {
        $values = (array) $op['values'];
        $recordId = $op['record_id'] ?? null;

        return match ($op['kind']) {
            'person' => $this->applyPerson($employee, $values, $actor, $reason, $origin),
            'statutory' => app(ChangeStatutoryIdentityAction::class)->handle($employee, $values, $actor, $reason, $origin),
            'bank_account' => $recordId === null
                ? app(ChangeBankAccountAction::class)->add($employee, $values, $actor, $reason, $origin)
                : app(ChangeBankAccountAction::class)->update($this->ownAccount($employee, (int) $recordId), $values, $actor, $reason, $origin),
            'custom_fields' => $this->applyCustomFields($employee, $values, $actor, $reason, $origin),
            default => $this->applyRecord($op['kind'], $employee, $recordId === null ? null : (int) $recordId, $values, $actor, $reason, $origin),
        };
    }

    private function applyPerson(Employee $employee, array $values, User $actor, string $reason, ChangeOrigin $origin): Person
    {
        $this->guard->authorize($actor, $employee, 'employee.update', $origin);
        $person = Person::query()->findOrFail($employee->person_id);

        return app(UpdatePersonAction::class)->handle($person, $values, $reason, $origin->reference);
    }

    private function applyCustomFields(Employee $employee, array $values, User $actor, string $reason, ChangeOrigin $origin): Employee
    {
        $this->guard->authorize($actor, $employee, 'employee.update', $origin);
        $editable = self::editableCustomFields()->pluck('key')->all();
        try {
            app(CustomFields::class)->store($employee, array_intersect_key($values, array_flip($editable)), $reason);
        } catch (ValidationException $e) {
            throw new ProfileChangeRefused(collect($e->errors())->flatten()->first() ?? 'The change is not valid.');
        }

        return $employee;
    }

    private function applyRecord(string $kind, Employee $employee, ?int $recordId, array $values, User $actor, string $reason, ChangeOrigin $origin): Model
    {
        [$actionClass, $model] = self::RECORDS[$kind];
        /** @var ChangePersonRecordAction $action */
        $action = app($actionClass);
        if ($recordId === null) {
            return $action->add($employee, $values, $actor, $reason, $origin);
        }
        $record = $model::query()->withoutGlobalScopes([AccessScope::class])->whereKey($recordId)->where('person_id', $employee->person_id)->first()
            ?? throw new ProfileChangeRefused('That entry is no longer on the profile.');

        return $action->update($employee, $record, $values, $actor, $reason, $origin);
    }

    private function ownAccount(Employee $employee, int $id): EmployeeBankAccount
    {
        return EmployeeBankAccount::query()->withoutGlobalScope(AccessScope::class)->whereKey($id)->where('employee_id', $employee->getKey())->first()
            ?? throw new ProfileChangeRefused('That account is no longer on the profile.');
    }

    // -- describing -------------------------------------------------------------------------------------------------

    /**
     * Earlier / new per changed field, as text. Earlier is read from the record now (at decision time); sensitive values
     * are masked unless $revealSensitive.
     *
     * @param  list<array<string, mixed>>  $operations
     * @return list<array{label: string, before: ?string, after: string, sensitive: bool}>
     */
    public function describe(Employee $employee, array $operations, bool $revealSensitive): array
    {
        $rows = [];
        foreach ($operations as $op) {
            $record = $this->currentRecord($employee, $op);
            $prefix = $this->prefix($op, $record);
            foreach ((array) $op['changed'] as $field) {
                $sensitive = in_array($field, self::SENSITIVE[$op['kind']] ?? [], true);
                $before = $record === null ? null : $this->display($op['kind'], $field, $record instanceof Model ? $record->getAttribute($field) : ($record[$field] ?? null));
                $after = $this->display($op['kind'], $field, $op['values'][$field] ?? null);
                if ($sensitive && ! $revealSensitive) {
                    [$before, $after] = [self::mask($before), self::mask($after)];
                }
                $rows[] = ['label' => $prefix.$this->fieldLabel($op['kind'], (string) $field), 'before' => $before, 'after' => $after ?? '—', 'sensitive' => $sensitive];
            }
        }

        return $rows;
    }

    /**
     * The history kept after the values are purged: field names with masked earlier / new values.
     *
     * @return list<array{label: string, earlier: ?string, new: ?string}>
     */
    public function maskedSummary(Employee $employee, array $operations): array
    {
        return array_map(fn (array $row) => ['label' => $row['label'], 'earlier' => self::mask($row['before']), 'new' => self::mask($row['after'] === '—' ? null : $row['after'])],
            $this->describe($employee, $operations, true));
    }

    /** "••••1234" for longer values, "••••" for short ones; null stays null. */
    public static function mask(?string $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        return mb_strlen($value) > 6 ? '••••'.mb_substr($value, -4) : '••••';
    }

    private function prefix(array $op, Model|array|null $record): string
    {
        return match ($op['kind']) {
            'person', 'statutory', 'custom_fields' => '',
            'address' => 'Address ('.mb_strtolower((string) (config('peopleos.people.address_types.'.($op['values']['type'] ?? ''), $op['values']['type'] ?? ''))).') · ',
            'bank_account' => $op['record_id'] === null ? 'New bank account · ' : 'Bank account · ',
            default => (self::RECORDS[$op['kind']][2] ?? 'Entry').($op['record_id'] === null ? ' (new)' : '').' · ',
        };
    }

    private function fieldLabel(string $kind, string $field): string
    {
        if ($kind === 'custom_fields') {
            return (string) (self::editableCustomFields()->firstWhere('key', $field)?->label ?? $field);
        }

        return self::LABELS[$field] ?? ucfirst(str_replace('_', ' ', $field));
    }

    /** The record as it is now (null when the operation adds one). */
    private function currentRecord(Employee $employee, array $op): Model|array|null
    {
        try {
            return match ($op['kind']) {
                'person' => Person::query()->find($employee->person_id),
                'statutory' => EmployeeStatutoryDetail::query()->withoutGlobalScope(AccessScope::class)->where('employee_id', $employee->getKey())->first(),
                'bank_account' => $op['record_id'] === null ? null : EmployeeBankAccount::query()->withoutGlobalScope(AccessScope::class)->whereKey($op['record_id'])->where('employee_id', $employee->getKey())->first(),
                'custom_fields' => app(CustomFields::class)->valuesFor($employee),
                default => $op['record_id'] === null ? null : self::RECORDS[$op['kind']][1]::query()->withoutGlobalScopes([AccessScope::class])->whereKey($op['record_id'])->where('person_id', $employee->person_id)->first(),
            };
        } catch (Throwable $e) {
            report($e);

            return null;
        }
    }

    private function display(string $kind, string $field, mixed $value): ?string
    {
        if ($value === null || $value === '' || $value === []) {
            return null;
        }
        if ($value instanceof CarbonInterface) {
            return $value->format('j M Y');
        }
        if (is_bool($value) || in_array($field, ['is_dependent', 'is_nominee', 'is_primary'], true)) {
            return (bool) $value ? 'Yes' : 'No';
        }
        if (in_array($field, ['date_of_birth', 'from_date', 'to_date', 'last_used_on'], true)) {
            try {
                return Carbon::parse((string) $value)->format('j M Y');
            } catch (Throwable) {
                return (string) $value;
            }
        }
        if ($kind === 'custom_fields') {
            $definition = self::editableCustomFields()->firstWhere('key', $field);
            $options = $definition?->optionMap() ?? [];

            return is_array($value) ? implode(', ', array_map(fn ($v) => (string) ($options[$v] ?? $v), $value)) : (string) ($options[$value] ?? $value);
        }

        return match ($field) {
            'marital_status' => (string) config('peopleos.people.marital_statuses.'.$value, $value),
            'gender' => (string) config('peopleos.people.genders.'.$value, $value),
            'relation' => $kind === 'family_member' ? (string) (app(OptionLists::class)->label('family_relations', (string) $value) ?? $value) : (string) $value,
            'type' => (string) config('peopleos.people.address_types.'.$value, $value),
            'account_type' => ucfirst((string) $value),
            'skill_id' => (string) (Skill::query()->whereKey((int) $value)->value('name') ?? $value),
            'qualification_id' => (string) (Qualification::query()->whereKey((int) $value)->value('name') ?? $value),
            'specialisation_id' => (string) (QualificationSpecialisation::query()->whereKey((int) $value)->value('name') ?? $value),
            'proficiency' => (string) (SkillProficiencies::label((string) $value) ?? $value),
            default => is_array($value) ? implode(', ', $value) : (string) $value,
        };
    }

    // -- helpers ----------------------------------------------------------------------------------------------------

    /** Trim strings, blank → null, dates as Y-m-d. @return array<string, mixed> */
    private function clean(array $values): array
    {
        $out = [];
        foreach ($values as $key => $value) {
            if ($value instanceof CarbonInterface) {
                $value = $value->toDateString();
            } elseif (is_string($value)) {
                $value = trim(strip_tags($value));
            }
            $out[(string) $key] = $value === '' ? null : $value;
        }

        return $out;
    }

    /** A comparable form: null for blank, Y-m-d for dates, '1' / '0' for booleans, strings for the rest. */
    private function norm(mixed $value): ?string
    {
        return match (true) {
            $value === null, $value === '', $value === [] => null,
            $value instanceof CarbonInterface => $value->toDateString(),
            is_bool($value) => $value ? '1' : '0',
            is_array($value) => json_encode(array_values($value)),
            is_string($value) && preg_match('/^\d{4}-\d{2}-\d{2}( 00:00:00)?$/', $value) === 1 => substr($value, 0, 10),
            is_numeric($value) && str_contains((string) $value, '.') => rtrim(rtrim((string) $value, '0'), '.'),
            default => (string) $value,
        };
    }

    /**
     * @param  array<string, mixed>  $values
     * @param  array<string, mixed>  $rules
     * @param  list<string>  $fields  the fields to check
     */
    private function validate(array $values, array $rules, array $fields): void
    {
        $rules = array_intersect_key($rules, array_flip($fields));
        if ($rules === []) {
            return;
        }
        $validator = Validator::make($values, $rules, [], array_intersect_key(self::LABELS, $rules));
        if ($validator->fails()) {
            throw new ProfileChangeRefused((string) collect($validator->errors()->all())->first());
        }
    }

    private function assertBankRule(?string $number, ?string $ifsc, ?string $bank): void
    {
        if (($problem = app(BankAccountRules::class)->problem($number, $ifsc, $bank)) !== null) {
            throw new ProfileChangeRefused($problem);
        }
    }
}
