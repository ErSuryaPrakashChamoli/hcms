<?php

namespace App\Filament\Resources\Employees\Pages;

use App\Domain\Employment\Actions\HireEmployeeAction;
use App\Domain\Employment\Exceptions\EmployeeCodeTakenException;
use App\Domain\Employment\Models\Employee;
use App\Domain\Employment\Models\EmployeeCodeSeries;
use App\Domain\Employment\Models\EmployeePosition;
use App\Domain\Employment\Services\EmployeeCodeGenerator;
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
use App\Filament\Support\Pages\PeopleCreateRecord;
use Closure;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\ToggleButtons;
use Filament\Infolists\Components\TextEntry;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\CreateRecord\Concerns\HasWizard;
use Filament\Schemas\Components\Component;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Components\Wizard\Step;
use Filament\Support\Enums\FontFamily;
use Filament\Support\Enums\FontWeight;
use Filament\Support\Enums\TextSize;
use Filament\Support\Exceptions\Halt;
use Illuminate\Database\Eloquent\Model;

/**
 * Hire wizard: Person → Employment → Position → Manager. Lands in HireEmployeeAction. The company is chosen with the
 * employment so the code can come from its series (auto), or be typed and looked up across the tenant (manual).
 */
class CreateEmployee extends PeopleCreateRecord
{
    use HasWizard {
        getWizardComponent as filamentWizardComponent;
    }

    protected static string $resource = EmployeeResource::class;

    protected static ?string $title = 'Hire employee';

