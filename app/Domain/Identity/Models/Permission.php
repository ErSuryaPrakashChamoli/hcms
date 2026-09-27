<?php

namespace App\Domain\Identity\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

/**
 * Platform-owned permission catalogue row. Never created by tenants.
 */
#[Fillable(['key', 'module', 'description'])]
class Permission extends Model
{
    public function roles(): BelongsToMany
    {
        return $this->belongsToMany(Role::class);
    }
}
