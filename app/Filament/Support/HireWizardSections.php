<?php

namespace App\Filament\Support;

use App\Domain\Configuration\Services\CustomFields;
use App\Domain\Configuration\Services\OptionLists;
use App\Domain\Documents\Models\DocumentType;
use App\Domain\Employment\Models\Employee;
use App\Domain\Identity\Models\Role;
use App\Domain\Identity\Models\User;
use App\Domain\Identity\Services\BulkInvitations;
use App\Domain\Identity\Services\RoleGrants;
use Closure;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Component;
use Filament\Schemas\Components\Fieldset;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Utilities\Get;

/**
 * HROne parity WO-3 (matrix 23 §23.2.3 #7, #17, #18): the optional parts of the hire wizard. Each part carries the fields
 * of the Employee 360 form that records it (Addresses, Family & nominees, Bank, Previous employment, Edit statutory
 * details, Additional information) and is written by the same action, so a part filled here creates exactly what the 360
 * would. Every part is optional: an empty list, or a toggle left off, records nothing.
 */
final class HireWizardSections
{
    /** HROne setup parity CA-4: the document type that must be on file before a value of this profile field is entered. */
    public static function documentNeededFor(string $field): ?string
    {
        return DocumentType::query()->where('status', 'active')->where('linked_field', $field)->where('required_with_field', true)->orderBy('name')->value('name');
    }

    /** A brand-new employee has no documents yet, so a field whose document is required with it waits for the 360. */
    private static function documentRule(string $field): Closure
    {
        return fn () => function (string $attribute, mixed $value, Closure $fail) use ($field): void {
            if (filled($value) && ($type = self::documentNeededFor($field)) !== null) {
                $fail("Your organisation needs the employee's {$type} on file before this is entered: leave it blank and add it on the Employee 360 once the document is uploaded.");
            }
        };
    }

    /** As "Edit statutory details" on the 360 (PAN, Aadhaar reference); written by ChangeStatutoryIdentityAction. @return array<int, Component> */
    public static function identityFields(): array
    {
        return [
            TextInput::make('pan')->label('PAN')->maxLength(10)->regex('/^[A-Z]{5}[0-9]{4}[A-Z]$/')->helperText('Format ABCDE1234F')
                ->live(onBlur: true)->rule(self::documentRule('statutory.pan')),
            TextInput::make('aadhaar_reference')->label('Aadhaar reference')->maxLength(16)
                ->helperText('Store a reference / last digits, not the full number, unless policy allows. A reference of 12 or more characters is also compared with earlier employees.')
                ->live(onBlur: true)->rule(self::documentRule('statutory.aadhaar')),
        ];
    }

    /** As the 360 Addresses tab (ChangeAddressAction). */
    public static function addresses(): Repeater
    {
        return Repeater::make('addresses')->label('Addresses')->defaultItems(0)->addActionLabel('Add an address')->columns(2)
            ->itemLabel(fn (array $state) => config('peopleos.people.address_types.'.($state['type'] ?? ''), 'Address'))
            ->rule(self::documentRule('address'))
            ->schema([
                Select::make('type')->options(config('peopleos.people.address_types'))->required(),
                TextInput::make('country_code')->required()->default('IN')->length(2),
                TextInput::make('address_line_1')->required()->maxLength(255)->columnSpanFull(),
                TextInput::make('address_line_2')->maxLength(255)->columnSpanFull(),
                TextInput::make('city')->maxLength(255),
                TextInput::make('state_code')->maxLength(16),
                TextInput::make('postal_code')->maxLength(16),
                DatePicker::make('effective_from')->native(false),
                DatePicker::make('effective_to')->native(false)->afterOrEqual('effective_from'),
            ]);
    }

    /** As the 360 Family & nominees tab (ChangeFamilyMemberAction, HROne parity MS-4 fields). */
    public static function family(): Repeater
    {
        return Repeater::make('family')->label('Family members')->defaultItems(0)->addActionLabel('Add a family member')
            ->itemLabel(fn (array $state) => filled($state['name'] ?? null) ? $state['name'] : 'Family member')
            ->schema([
                Fieldset::make('Family member')->columns(2)->schema([
                    TextInput::make('name')->required()->maxLength(255),
                    Select::make('relation')->options(fn () => app(OptionLists::class)->options('family_relations'))->required(),
                    DatePicker::make('date_of_birth')->native(false),
                    Select::make('gender')->options(config('peopleos.people.genders')),
                    TextInput::make('phone')->tel()->maxLength(32),
                    TextInput::make('occupation')->maxLength(120),
                    TextInput::make('remarks')->maxLength(255),
                ]),
                Fieldset::make('Dependant, nominee and schooling')->columns(2)->schema([
                    Toggle::make('is_dependent')->label('Dependent'),
                    Toggle::make('is_nominee')->label('Nominee')->live(),
                    TextInput::make('nominee_share')->numeric()->minValue(0)->maxValue(100)->suffix('%')->visible(fn (Get $get) => (bool) $get('is_nominee')),
                    Toggle::make('in_school')->label('In school')->helperText('For children: studying at a school or college.'),
                    Toggle::make('in_hostel')->label('In a hostel')->helperText('For children: living in a hostel while studying.'),
                ]),
            ]);
    }

