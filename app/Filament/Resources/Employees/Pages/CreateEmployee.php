<?php

namespace App\Filament\Resources\Employees\Pages;

use App\Domain\Employment\Actions\HireWithDetailsAction;
use App\Domain\Employment\Exceptions\EmployeeCodeTakenException;
use App\Domain\Employment\Models\Employee;
use App\Domain\Employment\Models\EmployeeCodeSeries;
use App\Domain\Employment\Models\EmployeePosition;
use App\Domain\Employment\Services\EmployeeCodeGenerator;
use App\Domain\Employment\Services\ReturningEmployees;
use App\Domain\Identity\Services\BulkInvitations;
use App\Domain\Organisation\Models\BusinessUnit;
use App\Domain\Organisation\Models\Company;
use App\Domain\Organisation\Models\CostCentre;
use App\Domain\Organisation\Models\Department;
use App\Domain\Organisation\Models\Designation;
use App\Domain\Organisation\Models\DimensionValue;
use App\Domain\Organisation\Models\Division;
use App\Domain\Organisation\Models\EmployeeCategory;
use App\Domain\Organisation\Models\EmploymentType;
use App\Domain\Organisation\Models\Grade;
use App\Domain\Organisation\Models\Level;
use App\Domain\Organisation\Models\Location;
use App\Domain\Organisation\Models\Team;
use App\Domain\Organisation\Models\WorkMode;
use App\Domain\Organisation\Support\DimensionLabel;
use App\Domain\People\Models\Person;
use App\Domain\People\Services\PersonMatcher;
use App\Domain\Workforce\Models\Position;
use App\Filament\Resources\Employees\EmployeeResource;
use App\Filament\Resources\Employees\RelationManagers\AddressesRelationManager;
use App\Filament\Resources\Employees\RelationManagers\DocumentsRelationManager;
use App\Filament\Resources\Employees\Schemas\EmployeeForm;
use App\Filament\Support\AuditReasonField;
use App\Filament\Support\HireWizardSections;
use App\Filament\Support\Pages\PeopleCreateRecord;
use Closure;
use Filament\Forms\Components\Checkbox;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\ToggleButtons;
use Filament\Infolists\Components\TextEntry;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\CreateRecord\Concerns\HasWizard;
use Filament\Resources\RelationManagers\RelationGroup;
use Filament\Schemas\Components\Component;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Components\View as SchemaView;
use Filament\Schemas\Components\Wizard\Step;
use Filament\Schemas\Schema;
use Filament\Support\Enums\FontFamily;
use Filament\Support\Enums\FontWeight;
use Filament\Support\Enums\TextSize;
use Filament\Support\Exceptions\Halt;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Url;
use RuntimeException;

/**
 * Hire wizard: Person → Employment → Position → Manager. Lands in HireEmployeeAction. The company is chosen with the
 * employment so the code can come from its series (auto), or be typed and looked up across the tenant (manual).
 *
 * HROne parity WO-3 (matrix 23 §23.2.3 #7–8, 12, 15–18, 22): optional steps around those four — identity numbers (PAN,
 * Aadhaar reference) with the returning-employee check, a starting state for historic loads, a position (seat), the
 * functional manager and HR business partner, addresses, family, bank, previous employment, other details and "create a
 * sign-in and invite" — all through HireWithDetailsAction (the 360's own actions), then a completion panel instead of
 * going straight to the Employee 360. Left empty, the hire creates exactly what it created before.
 */
class CreateEmployee extends PeopleCreateRecord
{
    use HasWizard {
        getWizardComponent as filamentWizardComponent;
    }

    protected static string $resource = EmployeeResource::class;

    protected static ?string $title = 'Hire employee';

    /** HROne parity WO-3 (§23.2.3 #22): what the hire created and skipped, shown once after it (from the session). */
    #[Locked]
    public ?array $completion = null;

    /** HROne parity AC-1: "Add new hire (joining later)" from the Actions panel — the joining date starts blank and in the future. */
    #[Url]
    public bool $upcoming = false;

    /** @var array<string, list<array<string, mixed>>> the returning-employee matches of this request, by input */
    private array $returningMemo = [];

    public function mount(): void
    {
        parent::mount();
        $this->completion = $this->completionFromSession();
    }

    public function getTitle(): string|Htmlable
    {
        return $this->completion !== null ? 'Employee hired' : ($this->upcoming ? 'Add new hire (joining later)' : parent::getTitle());
    }

    public function getSubheading(): ?string
    {
        return $this->completion !== null ? 'What the hire created, what was left for later, and where to go next.' : parent::getSubheading();
    }

