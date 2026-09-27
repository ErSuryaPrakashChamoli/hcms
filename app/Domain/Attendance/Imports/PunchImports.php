<?php

namespace App\Domain\Attendance\Imports;

use App\Domain\Attendance\Models\AttendanceDevice;
use App\Domain\Attendance\Models\AttendancePunch;
use App\Domain\Attendance\Services\PunchIngestion;
use App\Domain\Audit\Enums\AuditAction;
use App\Domain\Audit\Services\AuditRecorder;
use App\Domain\Employment\Imports\EmployeeImport;
use App\Domain\Employment\Imports\EmployeeImportRow;
use App\Domain\Employment\Models\Employee;
use App\Domain\Identity\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use League\Csv\Reader;
use RuntimeException;

/**
 * Raw punch import (Phase 2 §32): the same staged pipeline as employee imports — upload → inspect
 * → map → validate (duplicates detected by fingerprint) → preview → approve → import → audit.
 * Rows become raw punches through PunchIngestion; attendance records are recalculated from them,
 * never written by the file.
 */
final class PunchImports
{
    public const FIELDS = [
        'employee_code' => ['Employee code', true], 'punched_at' => ['Punch timestamp', true], 'timezone' => ['Timezone', false],
        'direction' => ['Direction (in/out)', false], 'external_id' => ['External punch id', false], 'device_code' => ['Device code', false],
    ];

    public function __construct(private readonly PunchIngestion $ingestion, private readonly AuditRecorder $audit) {}

