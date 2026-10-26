<?php

namespace App\Filament\Resources\Employees\Schemas;

use App\Domain\Employment\Models\Employee;
use App\Domain\People\Models\Person;
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
                        TextInput::make('work_email')->email()->maxLength(255),
                        TextInput::make('work_phone')->tel()->maxLength(32),
                        DatePicker::make('joining_date')->native(false)->disabled()->dehydrated(false)->helperText('Set at hire; change via lifecycle actions.'),
                        DatePicker::make('probation_end_date')->native(false),
                        Select::make('user_id')
                            ->label('Login user')
                            ->relationship('user', 'name', fn ($query) => $query->forCurrentTenant())
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
            TextInput::make('first_name')->required()->maxLength(255),
            TextInput::make('middle_name')->maxLength(255),
            TextInput::make('last_name')->maxLength(255),
            TextInput::make('preferred_name')->maxLength(255),
            DatePicker::make('date_of_birth')->native(false)->maxDate(now()->subYears(14)),
            ...self::birthdayFields(),
            Select::make('gender')->options(config('peopleos.people.genders')),
            Select::make('marital_status')->options(config('peopleos.people.marital_statuses')),
            TextInput::make('nationality')->maxLength(64)->default('Indian'),
            Select::make('blood_group')->options(array_combine(config('peopleos.people.blood_groups'), config('peopleos.people.blood_groups'))),
            TextInput::make('personal_email')->email()->maxLength(255),
            TextInput::make('personal_phone')->tel()->maxLength(32),
        ];
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
}
