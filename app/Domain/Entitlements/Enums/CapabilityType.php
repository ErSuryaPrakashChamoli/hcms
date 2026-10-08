<?php

namespace App\Domain\Entitlements\Enums;

/**
 * SaaS.3: every catalogue entry is a capability, of one of three kinds.
 * - Module: a whole area of PeopleOS (payroll, attendance…), on or off.
 * - Feature: a narrower on/off ability inside a module; never available unless its module is.
 * - Limit: a numeric ceiling (null = unlimited), compared with a measured usage.
 */
enum CapabilityType: string
{
    case Module = 'module';
    case Feature = 'feature';
    case Limit = 'limit';
}
