<?php

namespace App\Domain\Employment\Imports;

use App\Domain\Audit\Services\AuditRecorder;
use App\Domain\Employment\Actions\HireEmployeeAction;
use App\Domain\Employment\Models\Employee;
use App\Domain\Identity\Models\User;
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
use App\Domain\People\Actions\UpdatePersonAction;
use App\Domain\People\Services\PersonMatcher;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use League\Csv\Reader;
use RuntimeException;

/**
 * Employee import pipeline (Phase 1 §38–§41): upload → inspect → map → validate → preview →
 * approve → import → audit. Rows are staged in employee_import_rows; production records are
 * touched only in run(), through the same actions the UI uses, inside one audit operation.
 */
final class EmployeeImports
{
    /** Canonical import fields: key => [label, required]. Organisation values are matched by code. */
    public const FIELDS = [
        'person.first_name' => ['First name', true], 'person.middle_name' => ['Middle name', false], 'person.last_name' => ['Last name', true],
        'person.preferred_name' => ['Preferred name', false], 'person.date_of_birth' => ['Date of birth', false], 'person.gender' => ['Gender', false],
        'person.marital_status' => ['Marital status', false], 'person.nationality' => ['Nationality', false],
        'person.personal_email' => ['Personal email', false], 'person.personal_phone' => ['Personal phone', false],
        'employee.employee_code' => ['Employee code', false], 'employee.joining_date' => ['Joining date', true], 'employee.probation_end_date' => ['Probation end date', false],
        'employee.work_email' => ['Work email', false], 'employee.work_phone' => ['Work phone', false], 'employee.external_reference' => ['External reference', false],
        'position.company_code' => ['Company code', true], 'position.location_code' => ['Location code', false], 'position.business_unit_code' => ['Business unit code', false],
        'position.division_code' => ['Division code', false], 'position.department_code' => ['Department code', false], 'position.team_code' => ['Team code', false],
        'position.designation_code' => ['Designation code', false], 'position.level_code' => ['Level code', false], 'position.grade_code' => ['Grade code', false],
        'position.employment_type_code' => ['Employment type code', false], 'position.employee_category_code' => ['Employee category code', false],
        'position.work_mode_code' => ['Work mode code', false], 'position.cost_centre_code' => ['Cost centre code', false],
        'manager_code' => ['Manager employee code', false],
    ];