    public function register(string $path, string $originalName, User $user, ?string $reason = null): EmployeeImport
    {
        $disk = config('peopleos.documents.disk', 'local');
        if (! Storage::disk($disk)->exists($path)) {
            throw new RuntimeException('The uploaded file could not be found on the private disk.');
        }

        $import = new EmployeeImport(['type' => 'punches', 'original_name' => $originalName, 'disk' => $disk, 'path' => $path, 'size_bytes' => (int) Storage::disk($disk)->size($path), 'status' => 'uploaded', 'uploaded_by' => $user->getKey(), 'options' => ['timezone' => null]]);
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

    /** @return array<string, string> */
    public function suggestMapping(array $headers): array
    {
        $aliases = ['employee_code' => 'employee_code', 'code' => 'employee_code', 'emp_code' => 'employee_code', 'employee' => 'employee_code', 'punched_at' => 'punched_at', 'timestamp' => 'punched_at', 'time' => 'punched_at', 'datetime' => 'punched_at', 'punch_time' => 'punched_at',
            'timezone' => 'timezone', 'tz' => 'timezone', 'direction' => 'direction', 'in_out' => 'direction', 'type' => 'direction', 'external_id' => 'external_id', 'id' => 'external_id', 'punch_id' => 'external_id', 'device_code' => 'device_code', 'device' => 'device_code'];
        $mapping = [];
        foreach ($headers as $header) {
            $key = Str::slug($header, '_');
            if (isset($aliases[$key]) && ! in_array($aliases[$key], $mapping, true)) {
                $mapping[$header] = $aliases[$key];
            }
        }

        return $mapping;
    }

    /** @param  array<string, string|null>  $mapping */
    public function map(EmployeeImport $import, array $mapping, array $options = []): EmployeeImport
    {
        $this->assert($import, ['inspected', 'mapped', 'validated']);
        $mapping = array_filter($mapping, fn ($f) => $f !== null && $f !== '');
        foreach ($mapping as $header => $field) {
            if (! in_array($header, $import->headers ?? [], true) || ! isset(self::FIELDS[$field])) {
                throw new RuntimeException("Column [{$header}] or field [{$field}] is not valid.");
            }
        }
        $missing = collect(self::FIELDS)->filter(fn ($d) => $d[1])->keys()->diff(array_values($mapping));
        if ($missing->isNotEmpty()) {
            throw new RuntimeException('Required fields are not mapped: '.$missing->map(fn ($f) => self::FIELDS[$f][0])->implode(', ').'.');
        }
        $import->update(['mapping' => $mapping, 'options' => array_merge($import->options ?? [], $options), 'status' => 'mapped', 'valid_count' => 0, 'error_count' => 0, 'review_count' => 0, 'create_count' => 0, 'update_count' => 0, 'skip_count' => 0]);

        return $import->refresh();
    }

    public function validate(EmployeeImport $import): EmployeeImport
    {
        $this->assert($import, ['mapped', 'validated']);
        $mapping = $import->mapping ?? [];
        $defaultTz = $import->options['timezone'] ?? null;
        $counts = ['valid' => 0, 'error' => 0, 'create' => 0, 'skip' => 0];
        $employees = Employee::query()->pluck('id', 'employee_code')->mapWithKeys(fn ($id, $c) => [mb_strtolower((string) $c) => (int) $id])->all();
        $devices = AttendanceDevice::query()->get()->keyBy(fn ($d) => mb_strtolower($d->code));
        $seen = [];

        DB::transaction(function () use ($import, $mapping, $defaultTz, &$counts, $employees, $devices, &$seen) {
            foreach ($import->rows()->cursor() as $row) {
                $r = $this->recordFor($row, $mapping);
                $errors = [];
                $code = mb_strtolower((string) ($r['employee_code'] ?? ''));
                if ($code === '' || ! isset($employees[$code])) {
                    $errors[] = "Employee code '{$r['employee_code']}' does not exist.";
                }
                $tz = $r['timezone'] ?? $defaultTz;
                if ($tz && ! in_array($tz, timezone_identifiers_list(), true)) {
                    $errors[] = "Timezone '{$tz}' is not valid.";
                }
                $at = null;
                try {
                    $at = Carbon::parse($r['punched_at'] ?? '', $tz ?: config('app.timezone'))->setTimezone(config('app.timezone'));
                } catch (\Throwable) {
                    $errors[] = 'Punch timestamp is not a valid date/time.';
                }
                if (isset($r['direction']) && ! in_array(strtolower($r['direction']), ['in', 'out', 'auto'], true)) {
                    $errors[] = "Direction '{$r['direction']}' must be in, out or auto.";
                }
                $device = isset($r['device_code']) ? $devices->get(mb_strtolower($r['device_code'])) : null;
                if (isset($r['device_code']) && $device === null) {
                    $errors[] = "Device '{$r['device_code']}' does not exist.";
                }

                $action = 'create';
                $match = null;
                if ($errors === []) {
                    $fingerprint = $this->ingestion->fingerprint($device, $r['external_id'] ?? null, $employees[$code], $r['employee_code'], $at, strtolower($r['direction'] ?? 'auto'));
                    if (isset($seen[$fingerprint])) {
                        $errors[] = 'Duplicate of row '.$seen[$fingerprint].' in this file.';
                        $action = 'error';
                    } elseif (AttendancePunch::query()->where('fingerprint', $fingerprint)->exists()) {
                        $action = 'skip';
                        $match = ['reason' => 'Already recorded (same fingerprint).'];
                    }
                    $seen[$fingerprint] = $row->row_number;
                } else {
                    $action = 'error';
                }

                $row->update(['action' => $action, 'errors' => $errors ?: null, 'match' => $match, 'employee_id' => $employees[$code] ?? null, 'status' => 'pending', 'result' => null]);
                $counts[$action === 'error' ? 'error' : 'valid']++;
                if ($action !== 'error') {
                    $counts[$action]++;
                }
            }
            $import->update(['status' => 'validated', 'valid_count' => $counts['valid'], 'error_count' => $counts['error'], 'review_count' => 0, 'create_count' => $counts['create'], 'update_count' => 0, 'skip_count' => $counts['skip']]);
        });

        return $import->refresh();
    }

    public function approve(EmployeeImport $import, User $user, ?string $reason = null): EmployeeImport
    {
        $this->assert($import, ['validated']);
        if ($import->create_count === 0) {
            throw new RuntimeException('Nothing to import: no row would add a punch.');
        }
        $import->withAuditReason($reason)->update(['status' => 'approved', 'approved_by' => $user->getKey(), 'approved_at' => now()]);

        return $import->refresh();
    }

    public function run(EmployeeImport $import, User $user): EmployeeImport
    {
        $this->assert($import, ['approved']);
        $import->update(['status' => 'importing']);
        $mapping = $import->mapping ?? [];
        $defaultTz = $import->options['timezone'] ?? null;
        $devices = AttendanceDevice::query()->get()->keyBy(fn ($d) => mb_strtolower($d->code));
        $result = ['created' => 0, 'skipped' => 0, 'failed' => 0, 'ids' => []];

        $operationId = $this->audit->operation('attendance', "Punch import #{$import->getKey()} ({$import->original_name})", function () use ($import, $mapping, $defaultTz, $devices, &$result) {
            foreach ($import->rows()->whereIn('action', ['create', 'skip'])->cursor() as $row) {
                if ($row->action === 'skip') {
                    $row->update(['status' => 'skipped', 'result' => 'Skipped: already recorded']);
                    $result['skipped']++;

                    continue;
                }
                $r = $this->recordFor($row, $mapping);
                try {
                    $employee = Employee::query()->findOrFail($row->employee_id);
                    $device = isset($r['device_code']) ? $devices->get(mb_strtolower($r['device_code'])) : null;
                    $punch = $this->ingestion->record($employee, $r['punched_at'], strtolower($r['direction'] ?? 'auto'), 'import', $device, $r['external_id'] ?? null, ['source_timezone' => $r['timezone'] ?? $defaultTz, 'source_type' => 'import', 'employee_code' => $r['employee_code'], 'payload' => ['import_id' => $import->getKey(), 'row' => $row->row_number], 'correlation_id' => 'import:'.$import->getKey()]);
                    if ($punch === null) {
                        $row->update(['status' => 'skipped', 'result' => 'Duplicate punch']);
                        $result['skipped']++;
                    } else {
                        $this->audit->record(AuditAction::PunchImported, 'attendance', $punch, metadata: ['import_id' => $import->getKey(), 'row' => $row->row_number]);
                        $row->update(['status' => 'imported', 'result' => 'Punch #'.$punch->id]);
                        $result['created']++;
                        $result['ids'][] = $punch->id;
                    }
                } catch (\Throwable $e) {
                    report($e);
                    $row->update(['status' => 'failed', 'result' => Str::limit($e->getMessage(), 500)]);
                    $result['failed']++;
                }
            }

            return ['succeeded' => $result['created'] + $result['skipped'], 'failed' => $result['failed'], 'ids' => $result['ids']];
        }, reason: "Punch import {$import->original_name}", entityType: AttendancePunch::class);

        $import->update(['status' => 'imported', 'imported_at' => now(), 'operation_id' => $operationId, 'create_count' => $result['created'], 'skip_count' => $result['skipped'], 'failure_count' => $result['failed']]);

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

    /** @return array<string, mixed> */
    private function recordFor(EmployeeImportRow $row, array $mapping): array
    {
        $r = [];
        foreach ($mapping as $header => $field) {
            $v = $row->data[$header] ?? null;
            $v = is_string($v) ? trim($v) : $v;
            if ($v !== null && $v !== '') {
                $r[$field] = $v;
            }
        }

        return $r;
    }

    private function assert(EmployeeImport $import, array $allowed): void
    {
        if ($import->type !== 'punches') {
            throw new RuntimeException('This import is not a punch import.');
        }
        if (! in_array($import->status, $allowed, true)) {
            throw new RuntimeException("This import is {$import->status}; expected ".implode(' or ', $allowed).'.');
        }
    }
}
