<?php

namespace App\Filament\Resources\Employees\Schemas;

use App\Domain\Configuration\Services\OptionLists;
use App\Domain\Employment\Models\Employee;
use App\Domain\Identity\Enums\UserKind;
use App\Domain\People\Models\Person;
use App\Domain\People\Support\PhoneNumbers;
use App\Filament\Resources\Employees\EmployeeResource;
use App\Filament\Resources\Employees\Pages\CreateEmployee;
use App\Filament\Resources\Employees\Pages\EditEmployee;
use App\Filament\Support\AuditReasonField;
use App\Filament\Support\CustomFieldsSchema;
use App\Filament\Support\DocumentCopies;
use App\Filament\Support\TenantUnique;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Component;
use Filament\Schemas\Components\Fieldset;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Group;
use Filament\Schemas\Components\Livewire;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;

/**
 * Edit form, followed as a wizard on the Edit page (owner, 2026-11-27: every detail of an employee has one place). Its
 * steps are the Profile areas of the 360 in the same order (Personal, Contact & addresses, Employment, Position &
 * reporting, Statutory, Government IDs, Bank & payment, Family & nominees, Education & experience, Health insurance,
 * Other details), so what Profile shows in an area is changed in the step of the same name and nowhere else.
 *
 * Fields are saved together by Save on Review. The lists inside a step (addresses, emergency contacts, government IDs,
 * bank accounts, family, nominations, education, previous employment, insurance) are the record lists themselves
 * (EmployeeResource::STEP_LISTS): each entry is saved, through its governed action, as it is added, changed or removed.
 * Position & reporting and Statutory are written by the same governed actions as Transfer / promote, Change manager and
 * Edit statutory details (EditEmployee), and show only to someone who may make those changes. Lifecycle changes keep
 * their own actions.
 */
