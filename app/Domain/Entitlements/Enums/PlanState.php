<?php

namespace App\Domain\Entitlements\Enums;

/**
 * SaaS.4: where a plan version (or a plan) stands on a given day, in the canonical configuration vocabulary
 * (ADR-0009: Draft → Scheduled → Active → Superseded → Archived). Derived from the stored version status
 * (draft / published / retired) and the sale window, never stored, so it cannot drift.
 *
 * - draft: being written; can never affect a tenant.
 * - scheduled: published; tenants can be assigned from the start of its sale window, which is later.
 * - active: published and on sale today: new assignments are possible.
 * - superseded: published, its sale window has ended (a later version replaced it); tenants assigned to it keep it.
 * - retired: withdrawn by an operator; no new assignment; tenants assigned to it keep it (nothing is retroactive).
 */
enum PlanState: string
{
    case Draft = 'draft';
    case Scheduled = 'scheduled';
    case Active = 'active';
    case Superseded = 'superseded';
    case Retired = 'retired';

    public function color(): string
    {
        return match ($this) {
            self::Draft => 'gray',
            self::Scheduled => 'info',
            self::Active => 'success',
            self::Superseded => 'warning',
            self::Retired => 'danger',
        };
    }
}
