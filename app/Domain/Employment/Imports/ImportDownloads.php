<?php

namespace App\Domain\Employment\Imports;

use App\Domain\Audit\Enums\AuditAction;
use App\Domain\Audit\Services\AuditRecorder;
use App\Domain\Employment\Models\Employee;
use App\Domain\Identity\Models\User;
use App\Domain\Identity\Scopes\AccessScope;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Storage;
use League\Csv\Writer;
use RuntimeException;

/**
 * HROne setup parity P2 (Recent imports → Uploaded file / Status file): an upload's original file and its result file
 * (every staged row with its columns, the decided action, the outcome and the errors), for people who may open the
 * upload (EmployeeImportPolicy: the uploader, its checker, or anyone allowed for that type). Each download is audited.
 * Bank details rows keep account numbers sealed, so the result file never carries them.
 */
final class ImportDownloads
{
    public function __construct(private readonly AuditRecorder $audit) {}

    /** @return array{0: string, 1: string} [file name, contents] of the file as uploaded */
    public function original(EmployeeImport $import, User $user): array
    {
        $this->authorise($import, $user);
        $disk = Storage::disk($import->disk);
        if (! $disk->exists($import->path)) {
            throw new RuntimeException('The uploaded file is no longer stored.');
        }
        $contents = (string) $disk->get($import->path);
        $this->audit->record(AuditAction::Download, 'employment', $import, metadata: ['file' => 'original', 'type' => $import->type], entityLabel: $import->auditLabel(), actor: $user);

        return [$import->original_name, $contents];
    }

    /** @return array{0: string, 1: string} [file name, CSV] of every staged row with its result */
    public function results(EmployeeImport $import, User $user): array
    {
        $this->authorise($import, $user);
        if ($import->row_count === 0 && ! $import->rows()->exists()) {
            throw new RuntimeException('The file has not been read yet: inspect it first.');
        }
        $headers = array_values(array_map('strval', $import->headers ?? []));
        $csv = Writer::fromString();
        $csv->insertOne(['Row', ...$headers, 'Action', 'Status', 'Employee', 'Errors / notes', 'Result']);
        $codes = Employee::query()->withoutGlobalScope(AccessScope::class)->whereIn('id', $import->rows()->whereNotNull('employee_id')->pluck('employee_id'))->pluck('employee_code', 'id');
        foreach ($import->rows()->orderBy('row_number')->cursor() as $row) {
            $cells = [];
            foreach ($headers as $header) {
                $value = $row->data[$header] ?? '';
                $cells[] = is_scalar($value) ? (string) $value : json_encode($value);
            }
            $notes = $row->errors ? implode(' ', $row->errors) : (string) ($row->match['reason'] ?? '');
            $csv->insertOne([$row->row_number, ...$cells, $row->action, $row->status, $row->employee_id ? (string) ($codes[$row->employee_id] ?? '') : '', $notes, (string) $row->result]);
        }
        $this->audit->record(AuditAction::Download, 'employment', $import, metadata: ['file' => 'results', 'type' => $import->type], entityLabel: $import->auditLabel(), actor: $user);

        return [pathinfo($import->original_name, PATHINFO_FILENAME).'-results.csv', $csv->toString()];
    }

    private function authorise(EmployeeImport $import, User $user): void
    {
        if (! $user->can('view', $import)) {
            throw new AuthorizationException('You cannot open this upload.');
        }
    }
}
