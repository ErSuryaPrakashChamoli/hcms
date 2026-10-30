<?php

namespace App\Domain\Organisation\Models;

use App\Domain\Audit\Concerns\Auditable;
use App\Support\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * HROne setup parity CA-2 (Enterprise → Contact details): the people an organisation names for HR, decisions and
 * technical administration, for all companies (company_id null) or one company. Platform operators see them on
 * Platform operations, so security and release notices reach the technical administrator.
 */
#[Fillable(['tenant_id', 'company_id', 'type', 'name', 'email', 'mobile', 'designation', 'status'])]
class OrganisationContact extends Model
{
    use Auditable, BelongsToTenant;

    protected $attributes = ['status' => 'active'];

    public function auditModule(): string
    {
        return 'organisation';
    }

    public function auditLabel(): string
    {
        return config("peopleos.organisation.contact_types.{$this->type}", $this->type).' '.$this->name;
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }
}
