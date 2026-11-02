<?php

namespace App\Domain\Letters\Models;

use App\Domain\Audit\Concerns\Auditable;
use App\Domain\Identity\Models\User;
use App\Support\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * HROne parity WO-13 (§23.7.1 #6): a bulk download of issued letters, prepared as one zip by a queued job
 * (LetterDownloads). Only the person who asked for it fetches it, for `peopleos.letters.download_hours`; every letter in
 * it is audited as a DOWNLOAD when the zip is made. Status moves are written by the service without automatic auditing.
 */
#[Fillable(['tenant_id', 'requested_by', 'letter_ids', 'selection', 'status', 'total', 'included', 'skipped', 'file_disk', 'file_path', 'expires_at', 'error'])]
class LetterDownload extends Model
{
    use Auditable, BelongsToTenant;

    public const STATUSES = ['queued' => 'Being prepared', 'running' => 'Being prepared', 'ready' => 'Ready', 'failed' => 'Failed', 'expired' => 'Expired'];

    protected $attributes = ['status' => 'queued', 'selection' => 'selected', 'total' => 0, 'included' => 0, 'skipped' => 0];

    protected function casts(): array
    {
        return ['letter_ids' => 'array', 'expires_at' => 'datetime', 'total' => 'integer', 'included' => 'integer', 'skipped' => 'integer'];
    }

    public function auditModule(): string
    {
        return 'letters';
    }

    public function auditExcludedAttributes(): array
    {
        return [...config('peopleos.audit.ignored_attributes', []), 'file_path', 'file_disk', 'letter_ids'];
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
