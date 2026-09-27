<?php

namespace App\Domain\Performance\Models;

use App\Domain\Audit\Concerns\Auditable;
use App\Domain\Organisation\Enums\ActiveStatus;
use App\Domain\Organisation\Models\JobFamily;
use App\Support\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** A career ladder (§36): ordered designations with the skills each step needs. */
#[Fillable(['tenant_id', 'name', 'code', 'job_family_id', 'description', 'status'])]
class CareerPath extends Model
{
    use Auditable, BelongsToTenant;

    protected $attributes = ['status' => 'active'];

    protected static function booted(): void
    {
        static::saving(fn (self $p) => $p->code = strtoupper(trim((string) $p->code)));
    }

    protected function casts(): array
    {
        return ['status' => ActiveStatus::class];
    }

    public function auditModule(): string
    {
        return 'performance';
    }

    public function auditLabel(): string
    {
        return "{$this->name} ({$this->code})";
    }

    public function jobFamily(): BelongsTo
    {
        return $this->belongsTo(JobFamily::class);
    }

    public function steps(): HasMany
    {
        return $this->hasMany(CareerPathStep::class)->orderBy('sort_order');
    }
}
