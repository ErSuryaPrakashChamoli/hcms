<?php

namespace App\Domain\Knowledge\Models;

use App\Domain\Audit\Concerns\Auditable;
use App\Domain\Identity\Models\User;
use App\Support\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

/**
 * A knowledge base article (§50, Phase 12): the working copy and its lifecycle (draft → in_review →
 * approved → published → archived), audience by rule, optional acknowledgement. Readers are served the
 * immutable published version (`published_version`). The working copy is edited only as a draft: a
 * change to a published article is a revision (a new draft) that goes through review again.
 */
#[Fillable(['tenant_id', 'title', 'slug', 'category', 'summary', 'body', 'tags', 'audience', 'requires_acknowledgement', 'is_mandatory_reading', 'version', 'published_version', 'effective_from', 'status', 'author_id', 'reviewer_id', 'submitted_for_review_at', 'reviewed_at', 'review_note', 'approved_by', 'approved_at', 'published_at'])]
class Article extends Model
{
    use Auditable, BelongsToTenant;

    public const STATUSES = ['draft' => 'Draft', 'in_review' => 'In review', 'approved' => 'Approved', 'published' => 'Published', 'archived' => 'Archived'];

    /** The content of the working copy: frozen outside draft (review sees what is published). */
    public const CONTENT = ['title', 'summary', 'body', 'category', 'audience', 'requires_acknowledgement', 'is_mandatory_reading', 'effective_from'];

    protected $attributes = ['status' => 'draft', 'version' => 1];

    protected static function booted(): void
    {
        static::saving(function (self $a): void {
            $a->slug = Str::slug($a->slug ?: $a->title);
            if ($a->is_mandatory_reading) {
                $a->requires_acknowledgement = true;
            }
        });
        static::updating(function (self $a): void {
            $content = array_intersect(array_keys($a->getDirty()), self::CONTENT);
            if ($content !== [] && $a->getRawOriginal('status') !== 'draft') {
                throw new \RuntimeException('Only a draft article is edited; start a revision of a published article ('.implode(', ', $content).').');
            }
        });
    }

    protected function casts(): array
    {
        return ['tags' => 'array', 'audience' => 'array', 'requires_acknowledgement' => 'boolean', 'is_mandatory_reading' => 'boolean', 'version' => 'integer', 'published_version' => 'integer', 'effective_from' => 'date', 'published_at' => 'datetime', 'submitted_for_review_at' => 'datetime', 'reviewed_at' => 'datetime', 'approved_at' => 'datetime'];
    }

    public function auditModule(): string
    {
        return 'kb';
    }

    public function auditLabel(): string
    {
        return $this->title;
    }

    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'author_id');
    }

    public function versions(): HasMany
    {
        return $this->hasMany(ArticleVersion::class)->orderByDesc('version');
    }

    public function reads(): HasMany
    {
        return $this->hasMany(ArticleRead::class);
    }

    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewer_id');
    }

    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    /** Readers can open it: a version is published and the article is not archived. */
    public function isPublished(): bool
    {
        return $this->published_version !== null && $this->status !== 'archived';
    }
}
