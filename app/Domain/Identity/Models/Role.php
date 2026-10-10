<?php

namespace App\Domain\Identity\Models;

use App\Domain\Audit\Concerns\Auditable;
use App\Support\Tenancy\BelongsToTenant;
use Database\Factories\RoleFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

#[UseFactory(RoleFactory::class)]
#[Fillable(['tenant_id', 'name', 'slug', 'description', 'is_system'])]
class Role extends Model
{
    /** @use HasFactory<RoleFactory> */
    use Auditable, BelongsToTenant, HasFactory;

    protected function casts(): array
    {
        return [
            'is_system' => 'boolean',
            'template_keys' => 'array',
        ];
    }

    public function auditModule(): string
    {
        return 'identity';
    }

    /** SaaS.12: the template bookkeeping is not an access change; changes of the permissions themselves are audited. */
    public function auditExcludedAttributes(): array
    {
        return [...config('peopleos.audit.ignored_attributes', []), 'template_keys'];
    }

    public function permissions(): BelongsToMany
    {
        return $this->belongsToMany(Permission::class);
    }

    public function users(): BelongsToMany
    {
        return $this->belongsToMany(User::class)->withPivot('company_id')->withTimestamps();
    }
}
