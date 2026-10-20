<?php

namespace App\Domain\Experience\Models;

use App\Domain\Identity\Models\User;
use App\Support\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * UX: one user's presentation preferences (density, home layout, pinned people, recents, favourite
 * reports, notification snoozes). Presentation state only: it never holds HR data, so it is not
 * audited (documented exemption in the architecture test).
 */
#[Fillable(['tenant_id', 'user_id', 'preferences'])]
class ExperiencePreference extends Model
{
    use BelongsToTenant;

    protected function casts(): array
    {
        return ['preferences' => 'array'];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
