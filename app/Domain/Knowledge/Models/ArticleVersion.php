<?php

namespace App\Domain\Knowledge\Models;

use App\Domain\Identity\Models\User;
use App\Support\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Immutable snapshot of an article at each publish (Phase 12: content hash, reviewer, approver). Never edited or deleted. */
#[Fillable(['tenant_id', 'article_id', 'version', 'title', 'summary', 'category', 'body', 'body_hash', 'effective_from', 'published_by', 'reviewed_by', 'approved_by', 'published_at'])]
class ArticleVersion extends Model
{
    use BelongsToTenant;

    protected static function booted(): void
    {
        static::updating(fn () => throw new \RuntimeException('Published article versions are immutable.'));
        static::deleting(fn () => throw new \RuntimeException('Published article versions are never deleted.'));
    }

    public static function hash(string $title, string $body): string
    {
        return hash('sha256', $title."\n".$body);
    }

    protected function casts(): array
    {
        return ['version' => 'integer', 'effective_from' => 'date', 'published_at' => 'datetime'];
    }

    public function article(): BelongsTo
    {
        return $this->belongsTo(Article::class);
    }

    public function publisher(): BelongsTo
    {
        return $this->belongsTo(User::class, 'published_by');
    }
}
