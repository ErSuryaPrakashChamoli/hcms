<?php

namespace App\Domain\Entitlements\Enums;

/** SaaS.3: which layer produced the decision (the precedence order, highest last). SaaS.4 adds the plan layer. */
enum DecisionSource: string
{
    case None = 'none';             // nothing configured, or no evaluation possible
    case Catalog = 'catalog';       // the capability itself (not commercial)
    case Plan = 'plan';             // SaaS.4: the published plan version the tenant is assigned to (plan_entitlements)
    case Configuration = 'configuration'; // the tenant's own commercial terms (tenant_entitlements)
    case Override = 'override';     // an explicit platform override (entitlement_overrides)
}
