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

/** A knowledge base article (§50): versioned on publish, audience by rule, optional acknowledgement. */
#[Fillable(['tenant_id', 'title', 'slug', 'category', 'summary', 'body', 'tags', 'audience', 'requires_acknowledgement', 'is_mandatory_reading', 'version', 'effective_from', 'status', 'author_id', 'published_at'])]
class Article extends Model
{
    use Auditable, BelongsToTenant;

    public const STATUSES = ['draft' => 'Draft', 'published' => 'Published', 'archived' => 'Archived'];

    protected $attributes = ['status' => 'draft', 'version' => 1];

    protected static function booted(): void
    {
        static::saving(function (self $a): void {
            $a->slug = Str::slug($a->slug ?: $a->title);
            if ($a->is_mandatory_reading) {
                $a->requires_acknowledgement = true;
            }
        });
    }

    protected function casts(): array
    {
        return ['tags' => 'array', 'audience' => 'array', 'requires_acknowledgement' => 'boolean', 'is_mandatory_reading' => 'boolean', 'version' => 'integer', 'effective_from' => 'date', 'published_at' => 'datetime'];
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

    public function isPublished(): bool
    {
        return $this->status === 'published';
    }
}
