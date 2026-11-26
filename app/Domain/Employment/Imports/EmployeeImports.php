<?php

namespace App\Domain\Employment\Imports;

use App\Domain\Audit\Enums\AuditAction;
use App\Domain\Audit\Services\AuditRecorder;
use App\Domain\Configuration\Services\OptionLists;
use App\Domain\Employment\Actions\ChangeManagerAction;
use App\Domain\Employment\Actions\HireEmployeeAction;
use App\Domain\Employment\Enums\ImportMode;
use App\Domain\Employment\Imports\Workbooks\ImportReferences;
use App\Domain\Employment\Imports\Workbooks\WorkbookImports;
use App\Domain\Employment\Models\Employee;
use App\Domain\Employment\Requirements\PositionRequirements;
use App\Domain\Employment\Requirements\RequirementsResult;
use App\Domain\Identity\Models\User;
use App\Domain\Identity\Scopes\AccessScope;
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
use App\Domain\Organisation\Resolution\ResolutionStatus;
use App\Domain\Organisation\Services\OrganisationCodes;
use App\Domain\Organisation\Support\DimensionLabel;
use App\Domain\People\Actions\UpdatePersonAction;
use App\Domain\People\Services\PersonMatcher;
use App\Domain\People\Support\PhoneNumbers;
use App\Domain\Platform\Services\FeatureFlags;
use App\Support\Csv\SpreadsheetCell;
use App\Support\Imports\ReferenceGuide;
use App\Support\Tenancy\TenantContext;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use League\Csv\Reader;
use League\Csv\Writer;
use RuntimeException;

/**
 * Employee import pipeline (Phase 1 §38–§41): upload → inspect → map → validate → preview →
 * approve → import → audit. Rows are staged in employee_import_rows; production records are
 * touched only in run(), through the same actions the UI uses, inside one audit operation.
 */
final class EmployeeImports
{
    public const FEATURE = 'employee.import_export';

    /**
     * Canonical import fields: key => [label, required]. Organisation values and the manager are read by name (names
     * first, 2026-11-26) or by code, as before; the keys stay as they were (saved mappings and presets use them).
     */
    public const FIELDS = [
        'person.first_name' => ['First name', true], 'person.middle_name' => ['Middle name', false], 'person.last_name' => ['Last name', true],
        'person.preferred_name' => ['Preferred name', false], 'person.date_of_birth' => ['Date of birth', false], 'person.gender' => ['Gender', false],
        'person.marital_status' => ['Marital status', false], 'person.nationality' => ['Nationality', false],
        'person.personal_email' => ['Personal email', false], 'person.personal_phone' => ['Personal phone', false],
        'employee.employee_code' => ['Employee code', false], 'employee.joining_date' => ['Joining date', true], 'employee.probation_end_date' => ['Probation end date', false],
        'employee.work_email' => ['Work email', false], 'employee.work_phone' => ['Work phone', false], 'employee.external_reference' => ['External reference', false],
        'position.company_code' => ['Company', true], 'position.location_code' => ['Location', false], 'position.business_unit_code' => ['Business unit', false],
        'position.division_code' => ['Division', false], 'position.department_code' => ['Department', false], 'position.team_code' => ['Team', false],
        'position.designation_code' => ['Designation', false], 'position.level_code' => ['Level', false], 'position.grade_code' => ['Grade', false],
        'position.employment_type_code' => ['Employment type', false], 'position.employee_category_code' => ['Employee category', false],
        'position.work_mode_code' => ['Work mode', false], 'position.cost_centre_code' => ['Cost centre', false],
        'manager_code' => ['Manager', false],
        // HROne parity MS-4 (appended, so earlier files and exports keep their columns in place).
        'person.salutation' => ['Salutation', false], 'person.is_fresher' => ['Fresher', false],
        'person.personal_phone_country_code' => ['Personal phone country code', false], 'employee.work_phone_country_code' => ['Work phone country code', false],
        'employee.device_user_id' => ['Device user id', false],
        // Data import DI-1 (decision D1): the organisation's own dimension on the position (appended, as above).
        'position.dimension_code' => ['Dimension', false],
    ];

    /** What a file can be for: mode => [label, description]. */
    public const MODES = [
        'new' => ['Add new employees', 'Creates people who are not on file yet. Rows that match an employee already on file are skipped.'],
        'update' => ['Update existing employees', 'Updates people already on file, found by employee code. Only the employee code is required; blank cells keep what is on file. Rows for people not on file are skipped.'],
        'both' => ['Add new and update existing', 'Creates people not on file and updates the ones who are.'],
    ];

    private const ORGANISATION = [
        'company_code' => ['company_id', Company::class], 'location_code' => ['location_id', Location::class], 'business_unit_code' => ['business_unit_id', BusinessUnit::class],
        'division_code' => ['division_id', Division::class], 'department_code' => ['department_id', Department::class], 'team_code' => ['team_id', Team::class],
        'designation_code' => ['designation_id', Designation::class], 'level_code' => ['level_id', Level::class], 'grade_code' => ['grade_id', Grade::class],
        'employment_type_code' => ['employment_type_id', EmploymentType::class], 'employee_category_code' => ['employee_category_id', EmployeeCategory::class],
        'work_mode_code' => ['work_mode_id', WorkMode::class], 'cost_centre_code' => ['cost_centre_id', CostCentre::class],
        'dimension_code' => ['dimension_value_id', DimensionValue::class],
    ];

    public function __construct(
        private readonly TenantContext $tenants,
        private readonly PersonMatcher $matcher,
        private readonly HireEmployeeAction $hire,
        private readonly UpdatePersonAction $updatePerson,
        private readonly AuditRecorder $audit,
    ) {}

    public static function disk(): string
    {
        return config('peopleos.documents.disk', 'local');
    }

    public static function directory(): string
    {
        return 'tenants/'.app(TenantContext::class)->id().'/imports';
    }

