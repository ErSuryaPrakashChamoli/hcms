<?php

namespace App\Domain\Learning\Models;

use App\Domain\Audit\Concerns\Auditable;
use App\Domain\Organisation\Enums\ActiveStatus;
use App\Support\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** An ordered set of courses (§37). */
#[Fillable(['tenant_id', 'name', 'code', 'description', 'status'])]
class LearningPath extends Model
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
        return 'learning';
    }

    public function auditLabel(): string
    {
        return "{$this->name} ({$this->code})";
    }

    public function items(): HasMany
    {
        return $this->hasMany(LearningPathCourse::class)->orderBy('sort_order');
    }
}
