<?php

namespace App\Domain\Compensation\Imports;

use App\Domain\Audit\Services\AuditRecorder;
use App\Domain\Compensation\Exceptions\CompensationRuleViolation;
use App\Domain\Compensation\Models\CompensationChange;
use App\Domain\Compensation\Models\CompensationCycle;
use App\Domain\Compensation\Models\EmployeeSalaryAssignment;
use App\Domain\Compensation\Models\SalaryStructure;
use App\Domain\Compensation\Services\CompensationChanges;
use App\Domain\Compensation\Services\CompensationCycles;
use App\Domain\Employment\Enums\ImportMode;
use App\Domain\Employment\Imports\EmployeeImport;
use App\Domain\Employment\Imports\EmployeeImportRow;
use App\Domain\Employment\Models\Employee;
use App\Domain\Employment\Models\EmployeePosition;
use App\Domain\Identity\Models\User;
use App\Domain\Identity\Scopes\AccessScope;
use App\Domain\Organisation\Models\Company;
use App\Domain\Payroll\Contracts\PayrollClosureReader;
use App\Domain\Payroll\Models\SalaryComponent;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use League\Csv\Reader;
use League\Csv\Writer;
use RuntimeException;
use Throwable;

/**
 * Salary upload: the staged import pipeline (upload → inspect → map → validate → approve → run) for
 * employee compensation. Each row is checked with the rules a proposal must pass
 * (CompensationChanges::check) and the timeline rules execution would refuse, so a file that validates
 * is one whose cycle can execute.
 *
 * Running the import changes nobody's pay: it prepares a draft "salary upload" compensation cycle whose
 * lines are the rows, and that cycle is submitted, reviewed, approved and executed by four different
 * people (CompensationCycles). Only the uploader works on a salary import, because its rows hold pay.
 */
final class SalaryImports
{
    public const TYPE = 'salaries';

    /** Fixed fields: key => [label, required]. Fixed-amount components are added as component.CODE (fields()). */
    public const FIELDS = [
        'employee_code' => ['Employee code', true], 'structure_code' => ['Salary structure code', true], 'ctc_annual' => ['Annual CTC', true],
        'effective_from' => ['Effective from', false], 'currency' => ['Currency', false], 'variable_target_annual' => ['Variable target (annual)', false],
        'change_type' => ['Change type', false], 'reason' => ['Reason', false],
    ];

    /** What a file can be for: mode => [label, description]. "On file" means the employee has a CTC assigned. */
    public const MODES = [
        'new' => ['Assign CTC to new employees', 'For employees with no CTC on file yet (their first salary). Employees who already have one are skipped.'],
        'update' => ['Revise CTC of existing employees', 'For employees who already have a CTC on file. Employees without one are skipped.'],
        'both' => ['Both', 'Assigns a first CTC where there is none and revises the rest.'],
    ];

    public function __construct(
        private readonly CompensationChanges $changes,
        private readonly CompensationCycles $cycles,
        private readonly PayrollClosureReader $closure,
        private readonly AuditRecorder $audit,
    ) {}

    /** Preparing a salary upload is preparing a cycle and proposing its lines. */
    public static function allowed(?User $user): bool
    {
        return $user !== null && $user->hasPermission('compensation.cycles') && $user->hasPermission('compensation.propose');
    }

    /** @return array<string, array{0: string, 1: bool}> the fixed fields plus one column per fixed-amount component (monthly) */
    public static function fields(): array
    {
        $components = SalaryComponent::query()->where('calculation_method', 'fixed')->where('is_statutory', false)->where('status', 'active')
            ->orderBy('sort_order')->pluck('name', 'code');

        return self::FIELDS + $components->mapWithKeys(fn ($name, $code) => ["component.{$code}" => ["{$name} ({$code}), monthly", false]])->all();
    }

