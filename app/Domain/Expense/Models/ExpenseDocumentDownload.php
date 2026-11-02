<?php

namespace App\Domain\Expense\Models;

use App\Domain\Audit\Concerns\Auditable;
use App\Domain\Identity\Models\User;
use App\Support\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * HROne parity XO-3 (matrix 26 §26.4.2 #11, "Download documents"): the receipt files and / or cover letters of chosen
 * expense reports, prepared as one zip by a queued job (ExpenseDocumentDownloads). Only the person who asked fetches it,
 * for `peopleos.expense.documents.download_hours`; every file put in it is audited when the zip is made. Status moves are
 * written by the service without automatic auditing (as LetterDownload).
 */
#[Fillable(['tenant_id', 'requested_by', 'report_ids', 'documents', 'selection', 'status', 'total', 'included', 'skipped', 'files', 'file_disk', 'file_path', 'expires_at', 'error'])]
class ExpenseDocumentDownload extends Model
{
    use Auditable, BelongsToTenant;

    public const STATUSES = ['queued' => 'Being prepared', 'running' => 'Being prepared', 'ready' => 'Ready', 'failed' => 'Failed', 'expired' => 'Expired'];

    /** What a zip may hold. */
    public const DOCUMENTS = ['receipts' => 'Receipt files', 'cover_letters' => 'Cover letters'];

    protected $attributes = ['status' => 'queued', 'selection' => 'selected', 'total' => 0, 'included' => 0, 'skipped' => 0, 'files' => 0];

    protected function casts(): array
    {
        return ['report_ids' => 'array', 'documents' => 'array', 'expires_at' => 'datetime', 'total' => 'integer', 'included' => 'integer', 'skipped' => 'integer', 'files' => 'integer'];
    }

    public function auditModule(): string
    {
        return 'expense';
    }

    public function auditLabel(): string
    {
        return 'Expense documents download #'.($this->getAttributes()['id'] ?? '');
    }

    public function auditExcludedAttributes(): array
    {
        return [...config('peopleos.audit.ignored_attributes', []), 'file_path', 'file_disk', 'report_ids'];
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
