<?php

namespace App\Domain\Compensation\Imports;

use App\Domain\Audit\Services\AuditRecorder;
use App\Domain\Compensation\Contracts\CompensationOutput;
use App\Domain\Compensation\Exceptions\CompensationRuleViolation;
use App\Domain\Compensation\Models\CompensationChange;
use App\Domain\Compensation\Models\CompensationCycle;
use App\Domain\Compensation\Models\EmployeeSalaryAssignment;
use App\Domain\Compensation\Models\SalaryStructure;
use App\Domain\Compensation\Services\CompensationChanges;
use App\Domain\Compensation\Services\CompensationCycles;
use App\Domain\Compensation\Services\SalaryAssignments;
use App\Domain\Compensation\Services\SalaryRevisions;
use App\Domain\Employment\Enums\ImportMode;
use App\Domain\Employment\Imports\EmployeeImport;
use App\Domain\Employment\Imports\EmployeeImportRow;
use App\Domain\Employment\Imports\ImportGovernance;
use App\Domain\Employment\Imports\ReferenceCells;
use App\Domain\Employment\Imports\Workbooks\ImportReferences;
use App\Domain\Employment\Imports\Workbooks\WorkbookImports;
use App\Domain\Employment\Models\Employee;
use App\Domain\Enterprise\Services\CurrencyRates;
use App\Domain\Identity\Models\User;
use App\Domain\Identity\Scopes\AccessScope;
use App\Domain\Organisation\Models\Company;
use App\Domain\Payroll\Contracts\PayrollClosureReader;
use App\Domain\Payroll\Models\SalaryComponent;
use App\Support\Imports\ReferenceGuide;
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

    /**
     * Fixed fields: key => [label, required]. Fixed-amount components are added as component.CODE (fields()). The salary
     * structure is read by name (names first, 2026-11-26) or by code, as before.
     */
    public const FIELDS = [
        'employee_code' => ['Employee code', true], 'structure_code' => ['Salary structure', false], 'ctc_annual' => ['Annual CTC', false],
        'hike_percent' => ['Hike %', false], 'hike_on' => ['Hike on', false],
        'effective_from' => ['Effective from', false], 'currency' => ['Currency', false], 'variable_target_annual' => ['Variable target (annual)', false],
        'change_type' => ['Change type', false], 'reason' => ['Reason', false],
    ];

    /**
     * Owner, 2026-11-27/28: the salary is the annual CTC — the structure's formulas work out gross, every component, the
     * deductions and take-home from it, so gross is never asked for — or, for a revision, a hike % that the admin puts on
     * the CTC, the gross pay or the take-home (the upload's "A hike % is on", or a Hike on column row by row).
     */
    public const AMOUNTS = ['ctc_annual' => 'Annual CTC', 'hike_percent' => 'Hike %'];

    /** What a hike % can be on: option => label (SalaryRevisions bases). */
    public const HIKE_BASES = ['ctc' => 'CTC', 'gross' => 'Gross pay', 'net' => 'Net pay (take-home)'];

    /** A Hike on cell: value => basis. */
    private const HIKE_ON = ['ctc' => 'ctc', 'gross' => 'gross', 'gross pay' => 'gross', 'net' => 'net', 'net pay' => 'net', 'take-home' => 'net', 'take home' => 'net', 'takehome' => 'net'];

    /** The columns the downloadable starting file has; the others (format guide) are added only when needed. */
    private const TEMPLATE = ['employee_code', 'structure_code', 'ctc_annual', 'effective_from', 'reason'];

    /** The columns that name another record: field => master (ReferenceCells turns a name into the record's code). */
    private const REFERENCES = ['structure_code' => 'salary_structure'];

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

    /** @return array<string, array{0: string, 1: bool}> the fixed fields plus one column per fixed-amount or input component (monthly; inputs: HROne setup parity BP-2) */
    public static function fields(): array
    {
        $components = SalaryComponent::query()->whereIn('calculation_method', ['fixed', 'input'])->where('is_statutory', false)->where('status', 'active')
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
     * Downloadable starting file: the columns a salary needs (employee code, salary structure, annual CTC, effective
     * date, reason) as headers, so an upload maps automatically, and one example row from this tenant's own records (the
     * salary structure by name). Optional columns are in the format guide.
     */
    public function template(): string
    {
        $example = [
            'employee_code' => Employee::query()->orderBy('id')->value('employee_code'),
            'structure_code' => self::sampleStructure(),
            'ctc_annual' => '600000', 'effective_from' => now()->toDateString(), 'currency' => app(CurrencyRates::class)->baseCurrency(),
            'reason' => 'First salary',
        ];
        // Owner, 2026-11-28: the starting file has only what a salary needs; the format guide lists the optional columns.
        $fields = array_intersect_key(self::fields(), array_flip(self::TEMPLATE));

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
            'structure_code' => [ReferenceGuide::name('salary structure', 'Compensation → Salary structures', 'An active one. Required for a first salary; blank on a revision keeps the structure in force.'), self::sampleStructure() ?? 'Standard'],
            'ctc_annual' => ['Annual cost to company. Commas are fine (6,00,000). Gross pay, every component and take-home are worked out from it.', '600000'],
            'hike_percent' => ['Revisions only, instead of the CTC: the hike in % (−5 for a cut), on the salary in force on the effective date. What it is on — CTC, gross or take-home — you choose when mapping the columns.', '10'],
            'hike_on' => ['Only to differ row by row from the choice made when mapping: CTC, gross or net.', 'Gross'],
            'effective_from' => ['YYYY-MM-DD. Leave blank to use the default date you choose when mapping.', now()->toDateString()],
            'currency' => ['Three-letter code; blank means the organisation\'s base currency.', app(CurrencyRates::class)->baseCurrency()],
            'variable_target_annual' => ['Annual variable pay at target, if any.', '60000'],
            'change_type' => ['One of: '.implode(', ', array_keys(config('peopleos.compensation.change_types', []))).'. Blank means hire for a first CTC and revision otherwise; use correction to replace a CTC that starts on the same date.', 'annual_increment'],
            'reason' => ['Shown on the change; blank uses the file name.', 'FY27 increments'],
        ];
        $columns = [];
        foreach (self::fields() as $field => [$label, $required]) {
            $columns[] = [$label, $required ? 'Yes' : 'No', $what[$field][0] ?? 'Monthly amount of this fixed component; blank uses the structure\'s default.', $what[$field][1] ?? ''];
        }

        return ['columns' => $columns, 'notes' => [
            'Only the annual CTC is needed: the salary structure works out gross pay, every component (Basic, HRA, allowances …), PF, ESI, PT and take-home from it. Gross is not asked for.',
            'A new joiner\'s first salary: Employee code, Salary structure, Annual CTC and Effective from (their joining date) — the four columns of the starting file. A revision: Employee code, Effective from and either the new Annual CTC or a Hike %; a blank structure keeps the one in force.',
            'A Hike % is on the CTC, the gross pay or the take-home — you choose when mapping the columns (a Hike on column changes it row by row) — and the structure works out the new CTC. Every revision row shows its effect before you approve: CTC, gross and take-home before → after, with the % change of each.',
            'Optional columns, added only when needed: Hike %, Currency, Variable target (annual), Change type, and a monthly amount for a fixed component (blank = the structure\'s default). Structures built from component amounts rather than a CTC use the upload "Salary structure (employee CTC manual)".',
            'An effective date in a month whose payroll is already finalised is accepted: once the salary takes effect, each closed month is worked out again and the difference of every pay item is paid as arrears in the next open payroll month (Setup → Payroll can switch this off).',
            'One file covers one company; you choose it when mapping the columns.',
            'Running the upload prepares a draft compensation cycle. Nobody\'s pay changes until that cycle is submitted, reviewed, approved and executed by different people.',
            'A row that matches the CTC already in force from the same date is skipped as already on file.',
            ReferenceGuide::NOTE,
        ]];
    }

    /** The first active salary structure by name ("Name (CODE)" when another structure has that name), for examples. */
    private static function sampleStructure(): ?string
    {
        $structure = SalaryStructure::query()->where('status', 'active')->orderBy('id')->first(['id', 'code', 'name']);
        if ($structure === null) {
            return null;
        }
        $shared = SalaryStructure::query()->where('name', $structure->name)->count() > 1
            || SalaryStructure::query()->where('code', $structure->name)->whereKeyNot($structure->getKey())->exists();

        return $shared ? "{$structure->name} ({$structure->code})" : (string) $structure->name;
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
            'salary_structure_code' => 'structure_code', 'structure_code' => 'structure_code',
            'ctc' => 'ctc_annual', 'annual_ctc' => 'ctc_annual', 'effective_date' => 'effective_from', 'from' => 'effective_from', 'variable' => 'variable_target_annual', 'variable_target' => 'variable_target_annual', 'type' => 'change_type',
            'hike' => 'hike_percent', 'hike_percentage' => 'hike_percent', 'increment' => 'hike_percent', 'increment_percent' => 'hike_percent', 'hike_basis' => 'hike_on'];
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
        if (array_intersect(array_keys(self::AMOUNTS), array_values($mapping)) === []) {
            throw new RuntimeException('Map a column with the salary: '.implode(', ', self::AMOUNTS).'.');
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
        // Owner, 2026-11-28: what a Hike % is on, for the whole file (a Hike on column differs row by row).
        $options['hike_on'] = array_key_exists((string) ($options['hike_on'] ?? ''), self::HIKE_BASES) ? (string) $options['hike_on'] : 'ctc';
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

        // Names (not codes) of salary structures resolve through one session for the whole check (ImportReferences).
        $references = app(ImportReferences::class);
        $references->begin($import, checking: true);
        try {
            $this->validateRows($import, $actor, $lookups, $companyId, $mode, $counts, $seen);
            $import->update(['status' => 'validated', 'valid_count' => $counts['valid'], 'error_count' => $counts['error'], 'review_count' => 0, 'create_count' => $counts['create'], 'update_count' => 0, 'skip_count' => $counts['skip']]);
        } finally {
            $references->end();
        }

        return $import->refresh();
    }

    /**
     * @param  array{employees: array<string, int>, structures: array<string, int>}  $lookups
     * @param  array<string, int>  $counts
     * @param  array<int, int>  $seen
     */
    private function validateRows(EmployeeImport $import, User $actor, array $lookups, int $companyId, ImportMode $mode, array &$counts, array &$seen): void
    {
        // No surrounding transaction: the payroll-closure check takes row locks that payroll finalisation
        // also needs, and they must not be held for the whole file.
        foreach ($import->rows()->cursor() as $row) {
            [$employee, $proposal, $errors, $solvedNote] = $this->resolve($row, $import, $lookups);
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
                    $impact = $action === 'create' && $mode->updates() ? $this->impact($employee, $content) : null;
                    if ($action === 'error') {
                        $errors[] = $note;
                    }
                    $match = ['employee_id' => $employee->id, 'employee_code' => $employee->employee_code, 'name' => $employee->person?->full_name, 'matched_on' => ['employee code'], 'reason' => $action === 'skip' ? $note : null]
                        // How a hike became the CTC, the revision's impact, and a back-dated row's arrears (the months, the
                        // month that pays them).
                        + (($w = array_values(array_filter([$solvedNote, $impact['note'] ?? null, $action === 'create' ? $note : null]))) !== [] ? ['warnings' => $w] : [])
                        + ($impact !== null ? ['impact' => $impact['figures']] : []);
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
    }

    public function approve(EmployeeImport $import, User $user, ?string $reason = null): EmployeeImport
    {
        $this->assert($import, ['validated']);
        $this->assertApprover($import, $user);
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

        // Names resolve again at run time (deterministic; mappings confirmed in the review apply), as the uploader.
        $references = app(ImportReferences::class);
        $references->begin($import, checking: false);
        try {
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
        } finally {
            $references->end();
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
     * @return array{0: ?Employee, 1: array<string, mixed>, 2: list<string>, 3: ?string} … and how a hike became the CTC
     */
    private function resolve(EmployeeImportRow $row, EmployeeImport $import, array $lookups): array
    {
        $r = $this->recordFor($row, $import->mapping ?? []);
        // Names first: a salary structure given by name becomes its code (ReferenceCells); a name that cannot be used
        // (shared by two structures, a near miss) is the row's error, with the resolver's message.
        [$r, $refused, $refusedValues] = ReferenceCells::translate($r, self::REFERENCES, self::FIELDS, $import->sheet,
            (int) ($row->data[WorkbookImports::ROW_COLUMN] ?? $row->row_number + 1));
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
            if (! $isComponent && ! in_array($field, ['ctc_annual', 'variable_target_annual', 'hike_percent'], true)) {
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

        // Owner, 2026-11-27/28: the CTC, or a hike % on the CTC in force — one of them per row.
        $given = array_values(array_intersect(array_keys(self::AMOUNTS), array_keys($amounts)));
        if (count($given) === 0 && ! array_filter(array_keys(self::AMOUNTS), fn ($f) => isset($r[$f]))) {
            $errors[] = 'Give the Annual CTC (or, for a revision, the Hike %).';
        } elseif (count($given) > 1) {
            $errors[] = 'Give either the Annual CTC or the Hike %, not both.';
        }
        $hikeOn = (string) ($import->options['hike_on'] ?? 'ctc');
        if (isset($r['hike_on'])) {
            $hikeOn = self::HIKE_ON[mb_strtolower(trim($r['hike_on']))] ?? '';
            if ($hikeOn === '') {
                $errors[] = "Hike on '{$r['hike_on']}' is not one of CTC, gross or net.";
            }
        }
        if (! isset($r['structure_code']) && $employee !== null && $date !== null && app(CompensationOutput::class)->on($employee, $date) === null) {
            $errors[] = 'Salary structure is required for a first salary (nothing is in force on '.Carbon::parse($date)->format('j M Y').' to keep).';
        }

        $proposal = [
            'change_type' => $changeType, 'effective_from' => $date, 'salary_structure_id' => $structureId,
            'ctc_annual' => $amounts['ctc_annual'] ?? null, 'variable_target_annual' => $amounts['variable_target_annual'] ?? null,
            'currency' => $r['currency'] ?? null, 'component_values' => $components,
            'reason' => $r['reason'] ?? "Salary upload {$import->original_name}",
        ];
        $note = null;
        $errors = array_values(array_unique([...$refused, ...ReferenceCells::withoutRepeats($errors, $refusedValues)]));
        if ($errors === [] && $employee !== null && count($given) === 1 && ($given[0] !== 'ctc_annual' || $structureId === null)) {
            try {
                [$proposal, $note] = $this->solved($employee, $given[0], $amounts[$given[0]], $hikeOn, $proposal, isset($r['currency']), isset($r['variable_target_annual']));
            } catch (CompensationRuleViolation $e) {
                $errors[] = $e->getMessage();
            }
        }

        return [$employee, $proposal, $errors, $note];
    }

    /**
     * Owner, 2026-11-28 ("let the admin see the revision's impact"): a revision row's effect — the CTC, the gross pay and
     * the take-home a month before and after, each with its % change (SalaryRevisions::compare, both on the effective date).
     * Null for a first salary or when the breakup cannot be worked out.
     *
     * @param  array<string, mixed>  $content  normalised by CompensationChanges::check
     * @return array{note: string, figures: array<string, ?float>}|null
     */
    private function impact(Employee $employee, array $content): ?array
    {
        $current = app(CompensationOutput::class)->on($employee, $content['effective_from']);
        if ($current === null) {
            return null;
        }
        try {
            $revisions = app(SalaryRevisions::class);
            $before = $revisions->segmentFor($employee, $current, $content['effective_from']);
            $after = app(SalaryAssignments::class)->segment($employee, $content);
            if ($before === null || $after === null) {
                return null;
            }
            $headline = $revisions->compare($employee, $before, $after)['headline'];
        } catch (Throwable) {
            return null;
        }
        $ctcBefore = (float) $current->ctcAnnual;
        $ctcAfter = (float) $content['ctc_annual'];
        $grossAfter = (float) $headline['gross_monthly'];
        $grossBefore = (float) ($headline['gross_before'] ?? 0.0);
        $netAfter = (float) $headline['net_monthly'];
        $netBefore = $headline['net'] !== null && $headline['net'] > -100 ? round($netAfter / (1 + $headline['net'] / 100), 2) : null;
        $pct = fn (?float $p) => $p === null ? '' : ' ('.($p >= 0 ? '+' : '').rtrim(rtrim(number_format($p, 2), '0'), '.').'%)';
        $money = fn (float $v) => number_format($v, 2);

        return [
            'note' => 'Impact: CTC '.$money($ctcBefore).' → '.$money($ctcAfter).$pct(SalaryRevisions::percent($ctcBefore, $ctcAfter))
                .'; gross '.$money($grossBefore).' → '.$money($grossAfter).' a month'.$pct($headline['gross'])
                .($netBefore !== null ? '; take-home '.$money($netBefore).' → '.$money($netAfter).' a month'.$pct($headline['net']) : '').'.',
            'figures' => ['ctc_before' => $ctcBefore, 'ctc_after' => $ctcAfter, 'gross_before' => $grossBefore, 'gross_after' => $grossAfter,
                'net_before' => $netBefore, 'net_after' => $netAfter],
        ];
    }

    /**
     * Owner, 2026-11-27/28: a row given as a hike % on the CTC, gross or take-home in force (or a CTC that keeps the
     * structure in force) becomes a proposal the way the Employee 360 salary wizard makes it (SalaryRevisions solves the
     * structure's formulas for the CTC that gives the target, then SalaryAssignments).
     *
     * @param  array<string, mixed>  $proposal  the row's proposal so far
     * @return array{0: array<string, mixed>, 1: ?string} the proposal and how its CTC was worked out
     */
    private function solved(Employee $employee, string $given, float $value, string $hikeOn, array $proposal, bool $currencyGiven, bool $variableGiven): array
    {
        $state = ['effective_from' => $proposal['effective_from'], 'salary_structure_id' => $proposal['salary_structure_id'] ?? 'keep', 'change_type' => $proposal['change_type'],
            'reason' => $proposal['reason'], 'component_values' => $proposal['component_values'], 'basis' => $given === 'hike_percent' ? $hikeOn : 'ctc']
            + ($currencyGiven ? ['currency' => $proposal['currency']] : [])
            + ($variableGiven ? ['variable_target_annual' => $proposal['variable_target_annual']] : [])
            + ($given === 'hike_percent' ? ['ctc_mode' => 'percent', 'increase_percent' => $value] : ['ctc_mode' => 'amount', 'ctc_annual' => $value]);
        $resolved = app(SalaryRevisions::class)->resolve($employee, $state);
        $data = app(SalaryAssignments::class)->proposal($employee, $resolved['input']);
        $note = $given === 'hike_percent'
            ? 'Hike '.($value >= 0 ? '+' : '').$value.'% on '.(['ctc' => 'the CTC', 'gross' => 'gross pay', 'net' => 'take-home'][$hikeOn] ?? 'the CTC').' → annual CTC '.(is_numeric($data['ctc_annual']) ? number_format((float) $data['ctc_annual'], 2) : '—').'.'
            : null;

        return [array_intersect_key($data, array_flip(['change_type', 'effective_from', 'salary_structure_id', 'ctc_annual', 'variable_target_annual', 'currency', 'component_values', 'reason']))
            + ['currency' => $proposal['currency']], $note];
    }

    /**
     * What execution would do with this proposal: refuse it (error), find it already in force (skip) or
     * write it (create). Mirrors AssignmentWriter, so one stale row cannot block the cycle's all-or-none execution.
     * Data import DI-4: the rules live in UploadTimeline, shared with the pay group and component uploads.
     *
     * @param  array<string, mixed>  $content  normalised by CompensationChanges::check
     * @return array{0: string, 1: ?string} action and the reason for an error or a skip
     */
    private function againstTimeline(Employee $employee, array $content, int $companyId): array
    {
        return app(UploadTimeline::class)->against($employee, $content, $companyId);
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

    /** "6,00,000", "600000.50" and "10%" are numbers; anything else is not. */
    private function amount(string $value): ?float
    {
        $clean = str_replace([',', ' ', "\u{00A0}", '%', '₹'], '', $value);

        return is_numeric($clean) ? (float) $clean : null;
    }

    private function cycleCode(EmployeeImport $import): string
    {
        $code = 'UPLOAD-'.$import->getKey();

        return CompensationCycle::query()->withoutGlobalScope(AccessScope::class)->where('code', $code)->exists() ? $code.'-'.now()->format('YmdHis') : $code;
    }

    /**
     * HROne setup parity P2: the uploader approves their own file, unless the upload type is under maker-checker or
     * workflow approval (ImportGovernance); then another person allowed to make such uploads approves it (the upload
     * model refuses the uploader).
     */
    private function assertApprover(EmployeeImport $import, User $user): void
    {
        if (! ImportGovernance::checkedByAnother((string) $import->type)) {
            $this->assertUploader($import, $user);

            return;
        }
        if (! self::allowed($user)) {
            throw new RuntimeException('Uploading salaries needs compensation.cycles and compensation.propose.');
        }
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
