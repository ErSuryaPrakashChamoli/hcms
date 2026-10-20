<?php

use App\Domain\Entitlements\Models\PlanVersion;
use App\Domain\Entitlements\Services\PlanCatalog;
use App\Domain\Identity\Models\User;

/*
| SaaS.4 test helpers. Fictional plans only: no plan, package or limit here is a commercial decision.
*/

/**
 * A new plan whose v1 holds $values and is published (on sale) from $from.
 *
 * @param  array<string, bool|int|null>  $values
 */
function publishedPlan(User $operator, string $code, array $values, string $from): PlanVersion
{
    $catalog = app(PlanCatalog::class);
    $plan = $catalog->create($code, ucfirst($code).' (test)', null, 'Test catalogue', $operator);
    $draft = $plan->versions->first();
    $catalog->define($draft, $values, 'Test content', $operator);

    return $catalog->publish($draft, $from, 'Test publication', $operator)->load('plan');
}
