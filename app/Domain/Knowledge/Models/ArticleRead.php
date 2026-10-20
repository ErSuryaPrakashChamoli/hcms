<?php

namespace App\Domain\Knowledge\Models;

use App\Domain\Employment\Models\Employee;
use App\Support\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Read / acknowledgement tracking per employee per version (Phase 12: the acknowledged version's hash,
 * source and IP). An acknowledgement is recorded once and never withdrawn or deleted.
 */
#[Fillable(['tenant_id', 'article_id', 'article_version_id', 'employee_id', 'version', 'version_hash', 'read_at', 'acknowledged_at', 'source', 'ip_address'])]
class ArticleRead extends Model
{
    use BelongsToTenant;

    protected static function booted(): void
    {
        static::updating(function (self $read): void {
            if ($read->getRawOriginal('acknowledged_at') !== null && array_intersect(array_keys($read->getDirty()), ['acknowledged_at', 'version', 'version_hash', 'article_version_id', 'employee_id', 'article_id']) !== []) {
                throw new \RuntimeException('An acknowledgement is recorded once and never changed.');
            }
        });
        static::deleting(fn () => throw new \RuntimeException('Acknowledgement history is never deleted.'));
    }

    protected function casts(): array
    {
        return ['version' => 'integer', 'read_at' => 'datetime', 'acknowledged_at' => 'datetime'];
    }

    public function article(): BelongsTo
    {
        return $this->belongsTo(Article::class);
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }
}
