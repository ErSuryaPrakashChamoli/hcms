<?php

namespace App\Domain\Entitlements\Services;

use App\Domain\Employment\Models\Employee;
use App\Domain\Identity\Scopes\AccessScope;
use App\Domain\Lifecycle\Enums\LifecycleState;

/**
 * SaaS.3: the single definition of commercial units (SaaS.1 Entitlement Architecture §8.1). An active employee is
 * an employee in an employed lifecycle state (LifecycleState::isEmployed), counted tenant-wide for the bound tenant:
 * the organisation scope of whoever is signed in never shrinks a commercial count. Analytics keeps its own
 * headcount definitions; they answer a different question.
 */
final class BillableUnits
{
    public function activeEmployees(): int
    {
        $employed = array_values(array_map(fn (LifecycleState $s) => $s->value, array_filter(LifecycleState::cases(), fn (LifecycleState $s) => $s->isEmployed())));

        return Employee::query()->withoutGlobalScope(AccessScope::class)->whereIn('lifecycle_state', $employed)->count();
    }
}
