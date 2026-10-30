<?php

namespace App\Filament\Support;

use App\Domain\Configuration\Models\CustomField;
use App\Domain\Configuration\Services\CustomFields;
use App\Domain\Configuration\Services\OptionLists;
use App\Domain\Documents\Models\DocumentType;
use App\Domain\Documents\Services\DocumentRules;
use App\Domain\Employment\Models\Employee;
use App\Domain\Employment\Models\EmployeeBankAccount;
use App\Domain\Identity\Scopes\AccessScope;
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
use App\Domain\People\Services\ProfileChangeOperations;
use App\Domain\People\Support\ProfileEditSections;
use App\Domain\Skills\Support\SkillProficiencies;
use App\Support\Storage\StagedUpload;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Component;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;

/**
 * HROne parity MS-3: the "Edit" form of each part of My profile (ProfileEditSections), filled with the record as it is,
 * plus the reason (required, ≤ 500: "Please enter your reason for making changes to profile") and the supporting
 * document (CA-4 upload: types employees may upload, pending HR verification). The forms only collect; ProfileChanges
 * validates with the owning actions' rules, keeps only what changed and routes the request. Entries cannot be removed
 * here (HR removes them); a new row left empty is skipped.
 */
final class ProfileEditForms
{
    /** @return array<int, Component> */
    public static function schema(string $section, Employee $employee): array
    {
        return [...self::fields($section, $employee), ...self::common($section)];
    }

    /** @return array<string, mixed> */
    public static function fill(string $section, Employee $employee): array
    {
        $person = $employee->relationLoaded('person') && $employee->person !== null ? $employee->person : Person::query()->find($employee->person_id);
        $rows = fn (string $model, array $columns, string $order = 'id') => $model::query()->where('person_id', $employee->person_id)->orderBy($order)->get()
            ->map(fn ($r) => ['record_id' => $r->getKey()] + collect($columns)->mapWithKeys(fn (string $c) => [$c => self::state($r->getAttribute($c))])->all())->values()->all();
        $address = fn (string $type) => ($a = PersonAddress::query()->where('person_id', $employee->person_id)->where('type', $type)->currentlyEffective()->latest('id')->first())
            ? collect(['country_code', 'address_line_1', 'address_line_2', 'city', 'state_code', 'postal_code'])->mapWithKeys(fn ($c) => [$c => $a->getAttribute($c)])->all()
            : ['country_code' => 'IN'];

        return match ($section) {
            'blood_group', 'contact', 'legal_identity' => collect(ProfileChangeOperations::PERSON_FIELDS[$section])->mapWithKeys(fn ($f) => [$f => self::state($person?->getAttribute($f))])->all(),
            'addresses' => ['current' => $address('current'), 'permanent' => $address('permanent'), 'same_as_current' => false],
            'emergency_contacts' => ['entries' => $rows(PersonEmergencyContact::class, ['name', 'relation', 'phone', 'alternate_phone', 'email', 'priority'], 'priority')],
            'identifiers' => [],
            'family' => ['marital_status' => $person?->marital_status, 'entries' => $rows(PersonFamilyMember::class, ['name', 'relation', 'date_of_birth', 'gender', 'phone', 'is_dependent', 'is_nominee', 'nominee_share'])],
            'education' => ['entries' => $rows(PersonQualification::class, ['qualification_id', 'specialisation_id', 'qualification', 'specialisation', 'institution', 'board_or_university', 'year_of_completion', 'grade_or_score'])],
            'experience' => ['entries' => $rows(PersonExperience::class, ['employer', 'designation', 'from_date', 'to_date', 'location', 'responsibilities', 'reason_for_leaving'])],
            'skills' => ['entries' => $rows(PersonSkill::class, ['skill_id', 'proficiency', 'years_of_experience'])],
            'other_details' => ['values' => array_intersect_key(app(CustomFields::class)->valuesFor($employee), array_flip(ProfileChangeOperations::editableCustomFields()->pluck('key')->all()))],
            'bank' => ['record_id' => null, 'account_type' => 'savings', 'is_primary' => true],
            default => [],
        };
    }

    /**
     * Form data → [section input, reason, document (or null)].
     *
     * @return array{0: array<string, mixed>, 1: string, 2: ?array<string, mixed>}
     */
    public static function split(array $data): array
    {
        $comment = (string) ($data['change_comment'] ?? '');
        $document = filled($data['document_file'] ?? null) ? [
            'type_id' => $data['document_type_id'] ?? null, 'staged' => $data['document_file'], 'expires_on' => $data['document_expires_on'] ?? null,
            'comment' => $data['document_comment'] ?? null,
        ] : null;
        unset($data['change_comment'], $data['document_type_id'], $data['document_file'], $data['document_expires_on'], $data['document_comment']);

        return [$data, $comment, $document];
    }