class EmployeeForm
{
    /** What a step holding lists says about saving (the fields of the page wait for Save; list entries do not). */
    public const LISTS_SAVE_NOTE = 'Each entry in a list below is saved as soon as you add, change or remove it, and recorded in the audit trail.';

    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Personal')
                    ->description('Name, date of birth and personal particulars.')
                    ->relationship('person')
                    ->columns(3)
                    ->schema(self::personalFields()),
                Section::make('Contact & addresses')
                    ->description('How to reach this person, where they live and whom to call in an emergency. '.self::LISTS_SAVE_NOTE)
                    ->schema([
                        Grid::make(3)->schema([
                            TextInput::make('work_email')->email()->maxLength(255),
                            ...self::phoneFields('work_phone', 'work_phone_country_code', 'Work phone'),
                        ]),
                        Group::make()->relationship('person')->columns(3)->schema(self::personalContactFields()),
                        ...self::lists('contact'),
                    ]),
                Section::make('Employment')
                    ->description('Employee code, dates and sign-in. Joining and lifecycle dates change through their own actions.')
                    ->columns(3)
                    ->schema([
                        TextInput::make('employee_code')->required()->maxLength(32)->alphaDash()->unique(ignoreRecord: true, modifyRuleUsing: TenantUnique::rule()),
                        self::deviceUserIdField(),
                        DatePicker::make('joining_date')->native(false)->disabled()->dehydrated(false)->helperText('Set at hire; change via lifecycle actions.'),
                        DatePicker::make('probation_end_date')->native(false),
                        Select::make('user_id')
                            ->label('Login user')
                            // HROne parity WO-15: business partners' sign-ins are never an employee's.
                            ->relationship('user', 'name', fn ($query) => $query->forCurrentTenant()->where('kind', '!=', UserKind::Partner->value))
                            ->searchable()
                            ->preload()
                            ->helperText('Link the employee to a portal login.'),
                    ]),
                // Owner, 2026-11-27: every detail an employee record needs is on the Edit page.
                Section::make('Position & reporting')
                    ->description('Where this person works and whom they report to. A change starts on the date below and is kept in the position history (Work on the Employee 360), exactly as Transfer / promote and Change manager. Fields you leave as they are stay unchanged.')
                    ->statePath('placement')
                    ->columns(3)
                    ->visible(fn (mixed $record) => $record instanceof Employee && (auth()->user()?->can('assignPosition', $record) ?? false))
                    ->schema([
                        ...CreateEmployee::positionFields(keepCurrent: true),
                        // Today's manager stays a choice even when they are no longer employed (a reassignment is up to HR).
                        Select::make('manager_id')->label('Reporting manager')->searchable()
                            ->options(fn (mixed $record) => CreateEmployee::managerOptions($record instanceof Employee ? $record->id : null)
                                + ($record instanceof Employee && ($held = $record->currentManager?->manager) !== null ? [$held->id => $held->auditLabel()] : [])),
                        Select::make('change_type')->label('Kind of change')->required()
                            ->options(collect(config('peopleos.people.position_change_types'))->except('hire')->all()),
                        DatePicker::make('effective_from')->label('Changes take effect from')->native(false)->required()
                            ->helperText('Used only when the position or the manager changes.'),
                    ]),
                Section::make('Statutory')
                    ->description('PAN, Aadhaar, provident fund, ESIC, professional tax and the tax regime. Saved as "Edit statutory details" does: encrypted, masked in the audit trail.')
                    ->statePath('statutory')
                    ->columns(3)
                    ->visible(fn (mixed $record) => $record instanceof Employee && (auth()->user()?->can('updateSensitive', $record) ?? false))
                    ->schema([...self::statutoryFields(), ...self::statutoryCopyFields()]),
                self::listStep('identity', 'Government IDs', 'Passport, driving licence, voter ID and other government IDs, with their copies. PAN and Aadhaar are in Statutory.'),
                self::listStep('bank', 'Bank & payment', 'The accounts salary is paid into. How salary is paid (transfer, cheque or cash) is kept by date in Payroll → Payment modes.'),
                self::listStep('family', 'Family & nominees', 'Family members, dependants and nominations for each benefit.'),
                Section::make('Education & experience')
                    ->description('Qualifications and earlier employment. '.self::LISTS_SAVE_NOTE)
                    ->visible(fn (mixed $record) => self::anyList('professional', $record))
                    ->schema([
                        Group::make()->relationship('person')->columns(3)->schema([self::fresherField()]),
                        ...self::lists('professional'),
                    ]),
                self::listStep('insurance', 'Health insurance', 'Policies on file and the people they cover.'),
                // Named as on the 360 Profile and in the profile-section matrix (CA-6).
                CustomFieldsSchema::formSection(Employee::class)->heading('Other details')->description('The additional fields your organisation records.'),
                AuditReasonField::make(),
            ]);
    }

    /**
     * A step that is only lists (government IDs, bank accounts, family, insurance): shown while one of them is.
     */
    private static function listStep(string $key, string $heading, string $about): Section
    {
        return Section::make($heading)
            ->description($about.' '.self::LISTS_SAVE_NOTE)
            ->visible(fn (mixed $record) => self::anyList($key, $record))
            ->schema(self::lists($key));
    }

    /** Whether the person may see one of a step's lists for this employee. */
    private static function anyList(string $key, mixed $record): bool
    {
        return $record instanceof Employee
            && collect(EmployeeResource::STEP_LISTS[$key])->contains(fn (string $manager) => $manager::canViewForRecord($record, EditEmployee::class));
    }

    /**
     * A step's record lists, editable where they stand (each mounted as on a record tab, for the Edit page).
     *
     * @return list<Livewire>
     */
    private static function lists(string $key): array
    {
        return array_map(fn (string $manager) => Livewire::make($manager, fn (mixed $record) => ['ownerRecord' => $record, 'pageClass' => EditEmployee::class, ...$manager::getDefaultProperties()])
            ->key('step-list-'.class_basename($manager))
            ->id('list-'.class_basename($manager))
            // The review of the form skips these (its entries are saved on their own, not by Save).
            ->extraAttributes(['class' => 'pos-wizard-list'])
            ->columnSpanFull()
            ->visible(fn (mixed $record) => $record instanceof Employee && $manager::canViewForRecord($record, EditEmployee::class)),
            EmployeeResource::STEP_LISTS[$key]);
    }

    /**
     * Statutory identifiers and applicability, as "Edit statutory details" on the 360 and the Statutory step of the Edit
     * page (written by ChangeStatutoryIdentityAction and ChangeStatutoryApplicabilityAction).
     *
     * @return array<int, Component>
     */
    public static function statutoryFields(): array
    {
        return [
            TextInput::make('pan')->label('PAN')->maxLength(10)->regex('/^[A-Z]{5}[0-9]{4}[A-Z]$/')->helperText('Format ABCDE1234F'),
            TextInput::make('aadhaar_reference')->label('Aadhaar')->maxLength(16)->helperText('A reference or the last digits, unless your policy allows the full number.'),
            Select::make('tax_regime')->label('Tax regime')->options(['old' => 'Old regime', 'new' => 'New regime']),
            Toggle::make('pf_applicable')->label('PF applicable')->inline(false),
            TextInput::make('uan')->label('UAN')->maxLength(12),
            TextInput::make('pf_number')->label('PF number')->maxLength(32),
            Toggle::make('esic_applicable')->label('ESIC applicable')->inline(false),
            TextInput::make('esic_number')->label('ESIC number')->maxLength(32),
            Toggle::make('pt_applicable')->label('Professional tax applicable')->inline(false),
            // A state code the statutory rules use; a code recorded earlier that is not in the list stays selectable.
            Select::make('pt_state_code')->label('Professional tax state')->searchable()
                ->options(fn (mixed $state) => (array) config('peopleos.compliance.states') + (filled($state) && is_string($state) ? [$state => $state] : [])),
        ];
    }

    /**
     * Copies of the cards, filed with the employee's documents beside the numbers (owner, 2026-11-27; DocumentCopies):
     * form field => where it is filed.
     *
     * @var array<string, array{field: string, code: string, title: string}>
     */
    public const STATUTORY_COPIES = [
        'pan_copy' => ['field' => 'statutory.pan', 'code' => 'PAN', 'title' => 'PAN card'],
        'aadhaar_copy' => ['field' => 'statutory.aadhaar', 'code' => 'AADHAAR', 'title' => 'Aadhaar'],
        'uan_copy' => ['field' => 'statutory.uan', 'code' => 'UAN_CARD', 'title' => 'UAN card'],
    ];

    /** @return array<int, Component> */
    public static function statutoryCopyFields(): array
    {
        return [
            DocumentCopies::upload('pan_copy', 'PAN card copy', 'Optional. Filed under Documents as the PAN card, waiting for verification.'),
            DocumentCopies::upload('aadhaar_copy', 'Aadhaar copy', 'Optional. Mask the first 8 digits where your policy asks for it.'),
            DocumentCopies::upload('uan_copy', 'UAN card / EPF passbook', 'Optional.'),
        ];
    }

    /**
     * Every person field, as the hire wizard asks for them in one step.
     *
     * @return array<int, Component>
     */
    public static function personFields(): array
    {
        return [...self::personalFields(), ...self::personalContactFields(), self::fresherField()];
    }

    /**
     * The person's particulars (the Personal step of the Edit page, the Personal area of the 360 Profile).
     *
     * @return array<int, Component>
     */
    public static function personalFields(): array
    {
        return [
            // HROne parity MS-4: the title used on letters and lists (option list `salutations`); blank = from the gender.
            Select::make('salutation')->options(fn () => app(OptionLists::class)->options('salutations'))->placeholder('From the gender (Mr / Ms / Mx)'),
            TextInput::make('first_name')->required()->maxLength(255),
            TextInput::make('middle_name')->maxLength(255),
            TextInput::make('last_name')->maxLength(255),
            TextInput::make('preferred_name')->maxLength(255),
            DatePicker::make('date_of_birth')->native(false)->maxDate(now()->subYears(14)),
            ...self::birthdayFields(),
            Select::make('gender')->options(config('peopleos.people.genders')),
            Select::make('marital_status')->options(config('peopleos.people.marital_statuses')),
            ...self::weddingAnniversaryFields(),
            TextInput::make('nationality')->maxLength(64)->default('Indian'),
            Select::make('blood_group')->options(array_combine(config('peopleos.people.blood_groups'), config('peopleos.people.blood_groups'))),
        ];
    }

    /**
     * The person's own e-mail and phone (the Contact & addresses step).
     *
     * @return array<int, Component>
     */
    public static function personalContactFields(): array
    {
        return [
            TextInput::make('personal_email')->email()->maxLength(255),
            ...self::phoneFields('personal_phone', 'personal_phone_country_code', 'Personal phone'),
        ];
    }

    /** HROne parity MS-4: "I am a fresher" — no earlier employment (refused while previous employment is recorded). */
    public static function fresherField(): Toggle
    {
        return Toggle::make('is_fresher')->label('Fresher (no earlier employment)')->inline(false)
            ->disabled(fn (mixed $record) => $record instanceof Person && ! $record->isFresher() && $record->experiences()->exists())
            ->helperText(fn (mixed $record) => $record instanceof Person && ! $record->isFresher() && $record->experiences()->exists()
                ? 'Previous employment is recorded, so this person is not a fresher.' : 'No previous employer to record.');
    }

    /**
     * HROne parity MS-4 (matrix 22 §22.2.1 #9): a phone with its international dialling code. With a code the number is
     * checked and stored in E.164; without one it is kept as typed, as before.
     *
     * @return array<int, Component>
     */
    public static function phoneFields(string $phone, string $code, string $label, bool $required = false): array
    {
        return [
            Select::make($code)->label($label.': country code')->options(fn () => PhoneNumbers::codes())->searchable()->placeholder('None (kept as typed)')->live(onBlur: true),
            TextInput::make($phone)->label($label)->tel()->maxLength(32)->required($required)
                ->rule(fn (Get $get) => PhoneNumbers::rule(filled($get($code)) ? (string) $get($code) : null)),
        ];
    }

    /** HROne parity MS-4 (matrix 22 §22.2.1 #10): the id an attendance device knows the employee by (unique in the organisation). */
    public static function deviceUserIdField(): TextInput
    {
        return TextInput::make('device_user_id')->label('Biometric / device user id')->maxLength(64)
            ->unique(ignoreRecord: true, modifyRuleUsing: TenantUnique::rule())
            ->helperText('The id the attendance device knows this employee by. Blank: device punches are matched by the employee code, as before.');
    }

    /**
     * The day the person is celebrated, when it differs from the date of birth on official papers (day and month only),
     * and whether colleagues see it. Blank = the date of birth.
     *
     * @return array<int, Component>
     */
    public static function birthdayFields(): array
    {
        return [
            Fieldset::make('Birthday')->columns(3)->columnSpanFull()->schema([
                Select::make('birthday_month')->label('Celebrated in')->placeholder('Same as date of birth')
                    ->options(Person::MONTHS)->live()
                    ->helperText('Only when the real birthday differs from the date of birth on official papers.'),
                Select::make('birthday_day')->label('On day')
                    ->options(fn (Get $get) => ($month = (int) $get('birthday_month')) >= 1 && $month <= 12 ? array_combine($r = range(1, Person::daysIn($month)), $r) : [])
                    ->disabled(fn (Get $get) => blank($get('birthday_month')))
                    ->required(fn (Get $get) => filled($get('birthday_month'))),
                Toggle::make('birthday_shared')->label('Show to colleagues')->default(true)->inline(false)
                    ->helperText('Day and month only, never the year. HR always sees it.'),
            ]),
        ];
    }

    /**
     * HROne setup parity HP-9a: the wedding anniversary (day and month only), celebrated when the organisation turns
     * wedding anniversaries on, and whether colleagues see it (off unless the person chooses).
     *
     * @return array<int, Component>
     */
    public static function weddingAnniversaryFields(): array
    {
        return [
            Fieldset::make('Wedding anniversary')->columns(3)->columnSpanFull()->schema([
                Select::make('wedding_anniversary_month')->label('Month')->placeholder('Not recorded')
                    ->options(Person::MONTHS)->live(),
                Select::make('wedding_anniversary_day')->label('Day')
                    ->options(fn (Get $get) => ($month = (int) $get('wedding_anniversary_month')) >= 1 && $month <= 12 ? array_combine($r = range(1, Person::daysIn($month)), $r) : [])
                    ->disabled(fn (Get $get) => blank($get('wedding_anniversary_month')))
                    ->required(fn (Get $get) => filled($get('wedding_anniversary_month'))),
                Toggle::make('wedding_anniversary_shared')->label('Show to colleagues')->default(false)->inline(false)
                    ->helperText('Day and month only. HR always sees it.'),
            ]),
        ];
    }
}
