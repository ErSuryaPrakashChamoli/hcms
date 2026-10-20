<?php

namespace App\Domain\Communication\Models;

use App\Domain\Audit\Concerns\Auditable;
use App\Domain\Engagement\Models\Audience;
use App\Domain\Engagement\Models\EngagementCampaign;
use App\Domain\Identity\Models\User;
use App\Domain\Knowledge\Models\Article;
use App\Support\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use RuntimeException;

/**
 * Announcements, circulars, newsletters, policy publications and instructions (§51).
 *
 * Phase 13:
 * - Lifecycle: draft → in review → approved → scheduled → published → archived (or cancelled).
 *   Approval is by a second person (communication.approve) or a configured workflow.
 * - Content is frozen once it leaves draft. A correction is a new version that supersedes this one.
 * - The audience is structured (audiences / criteria), pinned at submission and snapshotted into
 *   communication_recipients at publication.
 * - Policy content stays in the Knowledge Base (article_id link), and a service link points to the
 *   service desk.
 */
#[Fillable([
    'tenant_id', 'title', 'type', 'priority', 'version', 'supersedes_id', 'body', 'audience', 'audience_id', 'audience_criteria', 'is_pinned', 'requires_acknowledgement',
    'publish_at', 'expires_at', 'status', 'author_id', 'article_id', 'campaign_id', 'prepared_by', 'scope_user_id', 'submitted_at', 'approved_by', 'approved_at',
    'decision_note', 'published_at', 'cancelled_at', 'workflow_instance_id', 'attachment_path', 'attachment_name', 'attachment_sha256', 'recipients_count',
    'operation_id', 'idempotency_key', 'lock_version',
])]
class Announcement extends Model
{
    use Auditable, BelongsToTenant;

    public const STATUSES = ['draft' => 'Draft', 'in_review' => 'In review', 'approved' => 'Approved', 'scheduled' => 'Scheduled', 'published' => 'Published', 'archived' => 'Archived', 'cancelled' => 'Cancelled'];

    /** Frozen once the announcement leaves draft (pinning and expiry stay operational). */
    public const CONTENT = ['title', 'type', 'priority', 'body', 'audience', 'audience_id', 'audience_criteria', 'requires_acknowledgement', 'article_id', 'attachment_path', 'attachment_name', 'attachment_sha256', 'version', 'supersedes_id'];

    protected $attributes = ['status' => 'draft', 'type' => 'announcement', 'priority' => 'normal', 'version' => 1, 'lock_version' => 0];

    protected static function booted(): void
    {
        static::updating(function (self $a): void {
            $content = array_intersect(array_keys($a->getDirty()), self::CONTENT);
            // Pinning the audience criteria is part of submission itself.
            if ($a->getRawOriginal('status') === 'draft' || $content === [] || ($content === ['audience_criteria'] && $a->isDirty('status'))) {
                return;
            }
            throw new RuntimeException('A submitted announcement never changes; create a new version ('.implode(', ', $content).').');
        });
        static::deleting(function (self $a): void {
            if ($a->status !== 'draft') {
                throw new RuntimeException('Only a draft announcement can be deleted; archive or cancel it instead.');
            }
        });
    }

    protected function casts(): array
    {
        return [
            'audience' => 'array', 'audience_criteria' => 'array', 'is_pinned' => 'boolean', 'requires_acknowledgement' => 'boolean', 'publish_at' => 'datetime', 'expires_at' => 'datetime',
            'submitted_at' => 'datetime', 'approved_at' => 'datetime', 'published_at' => 'datetime', 'cancelled_at' => 'datetime', 'version' => 'integer', 'recipients_count' => 'integer', 'lock_version' => 'integer',
        ];
    }

    public function auditModule(): string
    {
        return 'communication';
    }

    public function auditLabel(): string
    {
        return $this->title.($this->version > 1 ? ' (v'.$this->version.')' : '');
    }

    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'author_id');
    }

    public function article(): BelongsTo
    {
        return $this->belongsTo(Article::class);
    }

    public function campaign(): BelongsTo
    {
        return $this->belongsTo(EngagementCampaign::class, 'campaign_id');
    }

    public function audienceDefinition(): BelongsTo
    {
        return $this->belongsTo(Audience::class, 'audience_id');
    }

    public function supersedes(): BelongsTo
    {
        return $this->belongsTo(self::class, 'supersedes_id');
    }

    public function reads(): HasMany
    {
        return $this->hasMany(AnnouncementRead::class);
    }

    public function recipients(): HasMany
    {
        return $this->hasMany(CommunicationRecipient::class);
    }

    public function isLive(): bool
    {
        return $this->status === 'published' && ($this->publish_at === null || $this->publish_at->lte(now())) && ($this->expires_at === null || $this->expires_at->gt(now()));
    }

    public function isMandatory(): bool
    {
        return in_array($this->type, config('peopleos.communication.mandatory_types'), true);
    }
}