    /** Platform-controlled flag: Markedge switches bulk import and export on per organisation. */
    public static function enabled(): bool
    {
        return app(FeatureFlags::class)->enabled(self::FEATURE);
    }

    /** @return array<string, array{0: string, 1: bool}> */
    public static function fields(): array
    {
        return self::FIELDS;
    }

    /**
     * Fields every row must carry: adding someone needs the hire facts; in Update existing employees mode only the
     * employee code that finds them.
     *
     * @return list<string>
     */
    public static function required(ImportMode $mode): array
    {
        return $mode === ImportMode::Update ? ['employee.employee_code'] : array_keys(array_filter(self::FIELDS, fn (array $def) => $def[1]));
    }

    /** Register an uploaded CSV that already sits on the private disk (Filament upload) or store a raw file. */
    public function register(string $path, string $originalName, User $user, ?string $reason = null, ImportMode $mode = ImportMode::Both): EmployeeImport
    {
        $this->assertEnabled();
        $disk = self::disk();
        if (! Storage::disk($disk)->exists($path)) {
            throw new RuntimeException('The uploaded file could not be found on the private disk.');
        }

        $import = new EmployeeImport([
            'original_name' => $originalName, 'disk' => $disk, 'path' => $path, 'size_bytes' => (int) Storage::disk($disk)->size($path),
            'status' => 'uploaded', 'uploaded_by' => $user->getKey(), 'options' => ['mode' => $mode->value],
        ]);
        $import->withAuditReason($reason)->save();

        return $import;
    }

    /** Read headers, stage rows, suggest a mapping. */
    public function inspect(EmployeeImport $import): EmployeeImport
    {
        $this->assertStatus($import, ['uploaded', 'inspected']);
        $max = (int) config('peopleos.people.import_max_rows', 5000);

        $csv = Reader::from(Storage::disk($import->disk)->readStream($import->path));
        $csv->setHeaderOffset(0);
        $headers = array_values(array_map(fn ($h) => trim((string) $h), $csv->getHeader()));

        if ($headers === [] || count(array_filter($headers)) !== count(array_unique(array_filter($headers)))) {
            throw new RuntimeException('The file needs a header row with unique column names.');
        }

        DB::transaction(function () use ($import, $csv, $headers, $max) {
            $import->rows()->delete();
            $count = 0;
            foreach ($csv->getRecords() as $offset => $record) {
                $count++;
                if ($count > $max) {
                    throw new RuntimeException("The file has more than {$max} rows; split it.");
                }
                // QA audit 2026-11-12: export() guards formula-looking text with an apostrophe; an edited export reads back as exported.
                EmployeeImportRow::create(['employee_import_id' => $import->getKey(), 'row_number' => $count, 'data' => array_map(fn ($v) => is_string($v) ? SpreadsheetCell::unguard(trim($v)) : $v, $record)]);
            }

            $import->update(['headers' => $headers, 'row_count' => $count, 'mapping' => $import->mapping ?: $this->suggestMapping($headers), 'status' => 'inspected']);
        });

        return $import->refresh();
    }

    /** @return array<string, string> header => field */
    public function suggestMapping(array $headers): array
    {
        $byLabel = [];
        foreach (self::FIELDS as $field => [$label]) {
            $byLabel[Str::slug($label, '_')] = $field;
            $byLabel[Str::slug(Str::after($field, '.'), '_')] = $field;
        }
        $byLabel['name'] = 'person.first_name';
        $byLabel['email'] = 'employee.work_email';
        $byLabel['doj'] = 'employee.joining_date';
        $byLabel['dob'] = 'person.date_of_birth';
        $byLabel['code'] = 'employee.employee_code';
        $byLabel['manager'] = 'manager_code';
        // Files made before names came first head the manager column "Manager employee code".
        $byLabel['manager_employee_code'] = 'manager_code';
        $byLabel['reporting_manager'] = 'manager_code';

        $mapping = [];
        foreach ($headers as $header) {
            $key = Str::slug($header, '_');
            if (isset($byLabel[$key]) && ! in_array($byLabel[$key], $mapping, true)) {
                $mapping[$header] = $byLabel[$key];
            }
        }

        return $mapping;
    }

    /**
     * Downloadable starting file: every field label as a header, so an upload maps automatically, and one
     * example row built from this tenant's own active records, by name (earliest created first, usually the main
     * entity). Employee code is left blank to be generated.
     */
    public function template(): string
    {
        $this->assertEnabled();
        $active = fn (string $model) => $model::query()->where('status', 'active')->orderBy('id');
        $company = $active(Company::class)->first();
        $ofCompany = fn (string $model) => $company ? $active($model)->where('company_id', $company->getKey())->value('name') : null;

        $example = [
            'person.first_name' => 'Asha', 'person.last_name' => 'Rao', 'person.date_of_birth' => '1995-01-15', 'person.personal_email' => 'asha.personal@example.com',
            'employee.joining_date' => now()->toDateString(), 'employee.work_email' => 'asha.rao@example.com',
            'position.company_code' => $company?->name, 'position.location_code' => $ofCompany(Location::class), 'position.department_code' => $ofCompany(Department::class),
            'position.designation_code' => $active(Designation::class)->value('name'), 'position.employment_type_code' => $active(EmploymentType::class)->value('name'),
            'position.work_mode_code' => $active(WorkMode::class)->value('name'),
        ];
        // A name two records share (or that is another record's code) is written "Name (CODE)", so the row still validates.
        foreach (self::ORGANISATION as $key => [, $model]) {
            if (filled($example["position.{$key}"] ?? null)) {
                $labels = self::labelsOf($model);
                $id = array_search($example["position.{$key}"], array_column($labels, 'name', 'id'), true);
                $example["position.{$key}"] = $id === false ? $example["position.{$key}"] : $labels[$id]['label'];
            }
        }

        $csv = Writer::fromString();
        $csv->insertOne(array_column(self::FIELDS, 0));
        $csv->insertOne(array_map(fn ($field) => $example[$field] ?? '', array_keys(self::FIELDS)));

        return $csv->toString();
    }