    /** @return array<int, Component> */
    private static function fields(string $section, Employee $employee): array
    {
        return match ($section) {
            'blood_group' => [Select::make('blood_group')->label('Blood group')->options(array_combine((array) config('peopleos.people.blood_groups'), (array) config('peopleos.people.blood_groups')))],
            'contact' => [
                TextInput::make('personal_email')->label('Personal e-mail')->email()->maxLength(255),
                TextInput::make('personal_phone')->label('Personal phone')->tel()->maxLength(32),
            ],
            'legal_identity' => [
                TextInput::make('first_name')->label('First name')->required()->maxLength(255),
                TextInput::make('middle_name')->label('Middle name')->maxLength(255),
                TextInput::make('last_name')->label('Last name')->maxLength(255),
                DatePicker::make('date_of_birth')->label('Date of birth')->native(false)->required()->beforeOrEqual('yesterday'),
            ],
            'addresses' => [
                Section::make('Present address')->schema(self::addressFields('current'))->columns(2),
                Toggle::make('same_as_current')->label('Permanent address: same as present')->live()
                    ->afterStateUpdated(fn (mixed $state, Get $get, Set $set) => $state ? $set('permanent', $get('current')) : null),
                Section::make('Permanent address')->schema(self::addressFields('permanent'))->columns(2)->hidden(fn (Get $get) => (bool) $get('same_as_current')),
            ],
            'emergency_contacts' => [self::repeater([
                TextInput::make('name')->required()->maxLength(255),
                TextInput::make('relation')->maxLength(64),
                TextInput::make('phone')->tel()->required()->maxLength(32),
                TextInput::make('alternate_phone')->tel()->maxLength(32),
                TextInput::make('email')->email()->maxLength(255),
                TextInput::make('priority')->numeric()->minValue(1)->maxValue(9),
            ], 'Add a contact')],
            'identifiers' => [
                TextInput::make('pan')->label('PAN')->maxLength(10)->placeholder('ABCDE1234F')->helperText('Leave blank to keep what is on file.'),
                TextInput::make('aadhaar_reference')->label('Aadhaar reference (last digits)')->maxLength(16),
                TextInput::make('uan')->label('UAN')->maxLength(12),
            ],
            'family' => [
                Select::make('marital_status')->label('Marital status')->options((array) config('peopleos.people.marital_statuses')),
                self::repeater([
                    TextInput::make('name')->required()->maxLength(255),
                    Select::make('relation')->required()->options(fn () => app(OptionLists::class)->labels('family_relations')),
                    DatePicker::make('date_of_birth')->native(false)->beforeOrEqual('today'),
                    Select::make('gender')->options((array) config('peopleos.people.genders')),
                    TextInput::make('phone')->tel()->maxLength(32),
                    Toggle::make('is_dependent')->label('Dependent'),
                    Toggle::make('is_nominee')->label('Nominee'),
                    TextInput::make('nominee_share')->label('Nominee share (%)')->numeric()->minValue(0)->maxValue(100),
                ], 'Add a family member'),
            ],
            'education' => [self::repeater([
                Select::make('qualification_id')->label('Qualification')->placeholder('Other (type it)')->live()
                    ->options(fn () => Qualification::query()->where('status', 'active')->orderBy('name')->pluck('name', 'id')->all()),
                Select::make('specialisation_id')->label('Specialisation')->placeholder('Other (type it)')
                    ->options(fn (Get $get) => filled($get('qualification_id')) ? QualificationSpecialisation::query()->where('qualification_id', $get('qualification_id'))->orderBy('name')->pluck('name', 'id')->all() : []),
                TextInput::make('qualification')->label('Qualification (if not in the list)')->maxLength(255)->hidden(fn (Get $get) => filled($get('qualification_id'))),
                TextInput::make('specialisation')->label('Specialisation (if not in the list)')->maxLength(255)->hidden(fn (Get $get) => filled($get('specialisation_id'))),
                TextInput::make('institution')->label('Institute')->maxLength(255),
                TextInput::make('board_or_university')->label('Board / university')->maxLength(255),
                TextInput::make('year_of_completion')->label('Year of passing')->numeric()->minValue(1950)->maxValue((int) now()->format('Y') + 6),
                TextInput::make('grade_or_score')->label('Grade')->maxLength(32),
            ], 'Add education')],
            'experience' => [self::repeater([
                TextInput::make('employer')->label('Company')->required()->maxLength(255),
                TextInput::make('designation')->maxLength(255),
                DatePicker::make('from_date')->label('From')->native(false)->required()->beforeOrEqual('today'),
                DatePicker::make('to_date')->label('To')->native(false)->beforeOrEqual('today'),
                TextInput::make('location')->maxLength(255),
                TextInput::make('reason_for_leaving')->maxLength(255),
                Textarea::make('responsibilities')->label('Job description')->rows(2)->maxLength(2000)->columnSpanFull(),
            ], 'Add experience')],
            'skills' => [self::repeater([
                Select::make('skill_id')->label('Skill')->required()->searchable()
                    ->options(fn () => Skill::query()->where('status', 'active')->orderBy('name')->pluck('name', 'id')->all()),
                Select::make('proficiency')->options(fn () => SkillProficiencies::options()),
                TextInput::make('years_of_experience')->label('Years of experience')->numeric()->minValue(0)->maxValue(60),
            ], 'Add a skill')],
            'other_details' => ProfileChangeOperations::editableCustomFields()->map(fn (CustomField $f) => CustomFieldsSchema::component($f, 'values.'.$f->key))->values()->all(),
            'bank' => self::bankFields($employee),
            default => [],
        };
    }

