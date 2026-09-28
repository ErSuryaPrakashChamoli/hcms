<?php

namespace App\Domain\Performance\Models;

use App\Domain\Audit\Concerns\Auditable;
use App\Support\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

/** Phase 7: a reusable review template; its content lives in immutable, published versions. */
#[Fillable(['tenant_id', 'code', 'name', 'description', 'status'])]
class PerformanceTemplate extends Model
{
    use Auditable, BelongsToTenant;

    protected static function booted(): void
    {
        static::saving(fn (self $t) => $t->code = strtoupper(trim((string) $t->code)));
    }

    public function auditModule(): string
    {
        return 'performance';
    }

    public function versions(): HasMany
    {
        return $this->hasMany(PerformanceTemplateVersion::class)->orderBy('version');
    }

    public function latestVersion(): HasOne
    {
        return $this->hasOne(PerformanceTemplateVersion::class)->ofMany('version', 'max');
    }
}