    /**
     * The format guide the upload screens show: every column with when it is required, what to enter and an example.
     *
     * @return array{columns: list<array{0: string, 1: string, 2: string, 3: string}>, notes: list<string>}
     */
    public function format(): array
    {
        // Where each record a position names is set up (names first: the name is typed, its code works too).
        $where = [
            'position.company_code' => ['company', 'Organisation → Companies', 'Acme India'], 'position.location_code' => ['location', 'Organisation → Locations', 'Bengaluru'],
            'position.business_unit_code' => ['business unit', 'Organisation → Business units', ''], 'position.division_code' => ['division', 'Organisation → Divisions', ''],
            'position.department_code' => ['department', 'Organisation → Departments', 'Finance'], 'position.team_code' => ['team', 'Organisation → Teams', ''],
            'position.designation_code' => ['designation', 'People Setup → Designations', 'Accountant'], 'position.level_code' => ['level', 'People Setup → Levels', ''],
            'position.grade_code' => ['grade', 'People Setup → Grades', ''], 'position.employment_type_code' => ['employment type', 'People Setup → Employment types', 'Permanent'],
            'position.employee_category_code' => ['employee category', 'People Setup → Employee categories', ''],
            'position.work_mode_code' => ['work mode', 'People Setup → Work modes', 'Office'], 'position.cost_centre_code' => ['cost centre', 'Organisation → Cost centres', ''],
            'position.dimension_code' => [DimensionLabel::valueLabel(), 'Organisation → '.DimensionLabel::name(), ''],
        ];
        $what = [
            'person.first_name' => ['Given name.', 'Asha'], 'person.middle_name' => ['', ''], 'person.last_name' => ['Family name.', 'Rao'],
            'person.preferred_name' => ['The name used day to day, if different.', 'Ash'], 'person.date_of_birth' => ['YYYY-MM-DD.', '1995-01-15'],
            'person.gender' => ['One of: '.implode(', ', array_keys(config('peopleos.people.genders', []))).'.', 'female'],
            'person.marital_status' => ['One of: '.implode(', ', array_keys(config('peopleos.people.marital_statuses', []))).'.', 'single'],
            'person.nationality' => ['Two-letter country code.', 'IN'], 'person.personal_email' => ['Also used to spot a person already on file.', 'asha@example.com'],
            'person.personal_phone' => ['Also used to spot a person already on file.', '+919800000000'],
            'employee.employee_code' => ['Adding: leave blank to have it generated from the company\'s code series. Updating: the code of the employee to update.', 'EMP0001'],
            'employee.joining_date' => ['YYYY-MM-DD.', now()->toDateString()], 'employee.probation_end_date' => ['YYYY-MM-DD.', ''],
            'employee.work_email' => ['Official email.', 'asha.rao@example.com'], 'employee.work_phone' => ['', ''],
            'employee.external_reference' => ['An id from another system (ATS, old HRMS).', 'ATS-1042'],
            'person.salutation' => ['One of: '.implode(', ', array_keys(app(OptionLists::class)->options('salutations'))).' (or its name). Blank: letters use Mr / Ms / Mx from the gender.', 'ms'],
            'person.is_fresher' => ['yes when the person has no earlier employment (then no work experience can be recorded).', 'no'],
            'person.personal_phone_country_code' => ['International dialling code without + (91 for India). With a code the phone is checked and stored as +91…; blank keeps the phone as typed.', '91'],
            'employee.work_phone_country_code' => ['As for the personal phone.', ''],
            'employee.device_user_id' => ['The id the attendance device knows the person by, if it is not the employee code. Unique.', ''],
            'manager_code' => [ReferenceGuide::person('reporting manager', 'A manager added in the same file is named by their employee code.'), 'EMP0002'],
        ];
        foreach ($where as $field => [$record, $place, $example]) {
            $what[$field] = [ReferenceGuide::name($record, $place, $field === 'position.company_code' ? null : 'Leave blank if not used.'), $example];
        }
        $required = array_flip(self::required(ImportMode::New));

        return [
            'columns' => array_map(fn (string $field) => [
                self::FIELDS[$field][0],
                $field === 'employee.employee_code' ? 'To update' : (isset($required[$field]) ? 'To add' : 'No'),
                $what[$field][0] ?? '',
                $what[$field][1] ?? '',
            ], array_keys(self::FIELDS)),
            'notes' => [
                'To add people, fill the columns marked "To add". To update people, the employee code is enough; add only the columns you want to change.',
                'Updating changes names, date of birth, gender, marital status, nationality, emails, phones and external reference (and salutation, fresher, phone country codes and the device user id). Joining date, position and manager changes are made on the employee\'s record.',
                'Employees › Export gives your current employees in this format: edit it and upload it with Update existing employees.',
                'Nothing changes until the file is mapped, validated, approved and run. Rows that may be someone already on file wait for you to decide.',
                ReferenceGuide::NOTE,
            ],
        ];
    }

    /**
     * Employees in the template format, so the file can be edited and uploaded back: each row matches its employee
     * by code and updates person and contact details (position and manager changes go through moves, not imports).
     */
    public function export(Builder $employees, User $user): string
    {
        $this->assertEnabled();
        // Names first: each position is written by name ("Name (CODE)" when two records share it); the manager by code.
        $labels = collect(self::ORGANISATION)->map(fn (array $dimension) => array_column(self::labelsOf($dimension[1]), 'label', 'id'))->all();
        $value = fn (?Model $model, string $key) => ($v = $model?->getAttribute($key)) instanceof \DateTimeInterface ? $v->format('Y-m-d') : $v;

        $csv = Writer::fromString();
        $csv->insertOne(array_column(self::FIELDS, 0));
        $count = 0;
        foreach ($employees->with(['person', 'currentPosition', 'currentManager.manager'])->lazyById(500) as $employee) {
            // QA audit 2026-11-12: names, e-mails and phones are typed by people themselves; a value a spreadsheet would run
            // as a formula is written as text (the import reads it back unchanged: SpreadsheetCell::unguard()).
            $csv->insertOne(SpreadsheetCell::row(array_map(function (string $field) use ($employee, $labels, $value) {
                [$group, $key] = str_contains($field, '.') ? explode('.', $field, 2) : [$field, ''];

                return match ($group) {
                    'person' => $value($employee->person, $key),
                    'employee' => $value($employee, $key),
                    'position' => $labels[$key][$employee->currentPosition?->getAttribute(self::ORGANISATION[$key][0])] ?? null,
                    default => $employee->currentManager?->manager?->employee_code,
                };
            }, array_keys(self::FIELDS))));
            $count++;
        }

        $this->audit->record(AuditAction::Export, 'employment', null, [], null, actor: $user, metadata: ['export' => 'employees_import_format', 'rows' => $count]);

        return $csv->toString();
    }

