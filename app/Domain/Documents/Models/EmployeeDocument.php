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

/** Metadata only; the file itself is on a private disk and served through signed, authorised URLs. */
#[Fillable(['tenant_id', 'employee_id', 'document_type_id', 'title', 'disk', 'path', 'original_name', 'mime_type', 'size_bytes', 'version', 'status', 'issued_on', 'expires_on', 'uploaded_by', 'verified_by', 'verified_at', 'review_note'])]
class EmployeeDocument extends Model
{
    use Auditable, BelongsToTenant;
    use ScopedByEmployee;

    public const STATUSES = ['pending' => 'Pending verification', 'verified' => 'Verified', 'rejected' => 'Rejected', 'archived' => 'Archived'];

    protected function casts(): array
    {
        return [
            'size_bytes' => 'integer',
            'version' => 'integer',
            'issued_on' => 'date',
            'expires_on' => 'date',
            'verified_at' => 'datetime',
        ];
    }

    public function auditModule(): string
    {
        return 'documents';
    }

    public function auditLabel(): string
    {
        return "{$this->title} v{$this->version}";
    }

    public function auditExcludedAttributes(): array
    {
        return [...config('peopleos.audit.ignored_attributes', []), 'path', 'disk'];
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function type(): BelongsTo
    {
        return $this->belongsTo(DocumentType::class, 'document_type_id');
    }

    public function uploader(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }

    public function verifier(): BelongsTo
    {
        return $this->belongsTo(User::class, 'verified_by');
    }

    public function isExpired(): bool
    {
        return $this->expires_on !== null && $this->expires_on->isPast();
    }

    public function isSensitive(): bool
    {
        return $this->type?->isSensitive() ?? false;
    }
}