    /** @return array<int, Component> */
    private static function bankFields(Employee $employee): array
    {
        $accounts = EmployeeBankAccount::query()->withoutGlobalScope(AccessScope::class)->where('employee_id', $employee->getKey())->orderBy('id')->get();

        return [
            Select::make('record_id')->label('Account')->placeholder('Add a new account')->live()
                ->options($accounts->mapWithKeys(fn (EmployeeBankAccount $a) => [$a->id => $a->bank_name.' '.$a->maskedAccountNumber().($a->is_primary ? ' (salary account)' : '')])->all())
                ->afterStateUpdated(function (mixed $state, Set $set) use ($accounts) {
                    $account = filled($state) ? $accounts->firstWhere('id', (int) $state) : null;
                    foreach (['account_holder_name', 'bank_name', 'branch_name', 'ifsc', 'account_type'] as $field) {
                        $set($field, $account?->getAttribute($field));
                    }
                    $set('is_primary', $account ? (bool) $account->is_primary : true);
                }),
            TextInput::make('account_holder_name')->label('Account holder name')->required()->maxLength(255),
            TextInput::make('bank_name')->label('Bank')->required()->maxLength(255),
            TextInput::make('branch_name')->label('Branch')->maxLength(255),
            TextInput::make('ifsc')->label('IFSC')->maxLength(16)->placeholder('HDFC0001234'),
            TextInput::make('account_number')->label('Account number')->maxLength(34)->password()->revealable()
                ->required(fn (Get $get) => blank($get('record_id')))->helperText(fn (Get $get) => filled($get('record_id')) ? 'Leave blank to keep the number on file.' : null),
            TextInput::make('account_number_confirmation')->label('Confirm account number')->maxLength(34)
                ->required(fn (Get $get) => filled($get('account_number')))->same('account_number')->validationMessages(['same' => 'Account numbers do not match.']),
            Select::make('account_type')->label('Account type')->options(['savings' => 'Savings', 'current' => 'Current', 'salary' => 'Salary']),
            Toggle::make('is_primary')->label('Pay my salary into this account'),
        ];
    }

    /** @return array<int, Component> */
    private static function addressFields(string $prefix): array
    {
        return [
            TextInput::make($prefix.'.address_line_1')->label('Address line 1')->maxLength(255),
            TextInput::make($prefix.'.address_line_2')->label('Address line 2')->maxLength(255),
            TextInput::make($prefix.'.city')->label('City')->maxLength(255),
            TextInput::make($prefix.'.state_code')->label('State')->maxLength(16),
            TextInput::make($prefix.'.postal_code')->label('PIN / postal code')->maxLength(16),
            TextInput::make($prefix.'.country_code')->label('Country (2 letters)')->maxLength(2)->default('IN'),
        ];
    }

    /** @param  array<int, Component>  $fields */
    private static function repeater(array $fields, string $add): Repeater
    {
        return Repeater::make('entries')->hiddenLabel()->schema([Hidden::make('record_id'), ...$fields])->columns(2)
            ->addActionLabel($add)->deletable(false)->reorderable(false)->defaultItems(0)
            ->helperText('Change an entry or add one; a new row left empty is skipped. To remove an entry, ask HR.');
    }

    /** @return array<int, Component> the reason and the supporting document */
    private static function common(string $section): array
    {
        $always = ProfileEditSections::document($section) === 'always';

        return [
            Textarea::make('change_comment')->label('Reason for the change')->placeholder('Please enter your reason for making changes to profile')
                ->required()->maxLength(500)->rows(2),
            Section::make('Supporting document')->description($always ? 'Required: attach proof of the change (for example a passport or a gazette notification).' : 'Attach proof when the change needs it (HR verifies it).')
                ->collapsible()->collapsed(! $always)->columns(2)->visible(fn () => app(DocumentRules::class)->selfUploadTypes()->isNotEmpty())
                ->schema([
                    Select::make('document_type_id')->label('Document')->live()->required(fn (Get $get) => $always || filled($get('document_file')))
                        ->options(fn () => app(DocumentRules::class)->selfUploadTypes()->pluck('name', 'id')->all()),
                    FileUpload::make('document_file')->label('File')->required($always)->disk(StagedUpload::disk())->directory('tmp-uploads')->maxSize(config('peopleos.documents.max_kb'))
                        ->acceptedFileTypes(['application/pdf', 'image/jpeg', 'image/png', 'application/msword', 'application/vnd.openxmlformats-officedocument.wordprocessingml.document']),
                    DatePicker::make('document_expires_on')->label('Expires on')->native(false)->afterOrEqual('today')
                        ->required(fn (Get $get) => (bool) DocumentType::query()->find($get('document_type_id'))?->requires_expiry),
                    Textarea::make('document_comment')->label('Comment')->rows(2)->maxLength(500),
                ]),
        ];
    }

    private static function state(mixed $value): mixed
    {
        return $value instanceof \DateTimeInterface ? $value->format('Y-m-d') : $value;
    }
}
