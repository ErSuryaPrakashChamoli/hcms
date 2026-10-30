<?php

namespace App\Domain\Recognition\Models;

use App\Domain\Audit\Concerns\Auditable;
use App\Domain\Employment\Models\Employee;
use App\Domain\Identity\Models\User;
use App\Support\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use RuntimeException;

/**
 * HROne setup parity HP-9e: one post on the social wall — an HR post (written on the wall) or a post the system makes
 * for a badge, a reward, a celebration, an announcement, a new joiner or an achievement (SocialWall). Never deleted:
 * moderators hide it (who, when, why) and may restore it; HR posts are the only ones whose text a person wrote, and that
 * text stays out of the audit trail. The person the post is about is `aboutEmployee` (wall visibility is decided by
 * SocialWall, not by the HR access scope of the records it points at).
 */
#[Fillable(['tenant_id', 'type', 'subject_type', 'subject_id', 'employee_id', 'company_id', 'author_user_id', 'title', 'body', 'meta', 'attachments', 'pinned', 'status', 'hidden_at', 'hidden_by', 'hide_reason', 'dedupe_key'])]
class WallPost extends Model
{
    use Auditable, BelongsToTenant;

    /** Post types; the keys of engage.wall.comments_disabled_for name the HROne feed types they restrict. */
    public const TYPES = [
        'hr_post' => 'HR post', 'badge' => 'Badge', 'reward' => 'Reward', 'celebration' => 'Celebration', 'announcement' => 'Announcement',
        'new_joiner' => 'New joiner', 'goal_achievement' => 'Goal achieved', 'learning_certificate' => 'Certificate earned', 'skill_endorsement' => 'Skill endorsement',
    ];

    /** The comment restriction (engage.wall.comments_disabled_for) each type falls under. */
    public const COMMENT_GROUPS = ['hr_post' => 'hr_post', 'badge' => 'badge', 'reward' => 'reward', 'new_joiner' => 'new_joiner', 'goal_achievement' => 'goal_achievement',
        'learning_certificate' => 'goal_achievement', 'skill_endorsement' => 'skill_endorsement'];

    public const STATUSES = ['published' => 'Published', 'hidden' => 'Hidden'];

    protected $attributes = ['status' => 'published', 'pinned' => false];

    protected static function booted(): void
    {
        static::saving(function (self $post): void {
            if (! array_key_exists((string) $post->type, self::TYPES) || ! array_key_exists((string) $post->status, self::STATUSES)) {
                throw new RuntimeException('Unknown wall post type or status.');
            }
            if ($post->exists && ($post->isDirty('type') || $post->isDirty('subject_type') || $post->isDirty('subject_id') || $post->isDirty('author_user_id'))) {
                throw new RuntimeException('A wall post keeps its type, subject and author.');
            }
        });
        static::deleting(function (): void {
            throw new RuntimeException('Wall posts are never deleted; a moderator hides them.');
        });
    }

    protected function casts(): array
    {
        return ['meta' => 'array', 'attachments' => 'array', 'pinned' => 'boolean', 'hidden_at' => 'datetime'];
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
        return 'Wall post #'.$this->getKey();
    }

    public function isPublished(): bool
    {
        return $this->status === 'published';
    }

    public function subject(): MorphTo
    {
        return $this->morphTo();
    }

    /** The person the post is about (the receiver of a badge, the joiner, the person celebrating …). */
    public function aboutEmployee(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'employee_id');
    }

    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'author_user_id');
    }

    public function reactions(): HasMany
    {
        return $this->hasMany(WallReaction::class);
    }

    public function comments(): HasMany
    {
        return $this->hasMany(WallComment::class);
    }
}