    /**
     * How a file names each record of an organisation list: its name, or "Name (CODE)" when another record has the same
     * name or a code that reads as that name (so the file reads back as the same record).
     *
     * @param  class-string<Model>  $model
     * @return array<int, array{id: int, name: string, label: string}> id => the record's name and how a file names it
     */
    private static function labelsOf(string $model): array
    {
        $records = $model::query()->get(['id', 'code', 'name']);
        $names = $records->countBy(fn (Model $m) => mb_strtolower(trim((string) $m->getAttribute('name'))))->all();
        $codes = $records->mapWithKeys(fn (Model $m) => [mb_strtolower(trim((string) $m->getAttribute('code'))) => (int) $m->getKey()])->all();
        $labels = [];
        foreach ($records as $record) {
            $name = trim((string) $record->getAttribute('name'));
            $code = (string) $record->getAttribute('code');
            $key = mb_strtolower($name);
            $clear = $name !== '' && ($names[$key] ?? 0) === 1 && ($codes[$key] ?? $record->getKey()) === (int) $record->getKey();
            $labels[(int) $record->getKey()] = ['id' => (int) $record->getKey(), 'name' => $name, 'label' => $clear ? $name : ($name === '' ? $code : "{$name} ({$code})")];
        }

        return $labels;
    }

    /** @param  array<string, string|null>  $mapping  header => field */
    public function map(EmployeeImport $import, array $mapping, array $options = []): EmployeeImport
    {
        $this->assertStatus($import, ['inspected', 'mapped', 'validated']);
        $mapping = array_filter($mapping, fn ($field) => $field !== null && $field !== '');

        foreach ($mapping as $header => $field) {
            if (! in_array($header, $import->headers ?? [], true)) {
                throw new RuntimeException("Column [{$header}] is not in the file.");
            }
            if (! array_key_exists($field, self::FIELDS)) {
                throw new RuntimeException("Unknown import field [{$field}].");
            }
        }
        if (count($mapping) !== count(array_unique($mapping))) {
            throw new RuntimeException('Each field can be mapped from one column only.');
        }
        $options = array_merge($import->options ?? [], $options);
        $mode = ImportMode::tryFrom((string) ($options['mode'] ?? '')) ?? ImportMode::of($import);
        $options = ['mode' => $mode->value] + array_diff_key($options, ['on_duplicate' => true]);
        $missing = collect(self::required($mode))->diff(array_values($mapping));
        if ($missing->isNotEmpty()) {
            throw new RuntimeException('Required fields are not mapped: '.$missing->map(fn ($f) => self::FIELDS[$f][0])->implode(', ').'.');
        }

        $import->update(['mapping' => $mapping, 'options' => $options, 'status' => 'mapped', 'valid_count' => 0, 'error_count' => 0, 'review_count' => 0, 'create_count' => 0, 'update_count' => 0, 'skip_count' => 0]);

        return $import->refresh();
    }

    /** Validate every staged row and decide its action; never touches employees. */
    public function validate(EmployeeImport $import): EmployeeImport
    {
        $this->assertStatus($import, ['mapped', 'validated']);
        $mapping = $import->mapping ?? [];
        $mode = ImportMode::of($import);
        $counts = ['valid' => 0, 'error' => 0, 'review' => 0, 'create' => 0, 'update' => 0, 'skip' => 0];
        $seen = ['employee_code' => [], 'work_email' => [], 'personal_email' => [], 'external_reference' => [], 'device_user_id' => []];
        // A manager may be a new hire in the same file (any row): the run sets those managers once everyone is created.
        $lookups = ['file_codes' => []];
        foreach ($import->rows()->cursor() as $row) {
            $code = mb_strtolower(trim((string) ($this->recordFor($row, $mapping)['employee']['employee_code'] ?? '')));
            if ($code !== '') {
                $lookups['file_codes'][$code] = $row->row_number;
            }
        }

        // Names (not codes) of organisation records and managers resolve through one session (ImportReferences).
        $references = app(ImportReferences::class);
        $references->begin($import, checking: true);
        try {
            $this->validateRows($import, $mapping, $mode, $counts, $seen, $lookups);
        } finally {
            $references->end();
        }

        return $import->refresh();
    }

