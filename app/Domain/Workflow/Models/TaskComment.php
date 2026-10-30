<?php

namespace App\Domain\Workflow\Models;

use App\Domain\Audit\Concerns\Auditable;
use App\Domain\Identity\Models\User;
use App\Support\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use RuntimeException;

/**
 * HROne setup parity P3 (Inbox → Discussion): one message in the conversation about a request waiting for approval,
 * between its requester and its approvers. The thread hangs on the workflow run (a workflow step) or on the request
 * itself; `kind` is the Approval Center request type it was posted on. Messages are immutable; each is audited
 * (who, when, on what) without copying its text into the trail. Written only by TaskComments.
 */
#[Fillable(['tenant_id', 'commentable_type', 'commentable_id', 'kind', 'user_id', 'body'])]
class TaskComment extends Model
{
    use Auditable, BelongsToTenant;

    public const UPDATED_AT = null;

    protected static function booted(): void
    {
        static::updating(fn () => throw new RuntimeException('Discussion messages cannot be changed.'));
        static::deleting(fn () => throw new RuntimeException('Discussion messages cannot be removed.'));
    }

    protected function casts(): array
    {
        return ['created_at' => 'datetime'];
    }

    public function auditModule(): string
    {
        return 'workflow';
    }

    public function auditLabel(): string
    {
        return 'Discussion message #'.$this->getKey().' ('.$this->kind.')';
    }

    public function auditExcludedAttributes(): array
    {
        return [...config('peopleos.audit.ignored_attributes', []), 'body'];
    }

    public function commentable(): MorphTo
    {
        return $this->morphTo();
    }

    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }
}
