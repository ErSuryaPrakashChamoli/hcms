<?php

namespace App\Domain\Employment\Imports;

use App\Domain\Audit\Concerns\Auditable;
use App\Domain\Identity\Models\User;
use App\Support\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** A staged employee import: upload → inspect → map → validate → preview → approve → import → audit. */
#[Fillable(['tenant_id', 'type', 'source', 'original_name', 'disk', 'path', 'size_bytes', 'status', 'headers', 'mapping', 'options', 'row_count', 'valid_count', 'error_count', 'review_count', 'create_count', 'update_count', 'skip_count', 'failure_count', 'operation_id', 'uploaded_by', 'approved_by', 'approved_at', 'run_started_at', 'imported_at', 'error'])]
class EmployeeImport extends Model
{
    use Auditable, BelongsToTenant;

    public const STATUSES = ['uploaded', 'inspected', 'mapped', 'validated', 'approved', 'importing', 'imported', 'failed', 'discarded'];

    /** HROne setup parity P2: where an upload came from (employee_imports.source). */
    public const SOURCES = ['ui' => 'Admin screens', 'api' => 'API'];

    protected $attributes = ['status' => 'uploaded', 'type' => 'employees', 'source' => 'ui'];

    protected static function booted(): void
    {
        // HROne setup parity P2: the approval rule (imports.approval_mode) and the run start time hold for every upload
        // service; status changes raise the import events.
        static::updating(function (EmployeeImport $import): void {
            ImportGovernance::updating($import);
        });
        static::updated(function (EmployeeImport $import): void {
            ImportGovernance::updated($import);
        });
    }

    protected function casts(): array
    {
        return ['headers' => 'array', 'mapping' => 'array', 'options' => 'array', 'approved_at' => 'datetime', 'run_started_at' => 'datetime', 'imported_at' => 'datetime'];
    }

    public function auditModule(): string
    {
        return 'employment';
    }

    public function auditLabel(): string
    {
        return "Import #{$this->getKey()} ({$this->original_name})";
    }

    public function auditExcludedAttributes(): array
    {
        return [...config('peopleos.audit.ignored_attributes', []), 'headers', 'mapping', 'options'];
    }

    public function rows(): HasMany
    {
        return $this->hasMany(EmployeeImportRow::class)->orderBy('row_number');
    }

    public function uploader(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }

    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    public function isFinal(): bool
    {
        return in_array($this->status, ['imported', 'failed', 'discarded'], true);
    }
}
