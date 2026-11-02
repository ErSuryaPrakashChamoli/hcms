<?php

namespace App\Domain\Documents\Models;

use App\Domain\Audit\Concerns\Auditable;
use App\Domain\Employment\Models\Employee;
use App\Domain\Identity\Concerns\ScopedByEmployee;
use App\Domain\Identity\Models\User;
use App\Support\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * HROne parity WO-4 (§23.3.1 #11): "Download all documents" of one employee (an upcoming joiner), prepared as a zip by a
 * queued job (EmployeeDocumentDownloads). Only the person who asked fetches it, for `peopleos.lifecycle.joiners.download_hours`;
 * every document put in it is audited as a DOWNLOAD when the zip is made. Status moves are written without automatic auditing.
 */
#[Fillable(['tenant_id', 'employee_id', 'requested_by', 'status', 'total', 'included', 'skipped', 'file_disk', 'file_path', 'expires_at', 'error'])]
class EmployeeDocumentDownload extends Model
{
    use Auditable, BelongsToTenant;
    use ScopedByEmployee;

    public const STATUSES = ['queued' => 'Being prepared', 'running' => 'Being prepared', 'ready' => 'Ready', 'failed' => 'Failed', 'expired' => 'Expired'];

    protected $attributes = ['status' => 'queued', 'total' => 0, 'included' => 0, 'skipped' => 0];

    protected function casts(): array
    {
        return ['expires_at' => 'datetime', 'total' => 'integer', 'included' => 'integer', 'skipped' => 'integer'];
    }

    public function auditModule(): string
    {
        return 'documents';
    }

    public function auditExcludedAttributes(): array
    {
        return [...config('peopleos.audit.ignored_attributes', []), 'file_path', 'file_disk'];
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function requester(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by');
    }

    public function isReady(): bool
    {
        return $this->status === 'ready' && $this->expires_at !== null && $this->expires_at->isFuture() && filled($this->file_path);
    }
}
