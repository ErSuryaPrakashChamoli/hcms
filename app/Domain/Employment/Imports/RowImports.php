<?php

namespace App\Domain\Employment\Imports;

use App\Domain\Audit\Services\AuditRecorder;
use App\Domain\Employment\Enums\ImportMode;
use App\Domain\Employment\Models\Employee;
use App\Domain\Identity\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use League\Csv\Reader;
use League\Csv\Writer;
use RuntimeException;
use Throwable;

/**
 * The staged upload pipeline for one-row-one-change files (HROne parity uploads: leave balances, roster, geo
 * locations, overtime, day overrides …), on the same tables and steps as the other uploads: upload → inspect →
 * map → validate → approve → run, each run one audited operation. A subclass declares its fields, decides each
 * row (`check`) and applies it (`apply`) through the owning domain service; nothing outside staging changes
 * before Run.
 */
abstract class RowImports
{
    /** The import type stored on employee_imports.type. */
    public const TYPE = '';

    public function __construct(protected readonly AuditRecorder $audit) {}

    /** @return array<string, array{0: string, 1: bool}> field => [label, required] */
    abstract public static function fields(): array;

    /** Human name, e.g. "Leave balance upload". */
    abstract public function label(): string;

    /** Audit module of the run operation. */
    abstract protected function module(): string;

    /** Model class the run operation touches (for the audit trail). */
    abstract protected function entityType(): string;

    /**
     * Decide one row: ['action' => create|update|skip|error, 'errors' => list<string>, 'match' => ?array, 'employee_id' => ?int].
     *
     * @param  array<string, string>  $r  the mapped, trimmed, non-empty values
     * @param  array<string, mixed>  $context  what prepare() loaded once for the file
     * @return array{action: string, errors?: list<string>, match?: ?array, employee_id?: ?int}
     */
    abstract protected function check(array $r, array &$context, EmployeeImportRow $row, EmployeeImport $import): array;

    /** Apply one validated row; return the result text kept on the row. */
    abstract protected function apply(array $r, EmployeeImportRow $row, EmployeeImport $import, User $user): string;

    /** @return array{columns: list<array{0: string, 1: string, 2: string, 3: string}>, notes: list<string>} */
    abstract public function format(): array;

    /** @return list<list<string>> example rows for the template, in field order */
    abstract protected function examples(): array;

    /** Lookups loaded once per validation (employees by code etc.). @return array<string, mixed> */
    protected function prepare(EmployeeImport $import): array
    {
        return ['employees' => Employee::query()->pluck('id', 'employee_code')->mapWithKeys(fn ($id, $c) => [mb_strtolower((string) $c) => (int) $id])->all(), 'seen' => []];
    }

    /** @return array<string, string> extra header aliases => field */
    protected function aliases(): array
    {
        return [];
    }

    public function template(): string
    {
        $csv = Writer::fromString();
        $csv->insertOne(array_column(static::fields(), 0));
        foreach ($this->examples() as $example) {
            $csv->insertOne($example);
        }

        return $csv->toString();
    }

    public function register(string $path, string $originalName, User $user, ?string $reason = null, array $options = []): EmployeeImport
    {
        $disk = config('peopleos.documents.disk', 'local');
        if (! Storage::disk($disk)->exists($path)) {
            throw new RuntimeException('The uploaded file could not be found on the private disk.');
        }

        $import = new EmployeeImport(['type' => static::TYPE, 'original_name' => $originalName, 'disk' => $disk, 'path' => $path, 'size_bytes' => (int) Storage::disk($disk)->size($path), 'status' => 'uploaded', 'uploaded_by' => $user->getKey(), 'options' => $options]);
        $import->withAuditReason($reason)->save();

        return $import;
    }

