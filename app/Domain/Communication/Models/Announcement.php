<?php

namespace App\Domain\Communication\Models;

use App\Domain\Audit\Concerns\Auditable;
use App\Domain\Identity\Models\User;
use App\Domain\Knowledge\Models\Article;
use App\Support\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** Announcements, circulars, newsletters, policy publications, instructions (§51) with targeted audiences. */
#[Fillable(['tenant_id', 'title', 'type', 'body', 'audience', 'is_pinned', 'requires_acknowledgement', 'publish_at', 'expires_at', 'status', 'author_id', 'article_id'])]
class Announcement extends Model
{
    use Auditable, BelongsToTenant;

    public const STATUSES = ['draft' => 'Draft', 'published' => 'Published', 'archived' => 'Archived'];

    protected $attributes = ['status' => 'draft', 'type' => 'announcement'];

    protected function casts(): array
    {
        return ['audience' => 'array', 'is_pinned' => 'boolean', 'requires_acknowledgement' => 'boolean', 'publish_at' => 'datetime', 'expires_at' => 'datetime'];
    }

    public function auditModule(): string
    {
        return 'communication';
    }

    public function auditLabel(): string
    {
        return $this->title;
    }

    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'author_id');
    }

    public function article(): BelongsTo
    {
        return $this->belongsTo(Article::class);
    }

    public function reads(): HasMany
    {
        return $this->hasMany(AnnouncementRead::class);
    }

    public function isLive(): bool
    {
        return $this->status === 'published' && ($this->publish_at === null || $this->publish_at->lte(now())) && ($this->expires_at === null || $this->expires_at->gt(now()));
    }
}
