<?php

namespace App\Domain\Compliance\Models;

use App\Domain\Compliance\Concerns\FrozenWithReturn;
use App\Support\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** Part I: the ESI content of one statutory return (establishment × contribution month). */
#[Fillable(['tenant_id', 'statutory_return_id', 'establishment_id', 'statutory_registration_id', 'contribution_month', 'contribution_period', 'member_count', 'totals'])]
class EsiReturnRun extends Model
{
    use BelongsToTenant, FrozenWithReturn;

    protected function casts(): array
    {
        return ['contribution_month' => 'date', 'totals' => 'array'];
    }

    public function statutoryReturn(): BelongsTo
    {
        return $this->belongsTo(StatutoryReturn::class);
    }

    public function registration(): BelongsTo
    {
        return $this->belongsTo(StatutoryRegistration::class, 'statutory_registration_id');
    }

    public function entries(): HasMany
    {
        return $this->hasMany(EsiReturnEntry::class);
    }
}
