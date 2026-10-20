<?php

namespace App\Domain\Talent\Models;

use App\Domain\Audit\Concerns\Auditable;
use App\Support\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** Phase 9: a configurable talent pool (Emerging leaders, Technical experts…). Membership is explicit — never automatic. */
#[Fillable(['tenant_id', 'code', 'name', 'description', 'criteria', 'status', 'owner_user_id'])]
class TalentPool extends Model
{
    use Auditable, BelongsToTenant;

    protected $attributes = ['status' => 'active'];

    protected static function booted(): void
    {
        static::saving(fn (self $p) => $p->code = strtoupper(trim((string) $p->code)));
        static::deleting(fn () => throw new \RuntimeException('Talent pools are retired, never deleted.'));
    }

    public function auditModule(): string
    {
        return 'talent';
    }

    public function auditLabel(): string
    {
        return "{$this->name} ({$this->code})";
    }

    public function memberships(): HasMany
    {
        return $this->hasMany(TalentPoolMembership::class);
    }
}
