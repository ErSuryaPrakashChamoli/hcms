<?php

namespace App\Domain\Compliance\Models;

use App\Domain\Audit\Concerns\Auditable;
use App\Domain\Organisation\Models\Company;
use App\Support\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Which statutes apply to a legal entity and its registrations (§7, §32). */
#[Fillable(['tenant_id', 'company_id', 'jurisdiction', 'pt_state', 'lwf_state', 'pf_applicable', 'esi_applicable', 'pt_applicable', 'lwf_applicable', 'tds_applicable', 'pf_restrict_to_ceiling', 'pf_establishment_code', 'esi_code', 'pt_registration', 'tan', 'pan'])]
class CompanyStatutoryProfile extends Model
{
    use Auditable, BelongsToTenant;

    protected function casts(): array
    {
        return [
            'pf_applicable' => 'boolean',
            'esi_applicable' => 'boolean',
            'pt_applicable' => 'boolean',
            'lwf_applicable' => 'boolean',
            'tds_applicable' => 'boolean',
            'pf_restrict_to_ceiling' => 'boolean',
        ];
    }

    public function auditModule(): string
    {
        return 'compliance';
    }

    public function auditLabel(): string
    {
        return 'Statutory profile';
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public static function defaults(): array
    {
        return ['jurisdiction' => 'IN', 'pf_applicable' => true, 'esi_applicable' => true, 'pt_applicable' => true, 'lwf_applicable' => false, 'tds_applicable' => true, 'pf_restrict_to_ceiling' => true];
    }
}