    /** @param array<string, int> $counts @param array<string, array<string, int>> $seen @param array<string, mixed> $lookups */
    private function validateRows(EmployeeImport $import, array $mapping, ImportMode $mode, array &$counts, array &$seen, array &$lookups): void
    {
        DB::transaction(function () use ($import, $mapping, $mode, &$counts, &$seen, &$lookups) {
            foreach ($import->rows()->cursor() as $row) {
                $record = $this->recordFor($row, $mapping);
                $resolved = null;
                $errors = $this->validateRecord($record, $lookups, $mode, (int) ($row->data[WorkbookImports::ROW_COLUMN] ?? $row->row_number + 1), $resolved);

                foreach ($seen as $key => $values) {
                    $value = mb_strtolower((string) ($record['employee'][$key] ?? $record['person'][$key] ?? ''));
                    if ($value !== '') {
                        if (isset($seen[$key][$value])) {
                            $errors[] = ucfirst(str_replace('_', ' ', $key))." '{$value}' also appears in row {$seen[$key][$value]}.";
                        }
                        $seen[$key][$value] ??= $row->row_number;
                    }
                }

                $action = 'create';
                $match = null;
                if ($errors === []) {
                    $definite = $this->matcher->definite($record['person'], $record['employee']);
                    $possible = $this->matcher->candidates($record['person'], $record['employee'])->where('definite', false)->values();
                    $byCode = $definite->first(fn (array $hit) => in_array('employee code', $hit['matched_on'], true));
                    if ($mode === ImportMode::Update && $byCode === null) {
                        // Updating finds people by their code only: a row for someone not on file is left out, not created.
                        $action = 'skip';
                        $match = ['reason' => "No employee on file has code {$record['employee']['employee_code']}; Update existing employees leaves out people not on file."];
                    } elseif ($definite->isNotEmpty()) {
                        $match = $definite->first();
                        $action = $definite->count() > 1 || $match['employee_id'] === null ? 'review' : ($mode->updates() ? 'update' : 'skip');
                        if ($action === 'skip') {
                            $match['reason'] = 'Already on file as '.($match['employee_code'] ?? 'an employee').'; Add new employees leaves out people on file.';
                        }
                    } elseif ($possible->isNotEmpty()) {
                        $match = $possible->first();
                        $action = 'review';
                    }
                } else {
                    $action = 'error';
                }
                // Master relationships R2: a new hire's employment data requirements before approval. Block is a row
                // error; Warn a note on the row.
                if ($action === 'create' && $resolved !== null) {
                    $requirements = $this->requirementsOf($resolved);
                    if ($requirements->blocking() !== []) {
                        [$action, $errors, $match] = ['error', $requirements->blockingMessages(), null];
                    } elseif ($requirements->warnings() !== []) {
                        $match = ['warnings' => $requirements->warningMessages()] + (array) $match;
                    }
                }

                $row->update(['action' => $action, 'errors' => $errors ?: null, 'match' => $match, 'employee_id' => $match['employee_id'] ?? null, 'status' => 'pending', 'result' => null]);
                $counts[$action === 'error' ? 'error' : ($action === 'review' ? 'review' : 'valid')]++;
                if (in_array($action, ['create', 'update', 'skip'], true)) {
                    $counts[$action]++;
                }
            }

            $import->update(['status' => 'validated', 'valid_count' => $counts['valid'], 'error_count' => $counts['error'], 'review_count' => $counts['review'], 'create_count' => $counts['create'], 'update_count' => $counts['update'], 'skip_count' => $counts['skip']]);
        });
    }

    /** A human decides what to do with a row flagged for review. */
    public function resolveReview(EmployeeImportRow $row, string $decision): EmployeeImportRow
    {
        if ($row->action !== 'review' || ! in_array($decision, ['create', 'update', 'skip'], true)) {
            throw new RuntimeException('Only rows under review can be resolved, to create, update or skip.');
        }
        if ($decision === 'update' && empty($row->match['employee_id'])) {
            throw new RuntimeException('This row has no matched employee to update.');
        }
        $mode = ImportMode::of($row->import()->firstOrFail());
        if (($decision === 'create' && ! $mode->adds()) || ($decision === 'update' && ! $mode->updates())) {
            throw new RuntimeException('This file is set to '.mb_strtolower(self::MODES[$mode->value][0]).'; that decision belongs to another mode.');
        }

        DB::transaction(function () use ($row, $decision) {
            $row->update(['action' => $decision, 'employee_id' => $decision === 'update' ? $row->match['employee_id'] : null]);
            $import = $row->import()->firstOrFail();
            $import->update(['review_count' => max(0, $import->review_count - 1), 'valid_count' => $import->valid_count + 1, "{$decision}_count" => $import->{"{$decision}_count"} + 1]);
        });

        return $row->refresh();
    }

    /** @return array<string, mixed> */
    public function preview(EmployeeImport $import): array
    {
        return [
            'rows' => $import->row_count, 'valid' => $import->valid_count, 'errors' => $import->error_count, 'review' => $import->review_count,
            'will_create' => $import->create_count, 'will_update' => $import->update_count, 'will_skip' => $import->skip_count,
            'sample' => $import->rows()->where('action', 'create')->limit(5)->get()->map(fn (EmployeeImportRow $r) => $this->recordFor($r, $import->mapping ?? []))->all(),
        ];
    }

    public function approve(EmployeeImport $import, User $user, ?string $reason = null): EmployeeImport
    {
        $this->assertStatus($import, ['validated']);
        if ($import->review_count > 0) {
            throw new RuntimeException("{$import->review_count} row(s) still need review before approval.");
        }
        if ($import->create_count + $import->update_count === 0) {
            throw new RuntimeException('Nothing to import: no row would create or update an employee.');
        }

        $import->withAuditReason($reason)->update(['status' => 'approved', 'approved_by' => $user->getKey(), 'approved_at' => now()]);

        return $import->refresh();
    }

    /** Apply the approved rows through the authoritative actions inside one audit operation. */
    public function run(EmployeeImport $import, User $user): EmployeeImport
    {
        // A worksheet of a master upload may be resumed after an interruption (only its rows still pending run).
        $this->assertStatus($import, $import->isSheet() ? ['approved', 'importing'] : ['approved']);
        $this->assertEnabled();
        if ($import->status !== 'importing') {
            $import->update(['status' => 'importing']);
        }
        $mapping = $import->mapping ?? [];
        $result = ['created' => 0, 'updated' => 0, 'skipped' => 0, 'failed' => 0, 'ids' => []];
        $lookups = ['file_codes' => []];
        foreach ($import->rows()->whereIn('action', ['create', 'update'])->cursor() as $row) {
            $code = mb_strtolower(trim((string) ($this->recordFor($row, $mapping)['employee']['employee_code'] ?? '')));
            if ($code !== '') {
                $lookups['file_codes'][$code] = $row->row_number;
            }
        }
        $references = app(ImportReferences::class);
        $references->begin($import, checking: false);

        try {
            $operationId = $this->runRows($import, $mapping, $result, $lookups);
        } finally {
            $references->end();
        }

        // Counted from the rows, so a resumed run reports the whole file, not only its last part.
        $done = $import->rows()->reorder()->selectRaw('action, status, count(*) as n')->groupBy('action', 'status')->get();
        $count = fn (string $status, ?string $action = null) => (int) $done->filter(fn ($r) => $r->status === $status && ($action === null || $r->action === $action))->sum('n');
        $import->update([
            'status' => 'imported', 'imported_at' => now(), 'operation_id' => $operationId,
            'create_count' => $count('imported', 'create'), 'update_count' => $count('imported', 'update'), 'skip_count' => $count('skipped'), 'failure_count' => $count('failed'),
        ]);

        return $import->refresh();
    }