    /** As the 360 Bank tab (ChangeBankAccountAction): one account at hire; more on the 360. @return array<int, Component> */
    public static function bank(): array
    {
        return [
            Toggle::make('add_bank')->label('Add a bank account now')->live()->default(false)
                ->afterStateUpdated(fn (?bool $state, Get $get, $set) => $state && blank($get('bank.account_holder_name'))
                    ? $set('bank.account_holder_name', trim(implode(' ', array_filter([$get('first_name'), $get('middle_name'), $get('last_name')])))) : null)
                ->helperText(fn () => ($type = self::documentNeededFor('bank_account')) !== null
                    ? "Your organisation needs the employee's {$type} on file first: add the account on the Employee 360 once it is uploaded."
                    : 'The account number is encrypted, masked everywhere and audited, as on the Employee 360.')
                ->disabled(fn () => self::documentNeededFor('bank_account') !== null),
            Grid::make(2)->statePath('bank')->visible(fn (Get $get) => (bool) $get('add_bank'))->schema([
                TextInput::make('account_holder_name')->required()->maxLength(255),
                TextInput::make('bank_name')->required()->maxLength(255),
                TextInput::make('branch_name')->maxLength(255),
                TextInput::make('ifsc')->label('IFSC')->maxLength(16)->regex('/^[A-Z]{4}0[A-Z0-9]{6}$/')->helperText('Format ABCD0123456'),
                TextInput::make('account_number')->required()->maxLength(34)->password()->revealable(),
                Select::make('account_type')->options(['savings' => 'Savings', 'current' => 'Current', 'salary' => 'Salary']),
                Toggle::make('is_primary')->label('Primary (salary) account'),
            ]),
        ];
    }

    /** As the 360 Previous employment tab (written by ChangeExperienceAction, the same rules). */
    public static function experiences(): Repeater
    {
        return Repeater::make('experiences')->label('Previous employment')->defaultItems(0)->addActionLabel('Add an earlier employer')->columns(2)
            ->itemLabel(fn (array $state) => filled($state['employer'] ?? null) ? $state['employer'] : 'Earlier employer')
            ->visible(fn (Get $get) => ! $get('is_fresher'))
            ->schema([
                TextInput::make('employer')->required()->maxLength(255),
                TextInput::make('designation')->maxLength(255),
                DatePicker::make('from_date')->native(false)->required(),
                DatePicker::make('to_date')->native(false)->afterOrEqual('from_date'),
                TextInput::make('location')->maxLength(255),
                TextInput::make('reason_for_leaving')->maxLength(255),
                Textarea::make('responsibilities')->rows(3)->columnSpanFull(),
                Toggle::make('is_verified')->label('Verified'),
            ]);
    }

    public static function hasCustomFields(): bool
    {
        return app(CustomFields::class)->definitionsFor(Employee::class)->isNotEmpty();
    }

    /** As "Additional information" on the edit form (CustomFields::store). @return array<int, Component> */
    public static function customFields(): array
    {
        return [
            Toggle::make('fill_custom_fields')->label('Fill in the other details now')->live()->default(false),
            Grid::make(2)->visible(fn (Get $get) => (bool) $get('fill_custom_fields'))
                ->schema(fn () => app(CustomFields::class)->definitionsFor(Employee::class)
                    ->map(fn ($field) => CustomFieldsSchema::component($field, 'hire_custom_fields.'.$field->key))->all()),
        ];
    }

    /**
     * WO-16's "Send to new user" for this one employee: a sign-in from the work e-mail, the roles the actor may give (the
     * Employee role offered by default), the invitation e-mailed. Off by default (WRK-7: no self-service before day one).
     *
     * @return array<int, Component>
     */
    public static function signIn(): array
    {
        return [
            Toggle::make('invite_user')->label('Create a sign-in and send the invitation')->live()->default(false)
                ->helperText('Off by default. The sign-in uses the work e-mail; the person chooses their own password from the one-time link. People who have not joined yet usually get access from day one.')
                ->rule(fn (Get $get) => function (string $attribute, mixed $value, Closure $fail) use ($get): void {
                    if ($value && ($problem = self::signInProblem($get('work_email'))) !== null) {
                        $fail($problem);
                    }
                }),
            Select::make('invite_roles')->label('Roles for the new sign-in')->multiple()->preload()
                ->options(function () {
                    $actor = auth()->user();
                    $grants = app(RoleGrants::class);

                    return Role::query()->orderBy('name')->get()->filter(fn (Role $r) => $actor !== null && $grants->canAssign($actor, $r))->pluck('name', 'id')->all();
                })
                ->default(fn () => auth()->user() instanceof User ? app(BulkInvitations::class)->defaultRoleIds(auth()->user()) : [])
                ->visible(fn (Get $get) => (bool) $get('invite_user') && (auth()->user()?->hasPermission('user.assign_roles') ?? false))
                ->helperText('Role membership rules apply as usual.'),
        ];
    }

    /** What BulkInvitations would refuse for this work e-mail, checked before the hire (null = fine). */
    public static function signInProblem(mixed $workEmail): ?string
    {
        $email = mb_strtolower(trim((string) $workEmail));
        if ($email === '' || filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            return 'A sign-in needs the work e-mail (Employment step).';
        }
        if (User::query()->whereRaw('lower(email) = ?', [$email])->exists()) {
            return 'The work e-mail '.$email.' is already used by another user.';
        }

        return null;
    }
}
