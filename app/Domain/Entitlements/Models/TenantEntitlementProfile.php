<?php

namespace App\Domain\Entitlements\Models;

use App\Support\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

/**
 * SaaS.3: a tenant's commercial state. Absent or `unconfigured` means "no commercial configuration": every
 * commercial capability evaluates to UNKNOWN and nothing is ever read as DENY. Changed only through
 * EntitlementConfiguration (audited explicitly on the tenant and platform chains).
 */
#[Fillable(['tenant_id', 'state', 'configured_from', 'version', 'has_plan_assignments', 'updated_by'])]
class TenantEntitlementProfile extends Model
{
    use BelongsToTenant;

    public const UNCONFIGURED = 'unconfigured';

    public const CONFIGURED = 'configured';

    protected function casts(): array
    {
        return ['configured_from' => 'date', 'version' => 'integer', 'has_plan_assignments' => 'boolean'];
    }
}
