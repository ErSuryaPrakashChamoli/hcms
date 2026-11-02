<?php

namespace App\Filament\Resources\Employees\Schemas;

use App\Domain\Configuration\Services\OptionLists;
use App\Domain\Employment\Models\Employee;
use App\Domain\Identity\Enums\UserKind;
use App\Domain\People\Models\Person;
use App\Domain\People\Support\PhoneNumbers;
use App\Filament\Support\AuditReasonField;
use App\Filament\Support\CustomFieldsSchema;
use App\Filament\Support\TenantUnique;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Component;
use Filament\Schemas\Components\Fieldset;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;

/** Edit form: person identity + employment contact fields. Placement and lifecycle have their own actions. */
class EmployeeForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Person')
                    ->relationship('person')
                    ->columns(3)
                    ->schema(self::personFields()),
                Section::make('Employment')
                    ->columns(3)
                    ->schema([
                        TextInput::make('employee_code')->required()->maxLength(32)->alphaDash()->unique(ignoreRecord: true, modifyRuleUsing: TenantUnique::rule()),
                        self::deviceUserIdField(),
                        TextInput::make('work_email')->email()->maxLength(255),
                        ...self::phoneFields('work_phone', 'work_phone_country_code', 'Work phone'),
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
                CustomFieldsSchema::formSection(Employee::class),
                AuditReasonField::make(),
            ]);
    }

    /** @return array<int, Component> */
    public static function personFields(): array
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
            TextInput::make('personal_email')->email()->maxLength(255),
            ...self::phoneFields('personal_phone', 'personal_phone_country_code', 'Personal phone'),
            // HROne parity MS-4: "I am a fresher" — no earlier employment (refused while previous employment is recorded).
            Toggle::make('is_fresher')->label('Fresher (no earlier employment)')->inline(false)
                ->disabled(fn (mixed $record) => $record instanceof Person && ! $record->isFresher() && $record->experiences()->exists())
                ->helperText(fn (mixed $record) => $record instanceof Person && ! $record->isFresher() && $record->experiences()->exists()
                    ? 'Previous employment is recorded, so this person is not a fresher.' : 'No previous employer to record.'),
        ];
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