    /** HROne parity WO-3: after a hire the page shows the completion panel instead of the wizard. */
    public function content(Schema $schema): Schema
    {
        if ($this->completion === null) {
            return parent::content($schema);
        }

        return $schema->components([
            SchemaView::make('filament.employees.hire-completion')->viewData(fn () => ['summary' => $this->completion, 'links' => $this->completionLinks()]),
        ]);
    }

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
                        ->body($candidates->map(fn ($c) => $c['name'].($c['employee_code'] ? " ({$c['employee_code']})" : '').' — same '.implode(', ', $c['matched_on']))->implode('; ').($definite->isNotEmpty() ? '. Select the existing person above or correct the details.' : '. Review before continuing.')
                            // HROne parity WO-3 (§23.2.3 #8): an earlier employee comes back on their own record.
                            .($definite->contains(fn ($c) => filled($c['employee_code'])) ? ' Someone who worked here before comes back through "Rehire / reactivate" on their Employee 360.' : ''))
                        ->{$definite->isNotEmpty() ? 'danger' : 'warning'}()
                        ->persistent()
                        ->send();
                    if ($definite->isNotEmpty()) {
                        throw new Halt;
                    }
                }),
            // HROne parity WO-3 (§23.2.3 #7): PAN and Aadhaar reference at hire, as "Edit statutory details" on the 360.
            Step::make('Identity numbers')
                ->description('PAN and Aadhaar reference (optional)')
                ->columns(2)
                ->visible(fn () => self::allows('employee.sensitive.update'))
                ->schema([
                    ...HireWizardSections::identityFields(),
                    TextEntry::make('identity_note')->hiddenLabel()->columnSpanFull()
                        ->state('Optional: leave blank to add them later on the Employee 360. They are encrypted, masked in the audit trail and compared with earlier employees (never shown to anyone without the right to see them).'),
                ]),
            // HROne parity WO-3 (§23.2.3 #8): someone who worked here before, found on PAN / Aadhaar or the person's details.
            Step::make('Returning employee')
                ->description(fn (Get $get) => app(ReturningEmployees::class)->needsConfirmation($this->returningMatches($get)) ? 'Not eligible for rehire: confirm to continue' : 'Earlier employment found')
                ->visible(fn (Get $get) => $this->returningMatches($get) !== [])
                ->schema([
                    TextEntry::make('returning_matches')->label('It seems this person has worked with your organisation before')
                        ->state(fn (Get $get) => array_map(fn (array $m) => ReturningEmployees::describe($m), $this->returningMatches($get)))
                        ->listWithLineBreaks()->bulleted(),
                    TextEntry::make('returning_note')->hiddenLabel()
                        ->state('To bring an earlier employee back on the same record (same code and history), use "Rehire / reactivate" on their Employee 360 instead. Continuing here creates a new employee record.'),
                    Checkbox::make('returning_confirmed')->label('I have checked the earlier record and still want to hire this person')
                        ->visible(fn (Get $get) => app(ReturningEmployees::class)->needsConfirmation($this->returningMatches($get)))
                        ->accepted(fn (Get $get) => app(ReturningEmployees::class)->needsConfirmation($this->returningMatches($get))),
                    Textarea::make('returning_reason')->label('Reason for hiring despite the earlier record')->rows(2)->maxLength(500)
                        ->helperText('Recorded in the audit trail with your name and time.')
                        ->visible(fn (Get $get) => app(ReturningEmployees::class)->needsConfirmation($this->returningMatches($get)))
                        ->required(fn (Get $get) => app(ReturningEmployees::class)->needsConfirmation($this->returningMatches($get))),
                ]),
            Step::make('Employment')
                ->description('Company, joining and employee code')
                ->columns(3)
                ->schema([
                    Select::make('company_id')->label('Company')->required()->searchable()->live()
                        ->options(fn () => Company::query()->orderBy('name')->pluck('name', 'id')->all())
                        ->default(fn () => ($only = Company::query()->limit(2)->pluck('id'))->count() === 1 ? $only->first() : null)
                        ->afterStateUpdated(fn (Get $get, Set $set) => app(EmployeeCodeGenerator::class)->allowsManual(self::companyId($get), self::employmentTypeId($get)) ? null : $set('employee_code_mode', 'auto'))
                        ->helperText('The employing company; its series makes the employee code.'),
                    DatePicker::make('joining_date')->native(false)->required()->default(fn () => $this->upcoming ? null : now())
                        ->minDate(fn () => $this->upcoming ? today()->addDay() : null)
                        ->helperText(fn () => $this->upcoming ? 'A future date: the new hire stays in pre-boarding until then.' : null),
                    DatePicker::make('probation_end_date')->native(false)->helperText('Blank uses the tenant default probation length.'),
                    // HROne parity WO-3 (§23.2.3 #12): historic loads may start confirmed or active; blank = as before.
                    Select::make('start_state')->label('Starting state')->live()
                        ->options(['confirmed' => 'Already confirmed (historic load)', 'active' => 'Active, without probation (historic load)'])
                        ->placeholder('As usual: pre-boarding before the joining date, then joined and on probation')
                        ->visible(fn () => self::allows('employee.lifecycle'))
                        ->rule(fn (Get $get) => function (string $attribute, mixed $value, Closure $fail) use ($get): void {
                            if (filled($value) && filled($get('joining_date')) && Carbon::parse($get('joining_date'))->startOfDay()->isFuture()) {
                                $fail('Only someone who has already joined can start this way: a future joining date starts in pre-boarding.');
                            }
                        }),
                    DatePicker::make('confirmation_date')->label('Confirmed on')->native(false)->maxDate(now())->afterOrEqual('joining_date')
                        ->visible(fn (Get $get) => self::allows('employee.lifecycle') && $get('start_state') === 'confirmed')
                        ->required(fn (Get $get) => $get('start_state') === 'confirmed'),
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
                    // HROne parity MS-4: the work phone's country code (E.164) and the attendance device user id.
                    ...EmployeeForm::phoneFields('work_phone', 'work_phone_country_code', 'Work phone'),
                    EmployeeForm::deviceUserIdField(),
                ]),
            Step::make('Position')
                ->description('Where they sit in the organisation')
                ->columns(3)
                ->schema([
                    ...self::positionFields(company: false),
                    // HROne parity WO-3 (§23.2.3 #15): an open seat of the workforce plan, validated as "Transfer / promote" does.
                    Select::make('position_id')->label('Position (seat)')->searchable()
                        ->options(fn () => Position::query()->where('status', 'open')->orderBy('code')->limit(500)->get(['id', 'code', 'title'])->mapWithKeys(fn ($p) => [$p->id => "{$p->code} · {$p->title}"])->all())
                        ->visible(fn () => self::allows('workforce.view') || self::allows('workforce.manage'))
                        ->helperText('Optional: an open position. Its organisation fills any field left blank here; capacity is checked when you hire.'),
                ]),
            Step::make('Manager')
                ->description('Reporting line and reason')
                ->schema([
                    Select::make('manager_id')
                        ->label('Line manager')
                        ->options(fn () => self::managerOptions())
                        ->searchable(),
                    // HROne parity WO-3 (§23.2.3 #16): the other reporting lines, from the joining date (as "Change manager").
                    Select::make('functional_manager_id')->label(config('peopleos.people.reporting_types.functional'))
                        ->options(fn () => self::managerOptions())->searchable(),
                    Select::make('hrbp_id')->label(config('peopleos.people.reporting_types.hrbp'))
                        ->options(fn () => self::managerOptions())->searchable(),
                    AuditReasonField::make(),
                ]),
            // HROne parity WO-3 (§23.2.3 #17): the Additional Details sections, each optional and written by the 360's action.
            Step::make('Address')
                ->description('Optional')
                ->schema([HireWizardSections::addresses()]),
            Step::make('Family')
                ->description('Optional')
                ->schema([HireWizardSections::family()]),
            Step::make('Bank')
                ->description('Optional')
                ->visible(fn () => self::allows('employee.sensitive.update'))
                ->schema(HireWizardSections::bank()),
            Step::make('Previous employment')
                ->description('Optional')
                ->schema([
                    HireWizardSections::experiences(),
                    TextEntry::make('fresher_note')->hiddenLabel()->state('Marked as a fresher on the Person step: no earlier employer to record.')
                        ->visible(fn (Get $get) => (bool) $get('is_fresher')),
                ]),
            Step::make('Other details')
                ->description('Optional')
                ->visible(fn () => self::allows('employee.update') && HireWizardSections::hasCustomFields())
                ->schema(HireWizardSections::customFields()),
            // HROne parity WO-3 (§23.2.3 #18): WO-16's invitation for this one person, off by default.
            Step::make('Sign-in')
                ->description('Optional')
                ->visible(fn () => self::allows('user.create'))
                ->schema(HireWizardSections::signIn()),
        ];
    }

    private static function allows(string $permission): bool
    {
        return auth()->user()?->hasPermission($permission) ?? false;
    }

    /**
     * HROne parity WO-3: the returning-employee matches for what the wizard holds now (person, work e-mail, identifiers),
     * worked out once per request and input.
     *
     * @return list<array<string, mixed>>
     */
    private function returningMatches(Get $get): array
    {
        $user = auth()->user();
        if ($user === null || $this->completion !== null) {
            return [];
        }
        $person = filled($get('existing_person_id')) ? [] : ['first_name' => $get('first_name'), 'last_name' => $get('last_name'), 'date_of_birth' => $get('date_of_birth'), 'personal_email' => $get('personal_email'), 'personal_phone' => $get('personal_phone')];
        $employee = ['work_email' => $get('work_email')];
        $identifiers = self::allows('employee.sensitive.update') ? ['pan' => $get('pan'), 'aadhaar_reference' => $get('aadhaar_reference')] : [];
        $key = md5((string) json_encode([$person, $employee, $identifiers]));

        return $this->returningMemo[$key] ??= app(ReturningEmployees::class)->matches($user, $person, $employee, $identifiers);
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
            // Data import DI-1 (decision D1): the organisation's own dimension, under the name it gives it.
            $select('dimension_value_id', DimensionLabel::name(), DimensionValue::class),
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
        $personKeys = ['first_name', 'middle_name', 'last_name', 'preferred_name', 'date_of_birth', 'birthday_month', 'birthday_day', 'birthday_shared', 'wedding_anniversary_month', 'wedding_anniversary_day', 'wedding_anniversary_shared', 'gender', 'marital_status', 'nationality', 'blood_group', 'personal_email', 'personal_phone',
            'salutation', 'is_fresher', 'personal_phone_country_code'];
        $employeeKeys = ['joining_date', 'probation_end_date', 'employee_code', 'work_email', 'work_phone', 'work_phone_country_code', 'device_user_id'];

        $person = array_filter(array_intersect_key($data, array_flip($personKeys)), fn ($v) => $v !== null && $v !== '');
        if (empty($person['is_fresher'])) {
            unset($person['is_fresher']); // MS-4: recorded only when ticked (the column defaults to off)
        }
        if (! empty($data['existing_person_id'])) {
            $person = ['id' => (int) $data['existing_person_id']];
        }

        if (($data['employee_code_mode'] ?? 'auto') !== 'manual') {
            unset($data['employee_code']);
        } elseif (! app(EmployeeCodeGenerator::class)->allowsManual(isset($data['company_id']) ? (int) $data['company_id'] : null, isset($data['employment_type_id']) ? (int) $data['employment_type_id'] : null)) {
            Notification::make()->danger()->title('Codes cannot be typed by hand for this company')->body('Its employee code series only allows auto-generated codes. Go back to Employment and choose Auto-generate.')->persistent()->send();

            throw new Halt;
        }

        $position = array_filter(array_intersect_key($data, EmployeePosition::DIMENSIONS));
        if (filled($data['position_id'] ?? null)) {
            $position['position_id'] = (int) $data['position_id'];
        }

        try {
            $outcome = app(HireWithDetailsAction::class)->handle(
                actor: auth()->user(),
                person: $person,
                employee: array_filter(array_intersect_key($data, array_flip($employeeKeys)), fn ($v) => $v !== null && $v !== ''),
                position: $position,
                managerId: isset($data['manager_id']) ? (int) $data['manager_id'] : null,
                reason: $reason,
                details: self::details($data),
            );
        } catch (EmployeeCodeTakenException $e) {
            Notification::make()->danger()->title('Employee code already in use')->body($e->getMessage().' Go back to Employment and choose another code.')->persistent()->send();

            throw new Halt;
        } catch (RuntimeException|\InvalidArgumentException $e) {
            // HROne parity WO-3: a refusal by any part (the 360 actions, the seat, the starting state, the returning check)
            // leaves nothing behind: the hire is one transaction.
            Notification::make()->danger()->title('Not hired')->body($e->getMessage())->persistent()->send();

            throw new Halt;
        }

        $employee = $outcome['employee'];
        session()->put(self::completionKey((int) $employee->getKey()), [
            'employee_id' => (int) $employee->getKey(),
            'name' => (string) $employee->person?->display_name,
            'code' => (string) $employee->employee_code,
            'state' => $employee->lifecycle_state?->getLabel(),
            'created' => $outcome['created'],
            'skipped' => $outcome['skipped'],
            'invitation' => $outcome['invitation'],
            'invitation_refused' => $outcome['invitation_refused'],
        ]);

        return $employee;
    }

    /**
     * The optional parts as HireWithDetailsAction takes them. A step that is not shown (no right, or left off) sends
     * nothing, so it records nothing.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private static function details(array $data): array
    {
        $rows = fn (string $key) => array_values(array_filter((array) ($data[$key] ?? []), 'is_array'));

        return [
            'start_state' => $data['start_state'] ?? null,
            'confirmation_date' => $data['confirmation_date'] ?? null,
            'functional_manager_id' => filled($data['functional_manager_id'] ?? null) ? (int) $data['functional_manager_id'] : null,
            'hrbp_id' => filled($data['hrbp_id'] ?? null) ? (int) $data['hrbp_id'] : null,
            'statutory' => ['pan' => $data['pan'] ?? null, 'aadhaar_reference' => $data['aadhaar_reference'] ?? null],
            'returning_confirmation' => ! empty($data['returning_confirmed']) ? ($data['returning_reason'] ?? null) : null,
            'addresses' => $rows('addresses'),
            'family' => $rows('family'),
            'bank' => ! empty($data['add_bank']) && is_array($data['bank'] ?? null) ? $data['bank'] : null,
            'experiences' => empty($data['is_fresher']) ? $rows('experiences') : [],
            'custom_fields' => ! empty($data['fill_custom_fields']) ? (array) ($data['hire_custom_fields'] ?? []) : null,
            // The roles field shows only to people who may give roles; unseen, the WO-16 default applies (the Employee role, if allowed).
            'invite' => ! empty($data['invite_user']) ? ['roles' => is_array($data['invite_roles'] ?? null) ? array_map('intval', $data['invite_roles'])
                : app(BulkInvitations::class)->defaultRoleIds(auth()->user())] : null,
        ];
    }

    /** HROne parity WO-3 (§23.2.3 #22): back to this page, which shows the completion panel once. */
    protected function getRedirectUrl(): string
    {
        return static::getUrl(['hired' => $this->getRecord()->getKey()]);
    }

    private static function completionKey(int $employeeId): string
    {
        return 'peopleos.hire_completion.'.$employeeId;
    }

    /** @return array<string, mixed>|null the summary of the hire just made by this person (once; a forged id finds nothing) */
    private function completionFromSession(): ?array
    {
        $id = (int) request()->query('hired', 0);
        if ($id < 1) {
            return null;
        }
        $summary = session()->pull(self::completionKey($id));
        $employee = is_array($summary) ? Employee::query()->find($id) : null;

        return $employee !== null && Gate::allows('view', $employee) ? $summary : null;
    }

    /** @return list<array{label: string, description: string, url: string}> where to go next, each only when the person may */
    private function completionLinks(): array
    {
        $employee = $this->completion === null ? null : Employee::query()->find($this->completion['employee_id']);
        if ($employee === null) {
            return [];
        }
        $view = fn (array $query = []) => EmployeeResource::getUrl('view', ['record' => $employee]).($query === [] ? '' : '?'.http_build_query($query));
        $links = [['label' => 'Open the Employee 360', 'description' => 'Everything about '.$this->completion['name'].' in one place', 'url' => $view()]];
        $links[] = ['label' => 'Add another employee', 'description' => 'Start the hire wizard again', 'url' => static::getUrl()];
        if (self::allows('onboarding.manage') && $employee->onboardingPlan()->where('status', 'in_progress')->doesntExist()) {
            $links[] = ['label' => 'Start onboarding', 'description' => 'Pick a template or the best match by rules', 'url' => $view(['action' => 'startOnboarding'])];
        }
        if (Gate::allows('update', $employee)) {
            $links[] = ['label' => 'Complete employee details', 'description' => 'Edit the profile and other details', 'url' => EmployeeResource::getUrl('edit', ['record' => $employee])];
        }
        foreach ([AddressesRelationManager::class => ['Personal details', 'Addresses, family, education, bank and identity documents'], DocumentsRelationManager::class => ['Documents', 'Upload joining documents']] as $manager => [$label, $description]) {
            if (($tab = self::relationTab($manager)) !== null) {
                $links[] = ['label' => $label, 'description' => $description, 'url' => $view(['relation' => $tab])];
            }
        }

        return $links;
    }

    /** The Employee 360 tab (its key in EmployeeResource::getRelations()) that holds this relation manager. */
    private static function relationTab(string $manager): ?string
    {
        foreach (EmployeeResource::getRelations() as $key => $relation) {
            $managers = $relation instanceof RelationGroup ? $relation->getManagers() : [$relation];
            if (in_array($manager, $managers, true)) {
                return (string) $key;
            }
        }

        return null;
    }
}