    private const ORGANISATION = [
        'company_code' => ['company_id', Company::class], 'location_code' => ['location_id', Location::class], 'business_unit_code' => ['business_unit_id', BusinessUnit::class],
        'division_code' => ['division_id', Division::class], 'department_code' => ['department_id', Department::class], 'team_code' => ['team_id', Team::class],
        'designation_code' => ['designation_id', Designation::class], 'level_code' => ['level_id', Level::class], 'grade_code' => ['grade_id', Grade::class],
        'employment_type_code' => ['employment_type_id', EmploymentType::class], 'employee_category_code' => ['employee_category_id', EmployeeCategory::class],
        'work_mode_code' => ['work_mode_id', WorkMode::class], 'cost_centre_code' => ['cost_centre_id', CostCentre::class],
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

    /** Register an uploaded CSV that already sits on the private disk (Filament upload) or store a raw file. */
    public function register(string $path, string $originalName, User $user, ?string $reason = null): EmployeeImport
    {
        $disk = self::disk();
        if (! Storage::disk($disk)->exists($path)) {
            throw new RuntimeException('The uploaded file could not be found on the private disk.');
        }

        $import = new EmployeeImport([
            'original_name' => $originalName, 'disk' => $disk, 'path' => $path, 'size_bytes' => (int) Storage::disk($disk)->size($path),
            'status' => 'uploaded', 'uploaded_by' => $user->getKey(), 'options' => ['on_duplicate' => 'update'],
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
                EmployeeImportRow::create(['employee_import_id' => $import->getKey(), 'row_number' => $count, 'data' => array_map(fn ($v) => is_string($v) ? trim($v) : $v, $record)]);
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

        $mapping = [];
        foreach ($headers as $header) {
            $key = Str::slug($header, '_');
            if (isset($byLabel[$key]) && ! in_array($byLabel[$key], $mapping, true)) {
                $mapping[$header] = $byLabel[$key];
            }
        }

        return $mapping;
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
        $missing = collect(self::FIELDS)->filter(fn ($def) => $def[1])->keys()->diff(array_values($mapping));
        if ($missing->isNotEmpty()) {
            throw new RuntimeException('Required fields are not mapped: '.$missing->map(fn ($f) => self::FIELDS[$f][0])->implode(', ').'.');
        }

        $import->update(['mapping' => $mapping, 'options' => array_merge($import->options ?? [], $options), 'status' => 'mapped', 'valid_count' => 0, 'error_count' => 0, 'review_count' => 0, 'create_count' => 0, 'update_count' => 0, 'skip_count' => 0]);

        return $import->refresh();
    }

    /** Validate every staged row and decide its action; never touches employees. */
    public function validate(EmployeeImport $import): EmployeeImport
    {
        $this->assertStatus($import, ['mapped', 'validated']);
        $mapping = $import->mapping ?? [];
        $onDuplicate = $import->options['on_duplicate'] ?? 'update';
        $counts = ['valid' => 0, 'error' => 0, 'review' => 0, 'create' => 0, 'update' => 0, 'skip' => 0];
        $seen = ['employee_code' => [], 'work_email' => [], 'personal_email' => [], 'external_reference' => []];
        $lookups = [];

        DB::transaction(function () use ($import, $mapping, $onDuplicate, &$counts, &$seen, &$lookups) {
            foreach ($import->rows()->cursor() as $row) {
                $record = $this->recordFor($row, $mapping);
                $errors = $this->validateRecord($record, $lookups);

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
                    if ($definite->isNotEmpty()) {
                        $match = $definite->first();
                        $action = $definite->count() > 1 || $match['employee_id'] === null ? 'review' : ($onDuplicate === 'skip' ? 'skip' : 'update');
                    } elseif ($possible->isNotEmpty()) {
                        $match = $possible->first();
                        $action = 'review';
                    }
                } else {
                    $action = 'error';
                }

                $row->update(['action' => $action, 'errors' => $errors ?: null, 'match' => $match, 'employee_id' => $match['employee_id'] ?? null, 'status' => 'pending', 'result' => null]);
                $counts[$action === 'error' ? 'error' : ($action === 'review' ? 'review' : 'valid')]++;
                if (in_array($action, ['create', 'update', 'skip'], true)) {
                    $counts[$action]++;
                }
            }

            $import->update(['status' => 'validated', 'valid_count' => $counts['valid'], 'error_count' => $counts['error'], 'review_count' => $counts['review'], 'create_count' => $counts['create'], 'update_count' => $counts['update'], 'skip_count' => $counts['skip']]);
        });

        return $import->refresh();
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
        $this->assertStatus($import, ['approved']);
        $import->update(['status' => 'importing']);
        $mapping = $import->mapping ?? [];
        $result = ['created' => 0, 'updated' => 0, 'skipped' => 0, 'failed' => 0, 'ids' => []];

        $operationId = $this->audit->operation('employment', "Employee import #{$import->getKey()} ({$import->original_name})", function () use ($import, $mapping, &$result) {
            foreach ($import->rows()->whereIn('action', ['create', 'update', 'skip'])->cursor() as $row) {
                if ($row->action === 'skip') {
                    $row->update(['status' => 'skipped', 'result' => 'Skipped: existing employee '.($row->match['employee_code'] ?? '')]);
                    $result['skipped']++;

                    continue;
                }

                $record = $this->recordFor($row, $mapping);

                try {
                    $employee = DB::transaction(fn () => $row->action === 'create' ? $this->create($record, $import) : $this->updateExisting($row, $record, $import));
                    $row->update(['status' => 'imported', 'employee_id' => $employee->getKey(), 'result' => ($row->action === 'create' ? 'Created ' : 'Updated ').$employee->employee_code]);
                    $result[$row->action === 'create' ? 'created' : 'updated']++;
                    $result['ids'][] = $employee->getKey();
                } catch (\Throwable $e) {
                    report($e);
                    $row->update(['status' => 'failed', 'result' => Str::limit($e->getMessage(), 500)]);
                    $result['failed']++;
                }
            }

            return ['succeeded' => $result['created'] + $result['updated'] + $result['skipped'], 'failed' => $result['failed'], 'ids' => $result['ids']];
        }, reason: "Import {$import->original_name}", entityType: Employee::class);

        $import->update([
            'status' => 'imported', 'imported_at' => now(), 'operation_id' => $operationId,
            'create_count' => $result['created'], 'update_count' => $result['updated'], 'skip_count' => $result['skipped'], 'failure_count' => $result['failed'],
        ]);

        return $import->refresh();
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

        return $record;
    }

    /** @return list<string> */
    private function validateRecord(array $record, array &$lookups): array
    {
        $errors = [];
        foreach (self::FIELDS as $field => [$label, $required]) {
            [$group, $key] = $field === 'manager_code' ? ['manager_code', null] : explode('.', $field, 2);
            $value = $key === null ? $record['manager_code'] : ($record[$group][$key] ?? null);
            if ($required && ($value === null || $value === '')) {
                $errors[] = "{$label} is required.";
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

        foreach ($record['position'] as $key => $code) {
            if (! isset(self::ORGANISATION[$key])) {
                continue;
            }
            [$column, $model] = self::ORGANISATION[$key];
            $lookups[$model] ??= $model::query()->pluck('id', 'code')->mapWithKeys(fn ($id, $c) => [mb_strtolower((string) $c) => (int) $id])->all();
            if (! isset($lookups[$model][mb_strtolower((string) $code)])) {
                $errors[] = self::FIELDS["position.{$key}"][0]." '{$code}' does not exist.";
            }
        }

        if ($record['manager_code'] !== null && ! Employee::query()->where('employee_code', $record['manager_code'])->exists()) {
            $errors[] = "Manager employee code '{$record['manager_code']}' does not exist.";
        }

        if (isset($record['employee']['employee_code']) && ! preg_match('/^[A-Za-z0-9_-]{1,32}$/', $record['employee']['employee_code'])) {
            $errors[] = 'Employee code may only contain letters, digits, dashes and underscores.';
        }

        return $errors;
    }

    private function create(array $record, EmployeeImport $import): Employee
    {
        $position = [];
        foreach ($record['position'] as $key => $code) {
            [$column, $model] = self::ORGANISATION[$key];
            $position[$column] = $model::query()->whereRaw('lower(code) = ?', [mb_strtolower((string) $code)])->value('id');
        }
        $managerId = $record['manager_code'] ? Employee::query()->where('employee_code', $record['manager_code'])->value('id') : null;
        $employee = $this->hire->handle($record['person'], array_intersect_key($record['employee'], array_flip(['joining_date', 'probation_end_date', 'employee_code', 'work_email', 'work_phone'])), $position, $managerId, "Import #{$import->getKey()}");
        $employee->withAuditReason("Import #{$import->getKey()}")->update(['source' => 'import', 'external_reference' => $record['employee']['external_reference'] ?? $employee->external_reference]);

        return $employee;
    }

    private function updateExisting(EmployeeImportRow $row, array $record, EmployeeImport $import): Employee
    {
        $employee = Employee::query()->with('person')->findOrFail($row->employee_id);
        $this->updatePerson->handle($employee->person, $record['person'], "Import #{$import->getKey()}");
        $employeeFields = array_intersect_key($record['employee'], array_flip(['work_email', 'work_phone', 'external_reference']));
        if ($employeeFields !== []) {
            $employee->withAuditReason("Import #{$import->getKey()}")->update($employeeFields);
        }

        return $employee->refresh();
    }

    private function assertStatus(EmployeeImport $import, array $allowed): void
    {
        if (! in_array($import->status, $allowed, true)) {
            throw new RuntimeException("This import is {$import->status}; expected ".implode(' or ', $allowed).'.');
        }
    }
}