    /** Laid out like every other record wizard: the steps in a rail beside the step (FormWizard). */
    public function getWizardComponent(): Component
    {
        return $this->filamentWizardComponent()->extraAttributes(['class' => 'pos-wizard pos-wizard-page'], merge: true);
    }

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
                ->description('Company, joining and employee code')
                ->columns(3)
                ->schema([
                    Select::make('company_id')->label('Company')->required()->searchable()->live()
                        ->options(fn () => Company::query()->orderBy('name')->pluck('name', 'id')->all())
                        ->default(fn () => ($only = Company::query()->limit(2)->pluck('id'))->count() === 1 ? $only->first() : null)
                        ->afterStateUpdated(fn (Get $get, Set $set) => app(EmployeeCodeGenerator::class)->allowsManual(self::companyId($get), self::employmentTypeId($get)) ? null : $set('employee_code_mode', 'auto'))
                        ->helperText('The employing company; its series makes the employee code.'),
                    DatePicker::make('joining_date')->native(false)->required()->default(now()),
                    DatePicker::make('probation_end_date')->native(false)->helperText('Blank uses the tenant default probation length.'),
                    ToggleButtons::make('employee_code_mode')->label('Employee code')->inline()->live()->required()->default('auto')
                        ->options(fn (Get $get) => self::codeModes(self::companyId($get), self::employmentTypeId($get)))
                        ->icons(['auto' => 'heroicon-m-sparkles', 'manual' => 'heroicon-m-pencil-square'])
                        ->helperText(fn (Get $get) => app(EmployeeCodeGenerator::class)->allowsManual(self::companyId($get), self::employmentTypeId($get)) ? null : 'This company’s series does not allow typing a code by hand.'),
                    TextEntry::make('employee_code_preview')->label('Next code in the series')->columnSpan(2)
                        ->visible(fn (Get $get) => $get('employee_code_mode') !== 'manual')
                        ->state(fn (Get $get) => self::companyId($get) === null ? null : app(EmployeeCodeGenerator::class)->preview(self::companyId($get), self::employmentTypeId($get)))
                        ->placeholder('Choose the company first')
                        ->fontFamily(FontFamily::Mono)->weight(FontWeight::Bold)->size(TextSize::Large)
                        ->helperText(fn (Get $get) => self::seriesNote(self::companyId($get), self::employmentTypeId($get))),
                    TextInput::make('employee_code')->label('Code to use')->columnSpan(2)->maxLength(32)->alphaDash()
                        ->visible(fn (Get $get) => $get('employee_code_mode') === 'manual')
                        ->required(fn (Get $get) => $get('employee_code_mode') === 'manual')
                        ->live(onBlur: true)
                        ->afterStateUpdated(fn (TextInput $component, $livewire) => $livewire->validateOnly($component->getStatePath()))
                        ->rule(fn () => function (string $attribute, mixed $value, Closure $fail): void {
                            if (($message = self::codeTakenMessage((string) $value)) !== null) {
                                $fail($message);
                            }
                        })
                        ->helperText(fn (?string $state) => blank($state)
                            ? 'Checked against every employee of every company before you can continue.'
                            : (self::codeTakenMessage($state) === null ? '✓ '.trim($state).' is free.' : null)),
                    TextInput::make('work_email')->email()->maxLength(255),
                    TextInput::make('work_phone')->tel()->maxLength(32),
                ]),
            Step::make('Position')
                ->description('Where they sit in the organisation')
                ->columns(3)
                ->schema(self::positionFields(company: false)),
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
    public static function positionFields(bool $required = false, bool $company = true): array
    {
        $select = fn (string $name, string $label, string $model) => Select::make($name)
            ->label($label)
            ->options(fn () => $model::query()->orderBy('name')->pluck('name', 'id')->all())
            ->searchable();

        return [
            ...($company ? [$select('company_id', 'Company', Company::class)->required($required)] : []),
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

    /** @return array<string, string> */
    private static function codeModes(?int $companyId, ?int $employmentTypeId = null): array
    {
        return ['auto' => 'Auto-generate', ...(app(EmployeeCodeGenerator::class)->allowsManual($companyId, $employmentTypeId) ? ['manual' => 'Enter manually'] : [])];
    }

    private static function companyId(Get $get): ?int
    {
        return filled($get('company_id')) ? (int) $get('company_id') : null;
    }

    /** The employment type chosen on the Position step (an employment type may have its own code series). */
    private static function employmentTypeId(Get $get): ?int
    {
        return filled($get('employment_type_id')) ? (int) $get('employment_type_id') : null;
    }

    /** Where the auto code comes from, so HR can tell the series is the one they expect. */
    private static function seriesNote(?int $companyId, ?int $employmentTypeId = null): ?string
    {
        if ($companyId === null) {
            return null;
        }
        $series = app(EmployeeCodeGenerator::class)->seriesFor($companyId, $employmentTypeId);
        $taken = ' Taken when you create the employee; if someone else hires first, the next free code is used.';
        $byType = $employmentTypeId === null && EmployeeCodeSeries::query()->whereNotNull('employment_type_id')
            ->where(fn ($q) => $q->whereNull('company_id')->orWhere('company_id', $companyId))->exists()
            ? ' Some employment types have their own series: the code follows the employment type chosen under Position.' : '';

        return match (true) {
            $series === null => 'No code series is set up, so the tenant default pattern is used (People Setup → Employee code series).'.$taken.$byType,
            $series->employment_type_id !== null => 'Series '.$series->pattern().' for '.$series->employmentType?->name.($series->company_id === null ? '' : ' of '.$series->company?->name).'.'.$taken,
            $series->company_id === null => 'Default series '.$series->pattern().'.'.$taken.$byType,
            default => 'Series '.$series->pattern().' of '.$series->company?->name.'.'.$taken.$byType,
        };
    }

    /** Null when the code is free in the tenant. Names the holder only when the viewer may see them. */
    private static function codeTakenMessage(string $code): ?string
    {
        if (blank($code) || ($holder = app(EmployeeCodeGenerator::class)->holder($code)) === null) {
            return null;
        }
        $visible = Employee::query()->with('person')->find($holder->getKey());

        return 'Employee code '.$holder->employee_code.' is already used'.($visible ? ' by '.$visible->person?->display_name : ' by another employee').'.';
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
        $personKeys = ['first_name', 'middle_name', 'last_name', 'preferred_name', 'date_of_birth', 'birthday_month', 'birthday_day', 'birthday_shared', 'wedding_anniversary_month', 'wedding_anniversary_day', 'wedding_anniversary_shared', 'gender', 'marital_status', 'nationality', 'blood_group', 'personal_email', 'personal_phone'];
        $employeeKeys = ['joining_date', 'probation_end_date', 'employee_code', 'work_email', 'work_phone'];

        $person = array_filter(array_intersect_key($data, array_flip($personKeys)), fn ($v) => $v !== null && $v !== '');
        if (! empty($data['existing_person_id'])) {
            $person = ['id' => (int) $data['existing_person_id']];
        }

        if (($data['employee_code_mode'] ?? 'auto') !== 'manual') {
            unset($data['employee_code']);
        } elseif (! app(EmployeeCodeGenerator::class)->allowsManual(isset($data['company_id']) ? (int) $data['company_id'] : null, isset($data['employment_type_id']) ? (int) $data['employment_type_id'] : null)) {
            Notification::make()->danger()->title('Codes cannot be typed by hand for this company')->body('Its employee code series only allows auto-generated codes. Go back to Employment and choose Auto-generate.')->persistent()->send();

            throw new Halt;
        }

        try {
            return app(HireEmployeeAction::class)->handle(
                person: $person,
                employee: array_filter(array_intersect_key($data, array_flip($employeeKeys)), fn ($v) => $v !== null && $v !== ''),
                position: array_filter(array_intersect_key($data, EmployeePosition::DIMENSIONS)),
                managerId: isset($data['manager_id']) ? (int) $data['manager_id'] : null,
                reason: $reason,
            );
        } catch (EmployeeCodeTakenException $e) {
            Notification::make()->danger()->title('Employee code already in use')->body($e->getMessage().' Go back to Employment and choose another code.')->persistent()->send();

            throw new Halt;
        }
    }

    protected function getRedirectUrl(): string
    {
        return EmployeeResource::getUrl('view', ['record' => $this->getRecord()]);
    }
}
