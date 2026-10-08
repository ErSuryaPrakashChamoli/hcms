<?php

namespace App\Filament\Support;

use App\Support\Tenancy\Exceptions\MissingTenantException;
use App\Support\Tenancy\TenantContext;
use Closure;
use Illuminate\Validation\Rules\Unique;

/**
 * SaaS.8: Filament's unique() is a raw database rule (Rule::unique) that ignores the tenant scope. On a tenant-owned
 * table whose constraint is per tenant (e.g. employees (tenant_id, employee_code)) it refused a value another tenant
 * held, which broke saves and let one tenant probe another's codes (and so its headcount). Every unique() on a
 * tenant-owned table narrows the rule to the bound tenant with this modifier; without a tenant it fails closed.
 *
 * Usage: ->unique(ignoreRecord: true, modifyRuleUsing: TenantUnique::rule())
 */
final class TenantUnique
{
    public static function rule(): Closure
    {
        return static function (Unique $rule): Unique {
            $tenantId = app(TenantContext::class)->id() ?? throw new MissingTenantException('A uniqueness check on tenant data needs a tenant in context.');

            return $rule->where('tenant_id', $tenantId);
        };
    }
}
