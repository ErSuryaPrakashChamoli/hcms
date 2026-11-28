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
 * One bulk upload of employee documents (Admin → Data import → Employee documents): the zips given (kept only until the
 * upload has run or is discarded), the choices made, and the counts of what each file did (DocumentUploadItem). Written
 * only through DocumentBulkUploads: checked → review (the person looks at every match and fixes what is unclear) →
 * queued → running → completed, or failed / discarded.
 */
#[Fillable(['tenant_id', 'status', 'options', 'source_disk', 'sources', 'zip_count', 'employee_count', 'file_count', 'ready', 'needs_type', 'skipped', 'blocked',
    'uploaded', 'failed', 'error', 'created_by', 'started_at', 'finished_at'])]
class DocumentUploadBatch extends Model
{
    use Auditable, BelongsToTenant;

    public const STATUSES = ['review' => 'Waiting for your check', 'queued' => 'Waiting to run', 'running' => 'Uploading', 'completed' => 'Uploaded', 'failed' => 'Failed', 'discarded' => 'Discarded'];

    /** What happens when an employee already has a current document of the type a file matched. */
    public const EXISTING = [
        'version' => ['Add it as a new version', 'The document on file is kept in its history (archived); the new file becomes the current one.'],
        'skip' => ['Keep the one on file', 'The file is skipped and listed, so nothing on file changes. You can still include it while checking.'],
    ];

    protected $attributes = ['status' => 'review', 'zip_count' => 0, 'employee_count' => 0, 'file_count' => 0, 'ready' => 0, 'needs_type' => 0, 'skipped' => 0, 'blocked' => 0, 'uploaded' => 0, 'failed' => 0];

    protected function casts(): array
    {
        return [
            'options' => 'array', 'sources' => 'array',
            'zip_count' => 'integer', 'employee_count' => 'integer', 'file_count' => 'integer', 'ready' => 'integer', 'needs_type' => 'integer',
            'skipped' => 'integer', 'blocked' => 'integer', 'uploaded' => 'integer', 'failed' => 'integer',
            'started_at' => 'datetime', 'finished_at' => 'datetime',
        ];
    }

    public function auditModule(): string
    {
        return 'documents';
    }

    public function auditLabel(): string
    {
        return "Employee documents upload #{$this->id}";
    }

    public function auditExcludedAttributes(): array
    {
        return [...config('peopleos.audit.ignored_attributes', []), 'source_disk', 'sources'];
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function items(): HasMany
    {
        return $this->hasMany(DocumentUploadItem::class, 'document_upload_batch_id');
    }

    public function inReview(): bool
    {
        return $this->status === 'review';
    }

    public function isActive(): bool
    {
        return in_array($this->status, ['queued', 'running'], true);
    }

    public function isFinished(): bool
    {
        return in_array($this->status, ['completed', 'failed', 'discarded'], true);
    }

    public function option(string $key, mixed $default = null): mixed
    {
        return ($this->options ?? [])[$key] ?? $default;
    }

    /** The zips' names, as given ("EMP001_Asha Rao.zip, EMP002_Ravi Kumar.zip and 3 more"). */
    public function zipNames(int $show = 2): string
    {
        $names = array_map(fn ($s) => (string) ($s['name'] ?? ''), (array) ($this->sources ?? []));
        if ($names === [] || $names === ['']) {
            return $this->zip_count.' '.($this->zip_count === 1 ? 'zip' : 'zips');
        }
        $more = count($names) - $show;

        return implode(', ', array_slice($names, 0, $show)).($more > 0 ? " and {$more} more" : '');
    }

    /** "120 uploaded · 3 failed" once run; "118 ready · 2 need a type" while checking. */
    public function summary(): string
    {
        if (in_array($this->status, ['completed', 'failed', 'running'], true)) {
            $parts = ["{$this->uploaded} uploaded"];
            $this->failed > 0 && $parts[] = "{$this->failed} failed";
        } else {
            $parts = ["{$this->ready} ready"];
            $this->needs_type > 0 && $parts[] = "{$this->needs_type} need a type";
            $this->blocked > 0 && $parts[] = "{$this->blocked} cannot be uploaded";
        }
        $this->skipped > 0 && $parts[] = "{$this->skipped} skipped";

        return implode(' · ', $parts);
    }
}
