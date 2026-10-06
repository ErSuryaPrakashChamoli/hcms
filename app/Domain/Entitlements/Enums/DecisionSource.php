<?php

namespace App\Domain\Entitlements\Enums;

/** SaaS.3: which layer produced the decision (the precedence order, highest last). */
enum DecisionSource: string
{
    case None = 'none';             // nothing configured, or no evaluation possible
    case Catalog = 'catalog';       // the capability itself (not commercial)
    case Configuration = 'configuration'; // the tenant's commercial configuration (tenant_entitlements)
    case Override = 'override';     // an explicit platform override (entitlement_overrides)
}
