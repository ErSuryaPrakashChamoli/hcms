<?php

namespace App\Domain\Organisation\Models;

use App\Domain\Audit\Concerns\Auditable;
use App\Domain\Organisation\Enums\ActiveStatus;
use App\Support\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use RuntimeException;

/**
 * Data import DI-1 (HROne "Dimension"): a value of the organisation's own extra dimension (a vertical, a practice …),
 * optionally under another value. A value never sits under itself or one of its own sub-values.
 */
#[Fillable(['tenant_id', 'code', 'name', 'parent_id', 'description', 'status'])]
class DimensionValue extends Model
{
    use Auditable, BelongsToTenant;

    protected static function booted(): void
    {
        static::saving(function (DimensionValue $value): void {
            $parent = $value->getAttributes()['parent_id'] ?? null;
            if ($value->isDirty('parent_id') && $parent !== null && self::wouldLoop($value, (int) $parent)) {
                throw new RuntimeException('A dimension value cannot sit under itself or one of its own values.');
            }
        });
    }

    /** Whether putting the value under this parent would make it sit under itself (directly or through its values). */
    public static function wouldLoop(?DimensionValue $value, ?int $parentId): bool
    {
        if ($value === null || ! $value->exists || $parentId === null) {
            return false;
        }
        $seen = [];
        for ($current = $parentId; $current !== 0 && ! isset($seen[$current]); $current = (int) static::query()->whereKey($current)->value('parent_id')) {
            if ($current === (int) $value->getKey()) {
                return true;
            }
            $seen[$current] = true;
        }

        return false;
    }

    protected function casts(): array
    {
        return ['status' => ActiveStatus::class];
    }

    public function auditLabel(): string
    {
        return "{$this->name} ({$this->code})";
    }

    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_id');
    }

    public function children(): HasMany
    {
        return $this->hasMany(self::class, 'parent_id');
    }
}
