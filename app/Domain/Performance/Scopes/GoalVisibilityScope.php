<?php

namespace App\Domain\Performance\Scopes;

use App\Domain\Identity\Models\User;
use App\Domain\Identity\Scopes\AccessScope;
use App\Domain\Performance\Support\GoalVisibility;
use App\Support\Tenancy\TenantContext;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Scope;

/**
 * HROne parity GO-4 (owner decision PER-5 / D-GO5): private goals reach only the people GoalVisibility names, on every Goal
 * query — the Goals list and its search, the alignment options and linked goals, datasets (goals, check-ins through their
 * goal), the APIs. An API-key request gets public goals only; a signed-in person gets GoalVisibility::constrain;
 * system contexts (console, queue workers, platform bypass, AccessScope::withoutScoping) are not limited, so roll-ups,
 * reminders and cycle runs see every goal. Services that must work on a goal regardless of the reader (progress roll-up,
 * alignment checks) lift this scope by name.
 */
final class GoalVisibilityScope implements Scope
{
    public function apply(Builder $builder, Model $model): void
    {
        if (AccessScope::suspended() || app(TenantContext::class)->isBypassed()) {
            return;
        }
        // An API-key request reads public goals only, also where it runs a report as its owner.
        if (app()->bound('request') && request()->attributes->has('api_key')) {
            GoalVisibility::publicOnly($builder);

            return;
        }
        $user = auth()->user();
        if ($user instanceof User) {
            GoalVisibility::constrain($builder, $user);
        }
    }
}
