<?php

namespace App\Domain\Performance\Models;

use App\Domain\Audit\Concerns\Auditable;
use App\Support\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use RuntimeException;

/**
 * HROne setup parity HP-8a: a performance master value of one kind (config peopleos.performance.lookup_kinds):
 * perspectives (on KRAs and goals), initiative types (for initiatives) and PIP reasons (on improvement plans). The
 * standard rows are system rows: they can be renamed or deactivated, never deleted and never re-coded.
 */
#[Fillable(['tenant_id', 'kind', 'code', 'name', 'description', 'is_system', 'status', 'sort_order'])]
class PerformanceLookup extends Model
{
    use Auditable, BelongsToTenant;

    protected $attributes = ['status' => 'active', 'is_system' => false, 'sort_order' => 0];

    protected static function booted(): void
    {
        static::saving(function (self $lookup): void {
            if (! array_key_exists((string) $lookup->kind, (array) config('peopleos.performance.lookup_kinds', []))) {
                throw new RuntimeException("Unknown performance master kind [{$lookup->kind}].");
            }
            $lookup->code = strtoupper(trim((string) $lookup->code));
            if ($lookup->exists && $lookup->getRawOriginal('is_system') && ($lookup->isDirty('code') || $lookup->isDirty('kind'))) {
                throw new RuntimeException('A standard entry keeps its code; rename it or make it inactive instead.');
            }
        });
        static::deleting(function (self $lookup): void {
            if ($lookup->is_system) {
                throw new RuntimeException('A standard entry cannot be deleted; make it inactive instead.');
            }
        });
    }

    protected function casts(): array
    {
        return ['is_system' => 'boolean', 'sort_order' => 'integer'];
    }

    public function auditModule(): string
    {
        return 'performance';
    }

    public function auditLabel(): string
    {
        return config("peopleos.performance.lookup_kinds.{$this->kind}", $this->kind).": {$this->name}";
    }

    public function scopeOfKind(Builder $query, string $kind): Builder
    {
        return $query->where('kind', $kind);
    }

    /** @return array<int, string> id => name of the active entries of a kind (plus $keep, so a chosen one stays visible) */
    public static function options(string $kind, int|array|null $keep = null): array
    {
        return static::query()->ofKind($kind)
            ->where(fn (Builder $q) => $q->where('status', 'active')->when($keep, fn (Builder $k) => $k->orWhereIn('id', (array) $keep)))
            ->orderBy('sort_order')->orderBy('name')->pluck('name', 'id')->all();
    }

    /** @return array<string, string> code => name of the active entries of a kind */
    public static function codeOptions(string $kind): array
    {
        return static::query()->ofKind($kind)->where('status', 'active')->orderBy('sort_order')->orderBy('name')->pluck('name', 'code')->all();
    }
}