    /** @param array<string, mixed> $result @param array<string, mixed> $lookups */
    private function runRows(EmployeeImport $import, array $mapping, array &$result, array $lookups): string
    {
        return $this->audit->operation('employment', "Employee import #{$import->getKey()} ({$import->original_name})", function () use ($import, $mapping, &$result, $lookups) {
            $laterManagers = [];
            foreach ($import->rows()->whereIn('action', ['create', 'update', 'skip'])->where('status', 'pending')->cursor() as $row) {
                if ($row->action === 'skip') {
                    $row->update(['status' => 'skipped', 'result' => 'Skipped: '.($row->match['reason'] ?? 'existing employee '.($row->match['employee_code'] ?? ''))]);
                    $result['skipped']++;

                    continue;
                }

                // Names found again (as when the file was checked) and replaced by their codes; a reference that no
                // longer resolves fails the row with the reason.
                [$record, $referenceErrors] = $this->withReferences($this->recordFor($row, $mapping), $lookups, (int) ($row->data[WorkbookImports::ROW_COLUMN] ?? $row->row_number + 1));
                if ($referenceErrors !== []) {
                    $row->update(['status' => 'failed', 'result' => Str::limit(implode(' ', $referenceErrors), 500)]);
                    $result['failed']++;

                    continue;
                }
                // A manager who is a new hire further down the file does not exist yet: hire without, set it afterwards.
                $later = $row->action === 'create' && $record['manager_code'] !== null && ! Employee::query()->where('employee_code', $record['manager_code'])->exists()
                    ? $record['manager_code'] : null;
                if ($later !== null) {
                    $record['manager_code'] = null;
                }

                try {
                    $employee = DB::transaction(function () use ($row, $record, $import) {
                        $employee = $row->action === 'create' ? $this->create($record, $import) : $this->updateExisting($row, $record, $import);
                        // Written in the same transaction as the change, so a resumed run never applies the row twice.
                        $row->update(['status' => 'imported', 'employee_id' => $employee->getKey(), 'result' => ($row->action === 'create' ? 'Created ' : 'Updated ').$employee->employee_code
                            .($employee->requirementWarnings === [] ? '' : ' — warning: '.implode(' ', $employee->requirementWarnings))]);

                        return $employee;
                    });
                    $result[$row->action === 'create' ? 'created' : 'updated']++;
                    $result['ids'][] = $employee->getKey();
                    if ($later !== null) {
                        $laterManagers[$row->getKey()] = [$employee, $later];
                    }
                } catch (\Throwable $e) {
                    report($e);
                    $row->refresh()->update(['status' => 'failed', 'result' => Str::limit($e->getMessage(), 500)]);
                    $result['failed']++;
                }
            }
            $this->setLaterManagers($laterManagers, $import);

            return ['succeeded' => $result['created'] + $result['updated'] + $result['skipped'], 'failed' => $result['failed'], 'ids' => $result['ids']];
        }, reason: "Import {$import->original_name}", entityType: Employee::class);
    }

    /** Master uploads: a run stopped part-way can carry on (only rows still pending run). */
    public static function resumable(): bool
    {
        return true;
    }

    /**
     * The managers that were new hires further down the file, set once everyone is created (line manager from the
     * employee's joining date). A manager whose own row failed is not set; the row says so.
     *
     * @param  array<int, array{0: Employee, 1: string}>  $later  row id => [employee, manager code]
     */
    private function setLaterManagers(array $later, EmployeeImport $import): void
    {
        foreach ($later as $rowId => [$employee, $managerCode]) {
            $row = EmployeeImportRow::query()->find($rowId);
            $manager = Employee::query()->where('employee_code', $managerCode)->first();
            try {
                if ($manager === null) {
                    throw new RuntimeException("manager {$managerCode} was not created (see their row), so no manager is set");
                }
                DB::transaction(fn () => app(ChangeManagerAction::class)->handle($employee, $manager, 'line', $employee->joining_date, "Import #{$import->getKey()}"));
                $row?->update(['result' => Str::limit($row->result."; manager {$managerCode} set", 500)]);
            } catch (\Throwable $e) {
                $row?->update(['result' => Str::limit($row->result.'; '.$e->getMessage(), 500), 'match' => array_merge((array) $row->match, ['warnings' => [ucfirst($e->getMessage()).'.']])]);
            }
        }
    }

    public function discard(EmployeeImport $import, ?string $reason = null): EmployeeImport
    {
        if (in_array($import->status, ['importing', 'imported'], true)) {
            throw new RuntimeException('A completed import cannot be discarded; it is part of the audit trail.');
        }
        $import->withAuditReason($reason)->update(['status' => 'discarded']);

        return $import;
    }

    // ---------------------------------------------------------------------------------------

