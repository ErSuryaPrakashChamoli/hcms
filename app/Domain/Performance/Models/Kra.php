<?php

namespace App\Domain\Performance\Models;

use App\Domain\Audit\Concerns\Auditable;
use App\Domain\Organisation\Enums\ActiveStatus;
use App\Support\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** KRA library entry (§35) with its suggested KPIs. */
#[Fillable(['tenant_id', 'name', 'code', 'category', 'description', 'default_weight', 'kpis', 'status', 'perspective_id'])]
class Kra extends Model
{
    use Auditable, BelongsToTenant;

    protected $attributes = ['status' => 'active'];

    protected static function booted(): void
    {
        static::saving(fn (self $k) => $k->code = strtoupper(trim((string) $k->code)));
    }

    protected function casts(): array
    {
        return ['default_weight' => 'integer', 'kpis' => 'array', 'status' => ActiveStatus::class];
    }

    /** HP-8a: the KRA's perspective (performance master). */
    public function perspective(): BelongsTo
    {
        return $this->belongsTo(PerformanceLookup::class, 'perspective_id');
    }

    public function auditModule(): string
    {
        return 'performance';
    }

    public function auditLabel(): string
    {
        return "{$this->name} ({$this->code})";
    }
}