    public function register(string $path, string $originalName, User $user, ?string $reason = null, ImportMode $mode = ImportMode::Both): EmployeeImport
    {
        if (! self::allowed($user)) {
            throw new RuntimeException('Uploading salaries needs compensation.cycles and compensation.propose.');
        }
        $disk = config('peopleos.documents.disk', 'local');
        if (! Storage::disk($disk)->exists($path)) {
            throw new RuntimeException('The uploaded file could not be found on the private disk.');
        }
        $companies = Company::query()->pluck('id');

        $import = new EmployeeImport(['type' => self::TYPE, 'original_name' => $originalName, 'disk' => $disk, 'path' => $path, 'size_bytes' => (int) Storage::disk($disk)->size($path), 'status' => 'uploaded', 'uploaded_by' => $user->getKey(),
            'options' => ['company_id' => $companies->count() === 1 ? (int) $companies->first() : null, 'effective_from' => null, 'mode' => $mode->value]]);
        $import->withAuditReason($reason)->save();

        return $import;
    }

    /**
     * Downloadable starting file: every field label as a header, so an upload maps automatically, and one
     * example row from this tenant's own codes.
     */
    public function template(): string
    {
        $example = [
            'employee_code' => Employee::query()->orderBy('id')->value('employee_code'),
            'structure_code' => SalaryStructure::query()->where('status', 'active')->orderBy('id')->value('code'),
            'ctc_annual' => '600000', 'effective_from' => now()->toDateString(), 'currency' => config('peopleos.settings.tenant.base_currency', 'INR'),
        ];
        $fields = self::fields();

        $csv = Writer::fromString();
        $csv->insertOne(array_column($fields, 0));
        $csv->insertOne(array_map(fn ($field) => $example[$field] ?? '', array_keys($fields)));

        return $csv->toString();
    }

    /**
     * The format guide the upload screen shows: every column with whether it is required, what to enter and an example.
     *
     * @return array{columns: list<array{0: string, 1: string, 2: string, 3: string}>, notes: list<string>}
     */
    public function format(): array
    {
        $what = [
            'employee_code' => ['The employee\'s code.', 'EMP0001'],
            'structure_code' => ['Code of an active salary structure (Compensation › Salary structures).', SalaryStructure::query()->where('status', 'active')->orderBy('id')->value('code') ?? 'STANDARD'],
            'ctc_annual' => ['Annual cost to company. Commas are fine (6,00,000).', '600000'],
            'effective_from' => ['YYYY-MM-DD. Leave blank to use the default date you choose when mapping.', now()->toDateString()],
            'currency' => ['Three-letter code; blank means the organisation\'s base currency.', config('peopleos.settings.tenant.base_currency', 'INR')],
            'variable_target_annual' => ['Annual variable pay at target, if any.', '60000'],
            'change_type' => ['One of: '.implode(', ', array_keys(config('peopleos.compensation.change_types', []))).'. Blank means hire for a first CTC and revision otherwise; use correction to replace a CTC that starts on the same date.', 'annual_increment'],
            'reason' => ['Shown on the change; blank uses the file name.', 'FY27 increments'],
        ];
        $columns = [];
        foreach (self::fields() as $field => [$label, $required]) {
            $columns[] = [$label, $required ? 'Yes' : 'No', $what[$field][0] ?? 'Monthly amount of this fixed component; blank uses the structure\'s default.', $what[$field][1] ?? ''];
        }

        return ['columns' => $columns, 'notes' => [
            'One file covers one company; you choose it when mapping the columns.',
            'Running the upload prepares a draft compensation cycle. Nobody\'s pay changes until that cycle is submitted, reviewed, approved and executed by different people.',
            'A row that matches the CTC already in force from the same date is skipped as already on file.',
        ]];
    }

