<?php

namespace App\Domain\Documents\Models;

use App\Domain\Audit\Concerns\Auditable;
use App\Domain\Identity\Models\User;
use App\Support\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * HROne parity WO-14 (§23.7.3 "Share letters / documents"): one bulk share of ready-made files — a document type, a title,
 * the files (a zip with a sheet of employee code + file name, or one common file for everyone chosen), the optional message,
 * how employees are told, and what happened to each row (DocumentShareItem). Written only through DocumentShares; the
 * uploaded files are kept until the share has run. Unpublishing hides the shared documents from the employees (D-WO5);
 * nothing is deleted.
 */
#[Fillable(['tenant_id', 'document_type_id', 'title', 'mode', 'message', 'notify_in_app', 'email', 'email_to', 'selection', 'source_disk', 'source_path', 'source_name', 'sheet_path', 'sheet_name',
    'unused_files', 'status', 'total', 'shared', 'failed', 'emailed', 'error', 'created_by', 'started_at', 'finished_at', 'unpublished_at', 'unpublished_by', 'unpublish_reason'])]
class DocumentShareBatch extends Model
{
    use Auditable, BelongsToTenant;

    public const MODES = ['sheet' => 'A zip of files with a sheet (employee code + file name)', 'common' => 'One common file for everyone chosen'];

    public const STATUSES = ['queued' => 'Waiting to run', 'running' => 'Sharing', 'completed' => 'Shared', 'failed' => 'Failed'];

    public const EMAIL_TO = ['work' => 'Work e-mail', 'personal' => 'Personal e-mail'];

    protected $attributes = ['status' => 'queued', 'total' => 0, 'shared' => 0, 'failed' => 0, 'emailed' => 0, 'notify_in_app' => true, 'email' => false];

    protected function casts(): array
    {
        return [
            'notify_in_app' => 'boolean', 'email' => 'boolean', 'selection' => 'array', 'unused_files' => 'array',
            'total' => 'integer', 'shared' => 'integer', 'failed' => 'integer', 'emailed' => 'integer',
            'started_at' => 'datetime', 'finished_at' => 'datetime', 'unpublished_at' => 'datetime',
        ];
    }

    public function auditModule(): string
    {
        return 'documents';
    }

    public function auditLabel(): string
    {
        return "Document share #{$this->id}: {$this->title}";
    }

    public function auditExcludedAttributes(): array
    {
        return [...config('peopleos.audit.ignored_attributes', []), 'source_disk', 'source_path', 'sheet_path', 'selection', 'unused_files'];
    }

    public function type(): BelongsTo
    {
        return $this->belongsTo(DocumentType::class, 'document_type_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function unpublisher(): BelongsTo
    {
        return $this->belongsTo(User::class, 'unpublished_by');
    }

    public function items(): HasMany
    {
        return $this->hasMany(DocumentShareItem::class, 'document_share_batch_id');
    }

    public function isFinished(): bool
    {
        return in_array($this->status, ['completed', 'failed'], true);
    }

    /** "12 shared · 2 failed" (and how many were unpublished since). */
    public function summary(?int $unpublished = null): string
    {
        $parts = ["{$this->shared} shared"];
        if ($this->failed > 0) {
            $parts[] = "{$this->failed} failed";
        }
        if ($unpublished) {
            $parts[] = "{$unpublished} unpublished";
        }

        return implode(' · ', $parts);
    }
}