    /** @return array{person: array<string, mixed>, employee: array<string, mixed>, position: array<string, mixed>, manager_code: ?string} */
    private function recordFor(EmployeeImportRow $row, array $mapping): array
    {
        $record = ['person' => [], 'employee' => [], 'position' => [], 'manager_code' => null];
        foreach ($mapping as $header => $field) {
            $value = $row->data[$header] ?? null;
            $value = is_string($value) ? trim($value) : $value;
            if ($value === '' || $value === null) {
                continue;
            }
            if ($field === 'manager_code') {
                $record['manager_code'] = (string) $value;

                continue;
            }
            [$group, $key] = explode('.', $field, 2);
            $record[$group][$key] = $value;
        }

        return $this->normaliseAdditions($record);
    }

    /**
     * HROne parity MS-4: the new columns in the form the records take — yes / no for "Fresher" (anything else stays as
     * text and is reported), a salutation given by name becomes its code, dialling codes lose a leading "+".
     *
     * @param  array{person: array<string, mixed>, employee: array<string, mixed>, position: array<string, mixed>, manager_code: ?string}  $record
     * @return array{person: array<string, mixed>, employee: array<string, mixed>, position: array<string, mixed>, manager_code: ?string}
     */
    private function normaliseAdditions(array $record): array
    {
        if (isset($record['person']['is_fresher'])) {
            $flag = filter_var($record['person']['is_fresher'], FILTER_VALIDATE_BOOL, FILTER_NULL_ON_FAILURE);
            $record['person']['is_fresher'] = $flag ?? $record['person']['is_fresher'];
        }
        if (isset($record['person']['salutation'])) {
            $given = mb_strtolower(trim((string) $record['person']['salutation'], ' .'));
            foreach (app(OptionLists::class)->options('salutations') as $code => $label) {
                if ($given === mb_strtolower((string) $code) || $given === mb_strtolower(rtrim((string) $label, '.'))) {
                    $record['person']['salutation'] = (string) $code;
                }
            }
        }
        foreach ([['person', 'personal_phone_country_code'], ['employee', 'work_phone_country_code']] as [$group, $key]) {
            if (isset($record[$group][$key])) {
                $record[$group][$key] = ltrim(trim((string) $record[$group][$key]), '+');
            }
        }

        return $record;
    }

    /** @return list<string> HROne parity MS-4: problems with the new columns */
    private function additionErrors(array $record): array
    {
        $errors = [];
        if (isset($record['person']['is_fresher']) && ! is_bool($record['person']['is_fresher'])) {
            $errors[] = 'Fresher must be yes or no.';
        }
        if (isset($record['person']['salutation']) && ! array_key_exists((string) $record['person']['salutation'], app(OptionLists::class)->options('salutations'))) {
            $errors[] = 'Salutation must be one of: '.implode(', ', array_keys(app(OptionLists::class)->options('salutations'))).'.';
        }
        foreach ([['person', 'personal_phone', 'personal_phone_country_code'], ['employee', 'work_phone', 'work_phone_country_code']] as [$group, $phone, $code]) {
            $codeValue = $record[$group][$code] ?? null;
            if ($codeValue !== null && ! PhoneNumbers::validCode((string) $codeValue)) {
                $errors[] = self::FIELDS["{$group}.{$code}"][0].' must be one to three digits (for example 91).';
            } elseif ($codeValue !== null && isset($record[$group][$phone]) && ($problem = PhoneNumbers::problem((string) $codeValue, (string) $record[$group][$phone])) !== null) {
                $errors[] = self::FIELDS["{$group}.{$phone}"][0].': '.$problem;
            }
        }
        $device = $record['employee']['device_user_id'] ?? null;
        if ($device !== null) {
            if (mb_strlen((string) $device) > 64) {
                $errors[] = 'Device user id is longer than 64 characters.';
            } elseif (($holder = Employee::query()->where('device_user_id', (string) $device)->value('employee_code')) !== null
                && $holder !== ($record['employee']['employee_code'] ?? null)) {
                $errors[] = "Device user id '{$device}' is already used by employee {$holder}.";
            }
        }

        return $errors;
    }

    /** @return list<string> */
    private function validateRecord(array $record, array &$lookups, ImportMode $mode, ?int $sheetRow = null, ?array &$resolved = null): array
    {
        $errors = [];
        foreach (self::required($mode) as $field) {
            [$group, $key] = $field === 'manager_code' ? ['manager_code', null] : explode('.', $field, 2);
            $value = $key === null ? $record['manager_code'] : ($record[$group][$key] ?? null);
            if ($value === null || $value === '') {
                $errors[] = self::FIELDS[$field][0].' is required.';
            }
        }

        foreach (['person' => ['date_of_birth'], 'employee' => ['joining_date', 'probation_end_date']] as $group => $dates) {
            foreach ($dates as $key) {
                if (isset($record[$group][$key])) {
                    try {
                        $record[$group][$key] = Carbon::parse($record[$group][$key])->toDateString();
                    } catch (\Throwable) {
                        $errors[] = self::FIELDS["{$group}.{$key}"][0].' is not a valid date.';
                    }
                }
            }
        }

        foreach (['personal_email' => 'person', 'work_email' => 'employee'] as $key => $group) {
            if (isset($record[$group][$key]) && ! filter_var($record[$group][$key], FILTER_VALIDATE_EMAIL)) {
                $errors[] = self::FIELDS["{$group}.{$key}"][0].' is not a valid email address.';
            }
        }

        $genders = array_keys(config('peopleos.people.genders', []));
        if (isset($record['person']['gender']) && $genders !== [] && ! in_array(mb_strtolower($record['person']['gender']), $genders, true)) {
            $errors[] = 'Gender must be one of: '.implode(', ', $genders).'.';
        }

        $own = mb_strtolower(trim((string) ($record['employee']['employee_code'] ?? '')));
        if ($record['manager_code'] !== null && $own !== '' && mb_strtolower(trim($record['manager_code'])) === $own) {
            $errors[] = 'An employee cannot report to themselves.';
        } else {
            // The record with its names replaced by codes (master relationships R2 checks a new hire's position with it).
            [$resolved, $referenceErrors] = $this->withReferences($record, $lookups, $sheetRow);
            $errors = [...$errors, ...$referenceErrors];
        }

        if (isset($record['employee']['employee_code']) && ! preg_match('/^[A-Za-z0-9_-]{1,32}$/', $record['employee']['employee_code'])) {
            $errors[] = 'Employee code may only contain letters, digits, dashes and underscores.';
        }

        return [...$errors, ...$this->additionErrors($record)];
    }

