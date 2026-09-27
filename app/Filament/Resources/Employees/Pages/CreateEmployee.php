<?php

namespace App\Filament\Resources\Employees\Pages;

use App\Domain\Employment\Actions\HireEmployeeAction;
use App\Domain\Employment\Models\Employee;
use App\Domain\Employment\Models\EmployeePosition;
use App\Domain\Organisation\Models\BusinessUnit;
use App\Domain\Organisation\Models\Company;
use App\Domain\Organisation\Models\CostCentre;
use App\Domain\Organisation\Models\Department;
use App\Domain\Organisation\Models\Designation;
use App\Domain\Organisation\Models\Division;
use App\Domain\Organisation\Models\EmployeeCategory;
use App\Domain\Organisation\Models\EmploymentType;
use App\Domain\Organisation\Models\Grade;
use App\Domain\Organisation\Models\Level;
use App\Domain\Organisation\Models\Location;
use App\Domain\Organisation\Models\Team;
use App\Domain\Organisation\Models\WorkMode;
use App\Domain\People\Models\Person;
use App\Domain\People\Services\PersonMatcher;
use App\Filament\Resources\Employees\EmployeeResource;
use App\Filament\Resources\Employees\Schemas\EmployeeForm;
use App\Filament\Support\AuditReasonField;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\CreateRecord;
use Filament\Resources\Pages\CreateRecord\Concerns\HasWizard;
use Filament\Schemas\Components\Component;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Wizard\Step;
use Filament\Support\Exceptions\Halt;
use Illuminate\Database\Eloquent\Model;

/** Hire wizard: Person → Employment → Position → Manager. Lands in HireEmployeeAction. */
class CreateEmployee extends CreateRecord
{
    use HasWizard;

    protected static string $resource = EmployeeResource::class;

    protected static ?string $title = 'Hire employee';

    protected function getSteps(): array
    {
        return [
            Step::make('Person')
                ->description('Who is joining')
                ->columns(3)
                ->schema([
                    Select::make('existing_person_id')
                        ->label('Existing person (re-use instead of creating a new one)')
                        ->helperText('Pick a person already on record when this hire is a known individual; leave blank to create a new person. Definite duplicates (same email, phone or work email) are refused.')
                        ->searchable()
                        ->getSearchResultsUsing(fn (string $search) => Person::query()->whereDoesntHave('employee')->where(fn ($q) => $q->where('first_name', 'like', "%{$search}%")->orWhere('last_name', 'like', "%{$search}%")->orWhere('personal_email', 'like', "%{$search}%"))->limit(20)->get()->mapWithKeys(fn (Person $p) => [$p->id => $p->display_name.($p->personal_email ? " ({$p->personal_email})" : '')])->all())
                        ->getOptionLabelUsing(fn ($value) => Person::query()->find($value)?->display_name)
                        ->columnSpanFull(),
                    ...EmployeeForm::personFields(),
                ])
                ->afterValidation(function (Get $get): void {
                    if ($get('existing_person_id')) {
                        return;
                    }
                    $person = array_filter(['first_name' => $get('first_name'), 'last_name' => $get('last_name'), 'date_of_birth' => $get('date_of_birth'), 'personal_email' => $get('personal_email'), 'personal_phone' => $get('personal_phone')]);
                    $candidates = app(PersonMatcher::class)->candidates($person, ['work_email' => $get('work_email')]);
                    if ($candidates->isEmpty()) {
                        return;
                    }
                    $definite = $candidates->where('definite', true);
                    Notification::make()
                        ->title($definite->isNotEmpty() ? 'This person already exists' : 'Possible duplicate person')
                        ->body($candidates->map(fn ($c) => $c['name'].($c['employee_code'] ? " ({$c['employee_code']})" : '').' — same '.implode(', ', $c['matched_on']))->implode('; ').($definite->isNotEmpty() ? '. Select the existing person above or correct the details.' : '. Review before continuing.'))
                        ->{$definite->isNotEmpty() ? 'danger' : 'warning'}()
                        ->persistent()
                        ->send();
                    if ($definite->isNotEmpty()) {
                        throw new Halt;
                    }
                }),
            Step::make('Employment')
                ->description('When and how to reach them')
                ->columns(3)
                ->schema([
                    DatePicker::make('joining_date')->native(false)->required()->default(now()),
                    DatePicker::make('probation_end_date')->native(false)->helperText('Blank uses the tenant default probation length.'),
                    TextInput::make('employee_code')->maxLength(32)->alphaDash()->unique('employees', 'employee_code')->helperText('Blank generates the next code.'),
                    TextInput::make('work_email')->email()->maxLength(255),
                    TextInput::make('work_phone')->tel()->maxLength(32),
                ]),
            Step::make('Position')
                ->description('Where they sit in the organisation')
                ->columns(3)
                ->schema(self::positionFields(required: true)),
            Step::make('Manager')
                ->description('Reporting line and reason')
                ->schema([
                    Select::make('manager_id')
                        ->label('Line manager')
                        ->options(fn () => self::managerOptions())
                        ->searchable(),
                    AuditReasonField::make(),
                ]),
        ];
    }

