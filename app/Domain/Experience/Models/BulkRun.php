<?php

namespace App\Domain\Experience\Models;

use App\Domain\Audit\Concerns\Auditable;
use App\Domain\Identity\Models\User;
use App\Support\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * HROne parity WO-1: one bulk run started from a register — who started it, on which register, which operation, the
 * records chosen (selected rows or every record matching the filters) and the outcome counts. It runs queued, with the
 * permissions and access scope of the person who started it; each record is acted on by its owning service and gets a
 * BulkRunItem (done / refused with the reason / failed). Written only by BulkRuns; audited (the record list and the
 * run's input stay out of the trail: the input of "Send e-mail" is the message).
 */
#[Fillable(['tenant_id', 'user_id', 'register', 'operation', 'operation_label', 'selection', 'record_ids', 'input', 'total', 'done', 'refused', 'failed', 'status', 'operation_id', 'error', 'started_at', 'finished_at'])]
class BulkRun extends Model
{
    use Auditable, BelongsToTenant;

    public const STATUSES = ['queued' => 'Queued', 'running' => 'Running', 'completed' => 'Completed', 'failed' => 'Failed'];

    public const SELECTIONS = ['selected' => 'Selected records', 'all_matching' => 'All matching the filters'];

    protected function casts(): array
    {
        return ['record_ids' => 'array', 'input' => 'array', 'started_at' => 'datetime', 'finished_at' => 'datetime'];
    }

    public function auditModule(): string
    {
        return 'experience';
    }

    public function auditLabel(): string
    {
        return 'Bulk run: '.$this->operation_label.' ('.$this->total.')';
    }

    /** @return list<string> */
    public function auditExcludedAttributes(): array
    {
        return [...config('peopleos.audit.ignored_attributes', []), 'record_ids', 'input'];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function items(): HasMany
    {
        return $this->hasMany(BulkRunItem::class);
    }

    public function isFinished(): bool
    {
        return in_array($this->status, ['completed', 'failed'], true);
    }

    /** "12 done · 3 refused · 1 failed" */
    public function summary(): string
    {
        return collect(['done' => $this->done, 'refused' => $this->refused, 'failed' => $this->failed])
            ->filter(fn (int $n, string $k) => $n > 0 || $k === 'done')
            ->map(fn (int $n, string $k) => "{$n} {$k}")->implode(' · ');
    }
}
