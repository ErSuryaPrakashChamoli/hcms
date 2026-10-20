<?php

namespace App\Domain\Workforce\Models;

use App\Domain\Audit\Concerns\Auditable;
use App\Domain\Identity\Models\User;
use App\Domain\Organisation\Models\Company;
use App\Domain\Organisation\Models\OrganisationNode;
use App\Domain\Workforce\Concerns\ScopedByOrganisationDimensions;
use App\Support\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** Phase 10: a workforce plan (code, name, organisation scope, owner) whose content lives in versions. Never deleted. */
#[Fillable(['tenant_id', 'code', 'name', 'company_id', 'legal_entity_id', 'establishment_id', 'organisation_node_id', 'business_unit_id', 'division_id', 'department_id', 'team_id', 'location_id', 'owner_user_id', 'active_version_id', 'created_by'])]
class WorkforcePlan extends Model
{
    use Auditable, BelongsToTenant, ScopedByOrganisationDimensions;

    protected static function booted(): void
    {
        static::saving(fn (self $p) => $p->code = strtoupper(trim((string) $p->code)));
        static::updating(function (self $p) {
            foreach (['code', 'company_id'] as $immutable) {
                if ($p->isDirty($immutable)) {
                    throw new \RuntimeException('A workforce plan keeps its code and company.');
                }
            }
        });
        static::deleting(fn () => throw new \RuntimeException('Workforce plans are archived, never deleted.'));
    }

    public function auditModule(): string
    {
        return 'workforce';
    }

    public function auditLabel(): string
    {
        return "Workforce plan {$this->code}";
    }

    public function versions(): HasMany
    {
        return $this->hasMany(WorkforcePlanVersion::class)->orderBy('version');
    }

    public function activeVersion(): BelongsTo
    {
        return $this->belongsTo(WorkforcePlanVersion::class, 'active_version_id');
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function organisationNode(): BelongsTo
    {
        return $this->belongsTo(OrganisationNode::class);
    }

    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_user_id');
    }
}