    public function inspect(EmployeeImport $import): EmployeeImport
    {
        $this->assert($import, ['uploaded', 'inspected']);
        $max = (int) config('peopleos.attendance.import_max_rows', 50000);

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

    /** @return array<string, string> header => field, by label, field key or alias */
    public function suggestMapping(array $headers): array
    {
        $byName = [];
        foreach (static::fields() as $field => [$label]) {
            $byName[Str::slug($label, '_')] = $field;
            $byName[$field] = $field;
        }
        $byName += $this->aliases() + ['code' => 'employee_code', 'emp_code' => 'employee_code', 'employee' => 'employee_code'];

        $mapping = [];
        foreach ($headers as $header) {
            $key = Str::slug($header, '_');
            $field = $byName[$key] ?? null;
            if ($field !== null && isset(static::fields()[$field]) && ! in_array($field, $mapping, true)) {
                $mapping[$header] = $field;
            }
        }

        return $mapping;
    }

    /** @param  array<string, string|null>  $mapping */
    public function map(EmployeeImport $import, array $mapping, array $options = []): EmployeeImport
    {
        $this->assert($import, ['inspected', 'mapped', 'validated']);
        $fields = static::fields();
        $mapping = array_filter($mapping, fn ($f) => $f !== null && $f !== '');
        foreach ($mapping as $header => $field) {
            if (! in_array($header, $import->headers ?? [], true) || ! isset($fields[$field])) {
                throw new RuntimeException("Column [{$header}] or field [{$field}] is not valid.");
            }
        }
        $missing = collect($fields)->filter(fn ($d) => $d[1])->keys()->diff(array_values($mapping));
        if ($missing->isNotEmpty()) {
            throw new RuntimeException('Required fields are not mapped: '.$missing->map(fn ($f) => $fields[$f][0])->implode(', ').'.');
        }
        $import->update(['mapping' => $mapping, 'options' => array_merge($import->options ?? [], array_filter($options, fn ($v) => $v !== null)), 'status' => 'mapped', 'valid_count' => 0, 'error_count' => 0, 'review_count' => 0, 'create_count' => 0, 'update_count' => 0, 'skip_count' => 0]);

        return $import->refresh();
    }

    public function validate(EmployeeImport $import): EmployeeImport
    {
        $this->assert($import, ['mapped', 'validated']);
        $mapping = $import->mapping ?? [];
        $counts = ['valid' => 0, 'error' => 0, 'create' => 0, 'update' => 0, 'skip' => 0];
        $context = $this->prepare($import);
        $mode = ImportMode::of($import);

        DB::transaction(function () use ($import, $mapping, &$counts, &$context, $mode) {
            foreach ($import->rows()->orderBy('row_number')->cursor() as $row) {
                $r = $this->recordFor($row, $mapping);
                try {
                    $decision = $this->check($r, $context, $row, $import);
                } catch (Throwable $e) {
                    $decision = ['action' => 'error', 'errors' => [$e->getMessage()]];
                }
                $action = ($decision['errors'] ?? []) !== [] ? 'error' : $decision['action'];
                $match = $decision['match'] ?? null;
                // The "new or existing" choice: rows outside it are skipped, never errors.
                if (($action === 'create' && $mode === ImportMode::Update) || ($action === 'update' && $mode === ImportMode::New)) {
                    $match = ['reason' => $action === 'create' ? 'Not on file; this upload only updates.' : 'Already on file; this upload only adds.'];
                    $action = 'skip';
                }

                $row->update(['action' => $action, 'errors' => ($decision['errors'] ?? []) ?: null, 'match' => $match, 'employee_id' => $decision['employee_id'] ?? null, 'status' => 'pending', 'result' => null]);
                $counts[$action === 'error' ? 'error' : 'valid']++;
                if ($action !== 'error') {
                    $counts[$action]++;
                }
            }
            $import->update(['status' => 'validated', 'valid_count' => $counts['valid'], 'error_count' => $counts['error'], 'review_count' => 0, 'create_count' => $counts['create'], 'update_count' => $counts['update'], 'skip_count' => $counts['skip']]);
        });

        return $import->refresh();
    }

    public function approve(EmployeeImport $import, User $user, ?string $reason = null): EmployeeImport
    {
        $this->assert($import, ['validated']);
        if ($import->create_count + $import->update_count === 0) {
            throw new RuntimeException('Nothing to import: no row would change anything.');
        }
        $import->withAuditReason($reason)->update(['status' => 'approved', 'approved_by' => $user->getKey(), 'approved_at' => now()]);

        return $import->refresh();
    }

    public function run(EmployeeImport $import, User $user): EmployeeImport
    {
        $this->assert($import, ['approved']);
        $import->update(['status' => 'importing']);
        $mapping = $import->mapping ?? [];
        $result = ['created' => 0, 'updated' => 0, 'skipped' => 0, 'failed' => 0];

        $operationId = $this->audit->operation($this->module(), $this->label()." #{$import->getKey()} ({$import->original_name})", function () use ($import, $mapping, $user, &$result) {
            foreach ($import->rows()->whereIn('action', ['create', 'update', 'skip'])->orderBy('row_number')->cursor() as $row) {
                if ($row->action === 'skip') {
                    $row->update(['status' => 'skipped', 'result' => 'Skipped: '.($row->match['reason'] ?? 'nothing to change')]);
                    $result['skipped']++;

                    continue;
                }
                try {
                    $text = DB::transaction(fn () => $this->apply($this->recordFor($row, $mapping), $row, $import, $user));
                    $row->update(['status' => 'imported', 'result' => Str::limit($text, 500)]);
                    $result[$row->action === 'update' ? 'updated' : 'created']++;
                } catch (Throwable $e) {
                    if (! $e instanceof RuntimeException) {
                        report($e);
                    }
                    $row->update(['status' => 'failed', 'result' => Str::limit($e->getMessage(), 500)]);
                    $result['failed']++;
                }
            }

            return ['succeeded' => $result['created'] + $result['updated'] + $result['skipped'], 'failed' => $result['failed']];
        }, reason: $this->label().' '.$import->original_name, entityType: $this->entityType());

        $import->update(['status' => 'imported', 'imported_at' => now(), 'operation_id' => $operationId, 'create_count' => $result['created'], 'update_count' => $result['updated'], 'skip_count' => $result['skipped'], 'failure_count' => $result['failed']]);

        return $import->refresh();
    }

    public function discard(EmployeeImport $import, ?string $reason = null): EmployeeImport
    {
        if (in_array($import->status, ['importing', 'imported'], true)) {
            throw new RuntimeException('A completed import cannot be discarded.');
        }
        $import->withAuditReason($reason)->update(['status' => 'discarded']);

        return $import;
    }

    /** The employee a row names, or an error. @return array{0: ?int, 1: list<string>} */
    protected function employeeOf(array $r, array $context): array
    {
        $code = mb_strtolower((string) ($r['employee_code'] ?? ''));

        return $code !== '' && isset($context['employees'][$code]) ? [$context['employees'][$code], []] : [null, ["Employee code '".($r['employee_code'] ?? '')."' does not exist."]];
    }

    /** A strict date (YYYY-MM-DD or DD-MM-YYYY / DD/MM/YYYY), or null. */
    protected function date(?string $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }
        foreach (['Y-m-d', 'd-m-Y', 'd/m/Y'] as $format) {
            $d = \DateTime::createFromFormat('!'.$format, $value);
            if ($d !== false && $d->format($format) === $value) {
                return $d->format('Y-m-d');
            }
        }

        return null;
    }

    /** @return array<string, string> */
    protected function recordFor(EmployeeImportRow $row, array $mapping): array
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

    protected function assert(EmployeeImport $import, array $allowed): void
    {
        if ($import->type !== static::TYPE) {
            throw new RuntimeException('This import is not a '.strtolower($this->label()).'.');
        }
        if (! in_array($import->status, $allowed, true)) {
            throw new RuntimeException("This import is {$import->status}; expected ".implode(' or ', $allowed).'.');
        }
    }
}