    /** @return array<int, Component> */
    public static function positionFields(bool $required = false): array
    {
        $select = fn (string $name, string $label, string $model) => Select::make($name)
            ->label($label)
            ->options(fn () => $model::query()->orderBy('name')->pluck('name', 'id')->all())
            ->searchable();

        return [
            $select('company_id', 'Company', Company::class)->required($required),
            $select('location_id', 'Location', Location::class),
            $select('business_unit_id', 'Business unit', BusinessUnit::class),
            $select('division_id', 'Division', Division::class),
            $select('department_id', 'Department', Department::class),
            $select('team_id', 'Team', Team::class),
            $select('designation_id', 'Designation', Designation::class),
            $select('level_id', 'Level', Level::class),
            $select('grade_id', 'Grade', Grade::class),
            $select('employment_type_id', 'Employment type', EmploymentType::class),
            $select('employee_category_id', 'Employee category', EmployeeCategory::class),
            $select('work_mode_id', 'Work mode', WorkMode::class),
            $select('cost_centre_id', 'Cost centre', CostCentre::class),
        ];
    }

    /** @return array<int, string> */
    public static function managerOptions(?int $exclude = null): array
    {
        return Employee::query()
            ->with('person')
            ->employed()
            ->when($exclude, fn ($q) => $q->whereKeyNot($exclude))
            ->get()
            ->sortBy(fn (Employee $e) => $e->person->display_name)
            ->mapWithKeys(fn (Employee $e) => [$e->id => $e->auditLabel()])
            ->all();
    }

    protected function handleRecordCreation(array $data): Model
    {
        $reason = AuditReasonField::extract($data);
        $personKeys = ['first_name', 'middle_name', 'last_name', 'preferred_name', 'date_of_birth', 'gender', 'marital_status', 'nationality', 'blood_group', 'personal_email', 'personal_phone'];
        $employeeKeys = ['joining_date', 'probation_end_date', 'employee_code', 'work_email', 'work_phone'];

        $person = array_filter(array_intersect_key($data, array_flip($personKeys)), fn ($v) => $v !== null && $v !== '');
        if (! empty($data['existing_person_id'])) {
            $person = ['id' => (int) $data['existing_person_id']];
        }

        return app(HireEmployeeAction::class)->handle(
            person: $person,
            employee: array_filter(array_intersect_key($data, array_flip($employeeKeys)), fn ($v) => $v !== null && $v !== ''),
            position: array_filter(array_intersect_key($data, EmployeePosition::DIMENSIONS)),
            managerId: isset($data['manager_id']) ? (int) $data['manager_id'] : null,
            reason: $reason,
        );
    }

    protected function getRedirectUrl(): string
    {
        return EmployeeResource::getUrl('view', ['record' => $this->getRecord()]);
    }
}
