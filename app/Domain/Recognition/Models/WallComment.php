<?php

namespace App\Domain\Recognition\Models;

use App\Domain\Audit\Concerns\Auditable;
use App\Domain\Identity\Models\User;
use App\Support\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use RuntimeException;

/**
 * HROne setup parity HP-9e: a comment on a wall post. The text is never edited (its author may remove it; a moderator
 * hides it with a reason and may restore it) and stays out of the audit trail.
 */
#[Fillable(['tenant_id', 'wall_post_id', 'user_id', 'body', 'status', 'hidden_at', 'hidden_by', 'hide_reason'])]
class WallComment extends Model
{
    use Auditable, BelongsToTenant;

    public const STATUSES = ['published' => 'Published', 'hidden' => 'Hidden by a moderator', 'removed' => 'Removed by its author'];

    public const MAX = 1000;

    protected $attributes = ['status' => 'published'];

    protected static function booted(): void
    {
        static::saving(function (self $comment): void {
            if (! array_key_exists((string) $comment->status, self::STATUSES)) {
                throw new RuntimeException('Unknown comment status.');
            }
            if ($comment->exists && ($comment->isDirty('body') || $comment->isDirty('wall_post_id') || $comment->isDirty('user_id'))) {
                throw new RuntimeException('A comment is never edited.');
            }
        });
        static::deleting(function (): void {
            throw new RuntimeException('Comments are never deleted; they are removed or hidden.');
        });
    }

    protected function casts(): array
    {
        return ['hidden_at' => 'datetime'];
    }

    /** @return list<string> */
    public function auditExcludedAttributes(): array
    {
        return [...config('peopleos.audit.ignored_attributes', []), 'body'];
    }

    public function auditModule(): string
    {
        return 'recognition';
    }

    public function auditLabel(): string
    {
        return 'Wall comment #'.$this->getKey();
    }

    public function post(): BelongsTo
    {
        return $this->belongsTo(WallPost::class, 'wall_post_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