    public function inspect(EmployeeImport $import): EmployeeImport
    {
        $this->assert($import, ['uploaded', 'inspected']);
        $max = (int) config('peopleos.compensation.import_max_rows', 5000);

        $csv = Reader::from(Storage::disk($import->disk)->readStream($import->path));
        $csv->setHeaderOffset(0);
        $headers = array_values(array_map(fn ($h) => trim((string) $h), $csv->getHeader()));
        if ($headers === [] || count(array_filter($headers)) !== count(array_unique(array_filter($headers)))) {
            throw new RuntimeException('The file needs a header row with unique column names.');
        }

        DB::transaction(function () use ($import, $csv, $headers, $max) {
            $import->rows()->delete();
            $count = 0;
            foreach ($csv->getRecords() as $record) {
                if (++$count > $max) {
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
        $aliases = ['code' => 'employee_code', 'emp_code' => 'employee_code', 'employee' => 'employee_code', 'structure' => 'structure_code', 'salary_structure' => 'structure_code',
            'ctc' => 'ctc_annual', 'annual_ctc' => 'ctc_annual', 'effective_date' => 'effective_from', 'from' => 'effective_from', 'variable' => 'variable_target_annual', 'variable_target' => 'variable_target_annual', 'type' => 'change_type'];
        foreach (self::fields() as $field => [$label]) {
            $aliases[Str::slug($label, '_')] = $field;
            $aliases[Str::slug(Str::after($field, '.'), '_')] = $field;
        }
        $mapping = [];
        foreach ($headers as $header) {
            $key = Str::slug($header, '_');
            if (isset($aliases[$key]) && ! in_array($aliases[$key], $mapping, true)) {
                $mapping[$header] = $aliases[$key];
            }
        }

        return $mapping;
    }

    /**
     * @param  array<string, string|null>  $mapping  header => field
     * @param  array{company_id?: int|string|null, effective_from?: string|null}  $options  the company the cycle covers; the date for rows without one
     */
    public function map(EmployeeImport $import, array $mapping, array $options = []): EmployeeImport
    {
        $this->assert($import, ['inspected', 'mapped', 'validated']);
        $fields = self::fields();
        $mapping = array_filter($mapping, fn ($f) => $f !== null && $f !== '');
        foreach ($mapping as $header => $field) {
            if (! in_array($header, $import->headers ?? [], true) || ! isset($fields[$field])) {
                throw new RuntimeException("Column [{$header}] or field [{$field}] is not valid.");
            }
        }
        if (count($mapping) !== count(array_unique($mapping))) {
            throw new RuntimeException('Each field can be mapped from one column only.');
        }
        $missing = collect($fields)->filter(fn ($d) => $d[1])->keys()->diff(array_values($mapping));
        if ($missing->isNotEmpty()) {
            throw new RuntimeException('Required fields are not mapped: '.$missing->map(fn ($f) => $fields[$f][0])->implode(', ').'.');
        }

        $options = array_merge($import->options ?? [], $options);
        if (blank($options['company_id'] ?? null) || ! Company::query()->whereKey($options['company_id'])->exists()) {
            throw new RuntimeException('Choose the company the salaries belong to: one upload prepares one compensation cycle for one company.');
        }
        if (filled($options['effective_from'] ?? null)) {
            try {
                $options['effective_from'] = Carbon::parse($options['effective_from'])->toDateString();
            } catch (Throwable) {
                throw new RuntimeException('The default effective date is not a valid date.');
            }
        } elseif (! in_array('effective_from', $mapping, true)) {
            throw new RuntimeException('Map an Effective from column or choose a default effective date.');
        }
        $options['company_id'] = (int) $options['company_id'];
        $options['mode'] = (ImportMode::tryFrom((string) ($options['mode'] ?? '')) ?? ImportMode::of($import))->value;

        $import->update(['mapping' => $mapping, 'options' => $options, 'status' => 'mapped', 'valid_count' => 0, 'error_count' => 0, 'review_count' => 0, 'create_count' => 0, 'update_count' => 0, 'skip_count' => 0]);

        return $import->refresh();
    }

    /** Check every row as the uploader, who will propose the lines; touches no compensation. */
    public function validate(EmployeeImport $import): EmployeeImport
    {
        $this->assert($import, ['mapped', 'validated']);
        $actor = User::query()->find($import->uploaded_by) ?? throw new RuntimeException('The uploader of this file no longer exists.');
        $lookups = $this->lookups();
        $companyId = (int) ($import->options['company_id'] ?? 0);
        $mode = ImportMode::of($import);
        $counts = ['valid' => 0, 'error' => 0, 'create' => 0, 'skip' => 0];
        $seen = [];

        // No surrounding transaction: the payroll-closure check takes row locks that payroll finalisation
        // also needs, and they must not be held for the whole file.
        foreach ($import->rows()->cursor() as $row) {
            [$employee, $proposal, $errors] = $this->resolve($row, $import, $lookups);
            if ($employee !== null) {
                if (isset($seen[$employee->id])) {
                    $errors[] = "Employee {$employee->employee_code} also appears in row {$seen[$employee->id]}.";
                }
                $seen[$employee->id] ??= $row->row_number;
            }

            $action = 'create';
            $match = null;
            $outside = $errors === [] ? $this->outsideMode($employee, $mode) : null;
            if ($outside !== null) {
                $action = 'skip';
                $match = ['employee_id' => $employee->id, 'employee_code' => $employee->employee_code, 'name' => $employee->person?->full_name, 'matched_on' => ['employee code'], 'reason' => $outside];
            } elseif ($errors === []) {
                try {
                    $content = $this->changes->check($employee, $proposal, $actor);
                    [$action, $note] = $this->againstTimeline($employee, $content, $companyId);
                    if ($action === 'error') {
                        $errors[] = $note;
                    }
                    $match = ['employee_id' => $employee->id, 'employee_code' => $employee->employee_code, 'name' => $employee->person?->full_name, 'matched_on' => ['employee code'], 'reason' => $action === 'skip' ? $note : null];
                } catch (CompensationRuleViolation $e) {
                    $errors[] = $e->getMessage();
                }
            }
            $action = $errors === [] ? $action : 'error';

            $row->update(['action' => $action, 'errors' => $errors ?: null, 'match' => $match, 'employee_id' => $employee?->id, 'status' => 'pending', 'result' => null]);
            $counts[$action === 'error' ? 'error' : 'valid']++;
            if ($action !== 'error') {
                $counts[$action]++;
            }
        }
        $import->update(['status' => 'validated', 'valid_count' => $counts['valid'], 'error_count' => $counts['error'], 'review_count' => 0, 'create_count' => $counts['create'], 'update_count' => 0, 'skip_count' => $counts['skip']]);

        return $import->refresh();
    }

    public function approve(EmployeeImport $import, User $user, ?string $reason = null): EmployeeImport
    {
        $this->assert($import, ['validated']);
        $this->assertUploader($import, $user);
        if ($import->create_count === 0) {
            throw new RuntimeException('Nothing to import: no row would add a line to the compensation cycle.');
        }
        $import->withAuditReason($reason)->update(['status' => 'approved', 'approved_by' => $user->getKey(), 'approved_at' => now()]);

        return $import->refresh();
    }

    /**
     * Prepare the draft salary upload cycle from the approved rows (each row is checked again: facts may
     * have moved since validation). The uploader becomes the cycle's preparer.
     */
    public function run(EmployeeImport $import, User $user): EmployeeImport
    {
        $this->assert($import, ['approved']);
        $this->assertUploader($import, $user);
        $import->update(['status' => 'importing']);
        $lookups = $this->lookups();
        $result = ['created' => 0, 'skipped' => 0, 'failed' => 0];
        $lines = [];

        foreach ($import->rows()->whereIn('action', ['create', 'skip'])->cursor() as $row) {
            if ($row->action === 'skip') {
                $row->update(['status' => 'skipped', 'result' => 'Skipped: '.($row->match['reason'] ?? 'nothing to change')]);
                $result['skipped']++;

                continue;
            }
            [$employee, $proposal, $errors] = $this->resolve($row, $import, $lookups);
            if ($errors !== []) {
                $row->update(['status' => 'failed', 'result' => Str::limit(implode(' ', $errors), 500)]);
                $result['failed']++;

                continue;
            }
            $lines[$row->id] = [$employee, $proposal];
        }

        $cycle = null;
        try {
            $operationId = $this->audit->operation('compensation', "Salary upload #{$import->getKey()} ({$import->original_name})", function () use ($import, $user, $lines, &$result, &$cycle) {
                if ($lines === []) {
                    return ['succeeded' => $result['skipped'], 'failed' => $result['failed']];
                }
                $outcome = $this->cycles->fromUpload([
                    'code' => $this->cycleCode($import), 'name' => Str::limit("Salary upload: {$import->original_name}", 250),
                    'company_id' => $import->options['company_id'] ?? null, 'effective_from' => collect($lines)->map(fn ($line) => $line[1]['effective_from'])->min(),
                ], $lines, $user);
                $cycle = $outcome['cycle'];
                $ids = [];
                foreach ($outcome['lines'] as $rowId => $line) {
                    $row = EmployeeImportRow::query()->findOrFail($rowId);
                    if ($line instanceof CompensationChange) {
                        $row->update(['status' => 'imported', 'result' => "Line {$line->reference} in cycle {$cycle->code}"]);
                        $result['created']++;
                        $ids[] = $line->id;
                    } else {
                        $row->update(['status' => 'failed', 'result' => Str::limit($line, 500)]);
                        $result['failed']++;
                    }
                }

                return ['succeeded' => $result['created'] + $result['skipped'], 'failed' => $result['failed'], 'ids' => $ids];
            }, reason: "Salary upload {$import->original_name}", entityType: CompensationChange::class);
        } catch (Throwable $e) {
            $import->update(['status' => 'failed', 'error' => Str::limit($e->getMessage(), 500)]);

            throw $e;
        }

        $import->update(['status' => 'imported', 'imported_at' => now(), 'operation_id' => $operationId, 'options' => array_merge($import->options ?? [], ['compensation_cycle_id' => $cycle?->id]),
            'create_count' => $result['created'], 'skip_count' => $result['skipped'], 'failure_count' => $result['failed']]);

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

    /** The cycle a run prepared, if any. */
    public function cycle(EmployeeImport $import): ?CompensationCycle
    {
        $id = $import->options['compensation_cycle_id'] ?? null;

        return $id ? CompensationCycle::query()->find($id) : null;
    }

    // ---------------------------------------------------------------------------------------

    /** Why the chosen mode leaves this employee out, or null when the row belongs to it. */
    private function outsideMode(Employee $employee, ImportMode $mode): ?string
    {
        $onFile = EmployeeSalaryAssignment::query()->withoutGlobalScope(AccessScope::class)->active()->where('employee_id', $employee->id)->exists();
        if ($onFile && ! $mode->updates()) {
            return "{$employee->employee_code} already has a CTC on file; choose Revise CTC of existing employees or Both to change it.";
        }
        if (! $onFile && ! $mode->adds()) {
            return "{$employee->employee_code} has no CTC on file yet; choose Assign CTC to new employees or Both to assign one.";
        }

        return null;
    }

    /** @return array{employees: array<string, int>, structures: array<string, int>} lower-cased code => id */
    private function lookups(): array
    {
        $lower = fn ($rows) => $rows->mapWithKeys(fn ($id, $code) => [mb_strtolower((string) $code) => (int) $id])->all();

        return ['employees' => $lower(Employee::query()->pluck('id', 'employee_code')), 'structures' => $lower(SalaryStructure::query()->pluck('id', 'code'))];
    }

    /**
     * The proposal a row asks for, the employee it names (null when unknown) and what is wrong with it.
     *
     * @return array{0: ?Employee, 1: array<string, mixed>, 2: list<string>}
     */
    private function resolve(EmployeeImportRow $row, EmployeeImport $import, array $lookups): array
    {
        $r = $this->recordFor($row, $import->mapping ?? []);
        $errors = [];
        foreach (self::FIELDS as $field => [$label, $required]) {
            if ($required && ! isset($r[$field])) {
                $errors[] = "{$label} is required.";
            }
        }

        $employee = null;
        if (isset($r['employee_code'])) {
            $id = $lookups['employees'][mb_strtolower($r['employee_code'])] ?? null;
            $employee = $id ? Employee::query()->with('person')->find($id) : null;
            if ($employee === null) {
                $errors[] = "Employee code '{$r['employee_code']}' does not exist.";
            }
        }
        $structureId = isset($r['structure_code']) ? ($lookups['structures'][mb_strtolower($r['structure_code'])] ?? null) : null;
        if (isset($r['structure_code']) && $structureId === null) {
            $errors[] = "Salary structure '{$r['structure_code']}' does not exist.";
        }

        $date = $r['effective_from'] ?? $import->options['effective_from'] ?? null;
        try {
            $date = $date === null ? null : Carbon::parse($date)->toDateString();
        } catch (Throwable) {
            $errors[] = "Effective from '{$date}' is not a valid date.";
            $date = null;
        }
        if ($date === null && ! isset($r['effective_from'])) {
            $errors[] = 'Effective from is required (map the column or choose a default date).';
        }

        $amounts = [];
        $components = [];
        foreach ($r as $field => $value) {
            $isComponent = str_starts_with($field, 'component.');
            if (! $isComponent && ! in_array($field, ['ctc_annual', 'variable_target_annual'], true)) {
                continue;
            }
            $amount = $this->amount($value);
            if ($amount === null) {
                $errors[] = ($isComponent ? 'The amount for component '.Str::after($field, '.') : self::FIELDS[$field][0])." '{$value}' is not a number.";
            } elseif ($isComponent) {
                $components[Str::after($field, '.')] = $amount;
            } else {
                $amounts[$field] = $amount;
            }
        }

        $changeType = null;
        if (isset($r['change_type'])) {
            $types = config('peopleos.compensation.change_types', []);
            $changeType = collect($types)->search(fn ($label, $key) => in_array(mb_strtolower($r['change_type']), [mb_strtolower($key), mb_strtolower($label)], true)) ?: null;
            if ($changeType === null) {
                $errors[] = "Change type '{$r['change_type']}' is not one of: ".implode(', ', array_keys($types)).'.';
            }
        } elseif ($employee !== null && $date !== null) {
            // First compensation unless the employee already has compensation that starts on or before the date.
            $earlier = EmployeeSalaryAssignment::query()->withoutGlobalScope(AccessScope::class)->active()->where('employee_id', $employee->id)->where('effective_from', '<=', $date.' 23:59:59')->exists();
            $changeType = $earlier ? 'revision' : 'hire';
        }

        return [$employee, [
            'change_type' => $changeType, 'effective_from' => $date, 'salary_structure_id' => $structureId,
            'ctc_annual' => $amounts['ctc_annual'] ?? null, 'variable_target_annual' => $amounts['variable_target_annual'] ?? null,
            'currency' => $r['currency'] ?? null, 'component_values' => $components,
            'reason' => $r['reason'] ?? "Salary upload {$import->original_name}",
        ], array_values(array_unique($errors))];
    }

    /**
     * What execution would do with this proposal: refuse it (error), find it already in force (skip) or
     * write it (create). Mirrors AssignmentWriter, so one stale row cannot block the cycle's all-or-none execution.
     *
     * @param  array<string, mixed>  $content  normalised by CompensationChanges::check
     * @return array{0: string, 1: ?string} action and the reason for an error or a skip
     */
    private function againstTimeline(Employee $employee, array $content, int $companyId): array
    {
        $date = Carbon::parse($content['effective_from']);
        $held = EmployeePosition::query()->withoutGlobalScope(AccessScope::class)->where('employee_id', $employee->id)->effectiveOn($date)->orderByDesc('effective_from')->value('company_id');
        if ((int) $held !== $companyId) {
            return ['error', "Employee {$employee->employee_code} does not work for ".Company::query()->whereKey($companyId)->value('name')." on {$date->toDateString()}; upload other companies separately."];
        }

        // A decision still open for the employee (or a line of another cycle not yet submitted) would make
        // one of the two stale at approval.
        $pending = CompensationChange::query()->withoutGlobalScope(AccessScope::class)->where('employee_id', $employee->id)
            ->where(fn ($q) => $q->whereIn('status', [...CompensationChange::PENDING, 'approved'])->orWhere(fn ($q) => $q->where('status', 'draft')->whereNotNull('compensation_cycle_id')))
            ->value('reference');
        if ($pending !== null) {
            return ['error', "Compensation change {$pending} for this employee is still open; finish or cancel it first."];
        }

        $same = EmployeeSalaryAssignment::query()->withoutGlobalScope(AccessScope::class)->active()->where('employee_id', $employee->id)
            ->where('effective_from', '>=', $date->toDateString())->where('effective_from', '<=', $date->toDateString().' 23:59:59')->first();
        if ($same !== null) {
            $values = fn (?array $v) => collect($v ?? [])->map(fn ($a) => round((float) $a, 2))->sortKeys()->all();
            $identical = (int) $same->salary_structure_id === (int) $content['salary_structure_id'] && round((float) $same->ctc_annual, 2) === $content['ctc_annual']
                && $same->currency === $content['currency'] && $values($same->component_values) === $values($content['component_values'])
                && ($same->variable_target_annual === null ? null : round((float) $same->variable_target_annual, 2)) === $content['variable_target_annual'];
            if ($identical) {
                return ['skip', "Already in force from {$date->toDateString()}."];
            }
            if ($content['change_type'] !== 'correction') {
                return ['error', "Compensation already starts on {$date->toDateString()}; set Change type to correction to replace it, or use another date."];
            }
        }

        $closed = $this->closure->closedOnOrAfter((int) $employee->id, $date);
        if ($closed !== null) {
            return ['error', 'Payroll is finalized through '.$closed->toDateString().'; use an effective date after it and pay the difference as an arrear.'];
        }

        return ['create', null];
    }

    /** @return array<string, string> field => non-empty value */
    private function recordFor(EmployeeImportRow $row, array $mapping): array
    {
        $r = [];
        foreach ($mapping as $header => $field) {
            $v = $row->data[$header] ?? null;
            $v = is_string($v) ? trim($v) : $v;
            if ($v !== null && $v !== '') {
                $r[$field] = (string) $v;
            }
        }

        return $r;
    }

    /** "6,00,000" and "600000.50" are numbers; anything else is not. */
    private function amount(string $value): ?float
    {
        $clean = str_replace([',', ' ', "\u{00A0}"], '', $value);

        return is_numeric($clean) ? (float) $clean : null;
    }

    private function cycleCode(EmployeeImport $import): string
    {
        $code = 'UPLOAD-'.$import->getKey();

        return CompensationCycle::query()->withoutGlobalScope(AccessScope::class)->where('code', $code)->exists() ? $code.'-'.now()->format('YmdHis') : $code;
    }

    private function assertUploader(EmployeeImport $import, User $user): void
    {
        if ((int) $import->uploaded_by !== (int) $user->getKey()) {
            throw new RuntimeException('Only the person who uploaded a salary file works on it.');
        }
        if (! self::allowed($user)) {
            throw new RuntimeException('Uploading salaries needs compensation.cycles and compensation.propose.');
        }
    }

    private function assert(EmployeeImport $import, array $allowed): void
    {
        if ($import->type !== self::TYPE) {
            throw new RuntimeException('This import is not a salary upload.');
        }
        if (! in_array($import->status, $allowed, true)) {
            throw new RuntimeException("This import is {$import->status}; expected ".implode(' or ', $allowed).'.');
        }
    }
}
