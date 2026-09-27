<?php

namespace App\Domain\Organisation\Models;

use App\Domain\Audit\Concerns\Auditable;
use App\Domain\Identity\Concerns\ScopedByOrganisation;
use App\Domain\Organisation\Enums\ActiveStatus;
use App\Support\EffectiveDating\HasEffectiveDates;
use App\Support\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * The registered employer (ADR-0001): signs contracts, holds PAN/TAN, issues TDS certificates.
 * Belongs to one company; a company may have many. Scoped through the company dimension.
 */
#[Fillable(['tenant_id', 'company_id', 'code', 'legal_name', 'trade_name', 'legal_form', 'country', 'incorporation_identifier', 'is_primary', 'status', 'effective_from', 'effective_to', 'metadata'])]
class LegalEntity extends Model
{
    use Auditable, BelongsToTenant, HasEffectiveDates, ScopedByOrganisation;

    public string $accessScopeDimension = 'legal_entity';

    protected function casts(): array
    {
        return [
            'status' => ActiveStatus::class,
            'is_primary' => 'boolean',
            'effective_from' => 'date',
            'effective_to' => 'date',
            'metadata' => 'array',
        ];
    }

    public function auditModule(): string
    {
        return 'organisation';
    }

    public function auditLabel(): string
    {
        return "{$this->legal_name} ({$this->code})";
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function establishments(): HasMany
    {
        return $this->hasMany(Establishment::class);
    }
}
