<?php

namespace App\Domain\Learning\Models;

use App\Domain\Audit\Concerns\Auditable;
use App\Support\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** Phase 8: who delivers learning — an internal L&D team or an external provider. */
#[Fillable(['tenant_id', 'code', 'name', 'provider_type', 'description', 'website', 'contact_name', 'contact_email', 'contact_phone', 'address', 'status'])]
class LearningProvider extends Model
{
    use Auditable, BelongsToTenant;

    protected $attributes = ['status' => 'active', 'provider_type' => 'external'];

    protected static function booted(): void
    {
        static::saving(fn (self $p) => $p->code = strtoupper(trim((string) $p->code)));
    }

    public function auditModule(): string
    {
        return 'learning';
    }

    public function auditLabel(): string
    {
        return "{$this->name} ({$this->code})";
    }

    public function instructors(): HasMany
    {
        return $this->hasMany(LearningInstructor::class);
    }
}
