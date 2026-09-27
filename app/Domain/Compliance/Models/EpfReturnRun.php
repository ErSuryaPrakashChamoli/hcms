<?php

namespace App\Domain\Compliance\Models;

use App\Domain\Compliance\Concerns\FrozenWithReturn;
use App\Domain\Organisation\Models\Establishment;
use App\Support\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** Part H: the EPF / ECR content of one statutory return (establishment × wage month × kind). */
#[Fillable(['tenant_id', 'statutory_return_id', 'establishment_id', 'statutory_registration_id', 'payroll_period_id', 'wage_month', 'return_kind', 'member_count', 'totals'])]
class EpfReturnRun extends Model
{
    use BelongsToTenant, FrozenWithReturn;

    public const KINDS = ['regular', 'supplementary', 'revised'];

    protected function casts(): array
    {
        return ['wage_month' => 'date', 'totals' => 'array'];
    }

    public function statutoryReturn(): BelongsTo
    {
        return $this->belongsTo(StatutoryReturn::class);
    }

    public function establishment(): BelongsTo
    {
        return $this->belongsTo(Establishment::class);
    }

    public function registration(): BelongsTo
    {
        return $this->belongsTo(StatutoryRegistration::class, 'statutory_registration_id');
    }

    public function entries(): HasMany
    {
        return $this->hasMany(EpfReturnEntry::class);
    }
}
