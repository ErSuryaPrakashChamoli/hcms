<?php

namespace App\Domain\Analytics\Models;

use App\Domain\Audit\Concerns\Auditable;
use App\Domain\Identity\Models\User;
use App\Support\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

/** A role-specific dashboard (§85): ordered widgets [{type, title, metric|report_id, size, options}]. */
#[Fillable(['tenant_id', 'name', 'slug', 'description', 'role_ids', 'widgets', 'is_default', 'sort_order', 'status', 'owner_id'])]
class Dashboard extends Model
{
    use Auditable, BelongsToTenant;

    protected $attributes = ['status' => 'active'];

    protected static function booted(): void
    {
        static::saving(fn (self $d) => $d->slug = Str::slug($d->slug ?: $d->name));
    }

    protected function casts(): array
    {
        return ['role_ids' => 'array', 'widgets' => 'array', 'is_default' => 'boolean', 'sort_order' => 'integer'];
    }

    public function auditModule(): string
    {
        return 'analytics';
    }

    public function auditLabel(): string
    {
        return $this->name;
    }

    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_id');
    }

    public function isForUser(User $user): bool
    {
        if ($user->hasPermission('analytics.manage') || $this->owner_id === $user->id || empty($this->role_ids)) {
            return true;
        }

        return $user->roles()->whereIn('roles.id', $this->role_ids)->exists();
    }
}
