<?php

namespace App\Domain\Knowledge\Models;

use App\Domain\Identity\Models\User;
use App\Support\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Immutable snapshot of an article at each publish. */
#[Fillable(['tenant_id', 'article_id', 'version', 'title', 'body', 'effective_from', 'published_by', 'published_at'])]
class ArticleVersion extends Model
{
    use BelongsToTenant;

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