    /**
     * The row's organisation codes and manager, each found by its code as always or, when it is not a code, by its exact
     * name (or "Name (CODE)", or a mapping confirmed in the review) through the name resolver, as the uploader. Names
     * found are replaced by their codes, so hiring reads codes as before. The company comes first: once the row names
     * one, its other references must apply to it (the applicability framework) and namesakes are narrowed to it.
     *
     * @return array{0: array<string, mixed>, 1: list<string>} the record with codes, and what is wrong
     */
    private function withReferences(array $record, array &$lookups, ?int $sheetRow = null): array
    {
        $errors = [];
        $references = app(ImportReferences::class);
        $companyId = null;
        $keys = array_keys($record['position']);
        usort($keys, fn ($a, $b) => ($b === 'company_code') <=> ($a === 'company_code'));
        foreach ($keys as $key) {
            $code = $record['position'][$key];
            if (! isset(self::ORGANISATION[$key]) || $code === null || $code === '') {
                continue;
            }
            [, $model] = self::ORGANISATION[$key];
            $label = self::FIELDS["position.{$key}"][0];
            $lookups[$model] ??= $model::query()->pluck('id', 'code')->mapWithKeys(fn ($id, $c) => [mb_strtolower((string) $c) => (int) $id])->all();
            $id = $lookups[$model][mb_strtolower((string) $code)] ?? null;
            $forCompany = $model === Company::class ? null : $companyId;
            if ($id === null) {
                $resolution = $references->resolve($model, (string) $code, $forCompany, null, $sheetRow, $label);
                if ($resolution === null || ! $resolution->ok() || (int) $resolution->id <= 0) {
                    $errors[] = $resolution !== null && $resolution->status !== ResolutionStatus::Unmatched && $resolution->error() !== null
                        ? $resolution->error() : "{$label} '{$code}' does not exist.";

                    continue;
                }
                $id = (int) $resolution->id;
                $record['position'][$key] = (string) ($model::query()->withoutGlobalScope(AccessScope::class)->whereKey($id)->value('code') ?? $code);
            } elseif ($forCompany !== null && ! $references->appliesTo($model, $id, $forCompany)) {
                $errors[] = "{$label} '{$code}' is not available to {$references->companyName($forCompany)}. Extend where it applies, or use one that applies to that company.";

                continue;
            }
            if ($model === Company::class) {
                $companyId = $id;
            }
        }

        $manager = $record['manager_code'];
        if ($manager !== null && ! isset($lookups['file_codes'][mb_strtolower(trim($manager))]) && ! Employee::query()->where('employee_code', $manager)->exists()) {
            // A manager on file named by work e-mail or full name; a manager hired in this file is named by code.
            $resolution = $references->resolve(Employee::class, (string) $manager, $companyId, null, $sheetRow, 'Manager');
            if ($resolution !== null && $resolution->ok() && (int) $resolution->id > 0) {
                $record['manager_code'] = (string) Employee::query()->whereKey($resolution->id)->value('employee_code');
            } else {
                $errors[] = $resolution !== null && $resolution->status !== ResolutionStatus::Unmatched && $resolution->error() !== null
                    ? $resolution->error() : "Manager '{$manager}' does not exist (on file or in this file).";
            }
        }

        return [$record, $errors];
    }

    /** A new hire's position checked against the employment data requirements (nothing when its codes do not resolve). */
    private function requirementsOf(array $record): RequirementsResult
    {
        try {
            $position = app(OrganisationCodes::class)->resolvePosition($record['position']);
        } catch (\InvalidArgumentException) {
            return RequirementsResult::none();
        }

        return app(PositionRequirements::class)->assess($position, 'hire', $record['employee']['joining_date'] ?? null);
    }

    private function create(array $record, EmployeeImport $import): Employee
    {
        $position = app(OrganisationCodes::class)->resolvePosition($record['position']);
        $managerId = $record['manager_code'] ? Employee::query()->where('employee_code', $record['manager_code'])->value('id') : null;
        $employee = $this->hire->handle($record['person'], array_intersect_key($record['employee'], array_flip(['joining_date', 'probation_end_date', 'employee_code', 'work_email', 'work_phone', 'work_phone_country_code', 'device_user_id'])), $position, $managerId, "Import #{$import->getKey()}");
        $employee->withAuditReason("Import #{$import->getKey()}")->update(['source' => 'import', 'external_reference' => $record['employee']['external_reference'] ?? $employee->external_reference]);

        return $employee;
    }

    private function updateExisting(EmployeeImportRow $row, array $record, EmployeeImport $import): Employee
    {
        $employee = Employee::query()->with('person')->findOrFail($row->employee_id);
        $this->updatePerson->handle($employee->person, $record['person'], "Import #{$import->getKey()}");
        $employeeFields = array_intersect_key($record['employee'], array_flip(['work_email', 'work_phone', 'external_reference', 'work_phone_country_code', 'device_user_id']));
        if ($employeeFields !== []) {
            $employee->withAuditReason("Import #{$import->getKey()}")->update($employeeFields);
        }

        return $employee->refresh();
    }

    private function assertEnabled(): void
    {
        if (! self::enabled()) {
            throw new RuntimeException('Employee import and export is not switched on for this organisation; Markedge can switch it on.');
        }
    }

    private function assertStatus(EmployeeImport $import, array $allowed): void
    {
        if (($import->type ?? 'employees') !== 'employees') {
            throw new RuntimeException('This import is not an employee import.');
        }
        if (! in_array($import->status, $allowed, true)) {
            throw new RuntimeException("This import is {$import->status}; expected ".implode(' or ', $allowed).'.');
        }
    }
}
