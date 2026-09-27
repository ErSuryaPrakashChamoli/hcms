<?php

namespace App\Domain\Compliance\Models;

use App\Domain\Compliance\Concerns\FrozenWithReturn;
use App\Support\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** Part J: the professional tax content of one statutory return (establishment × state × month). */
#[Fillable(['tenant_id', 'statutory_return_id', 'establishment_id', 'statutory_registration_id', 'state_code', 'return_month', 'member_count', 'totals'])]
class ProfessionalTaxReturn extends Model
{
    use BelongsToTenant, FrozenWithReturn;

    protected function casts(): array
    {
        return ['return_month' => 'date', 'totals' => 'array'];
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
        return $this->hasMany(ProfessionalTaxReturnEntry::class, 'professional_tax_return_id');
    }
}
