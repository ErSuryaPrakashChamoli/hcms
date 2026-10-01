<?php

namespace App\Domain\Identity\Scopes;

use App\Domain\Employment\Models\Employee;
use App\Domain\Identity\Models\User;
use App\Domain\Identity\Services\AccessScopes;
use App\Support\Tenancy\TenantContext;
use Closure;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Scope;

/**
 * Global scope that applies the authenticated user's organisational access scope at the query
 * layer, so UI, search, exports, reports and assistants cannot reach records outside it.
 * System contexts (console, queue workers, API keys) have no authenticated User and are not
 * restricted; tenant isolation still applies to them through TenantScope.
 */
final class AccessScope implements Scope
{
    private static bool $disabled = false;

    public function apply(Builder $builder, Model $model): void
    {
        if (self::$disabled || app(TenantContext::class)->isBypassed()) {
            return;
        }

        $user = auth()->user();

        if (! $user instanceof User || $user->isPlatformAdmin()) {
            return;
        }

        $scopes = app(AccessScopes::class);

        if (! $scopes->isScoped($user)) {
            return;
        }

        if ($model instanceof Employee) {
            $scopes->constrainEmployees($builder, $user);

            return;
        }

        // Phase 10: records carrying several organisation dimensions (positions, workforce plans and
        // budgets) constrain themselves on each scoped dimension (fail-closed on missing values).
        if (method_exists($model, 'applyAccessScope')) {
            $model->applyAccessScope($builder, $user, $scopes);

            return;
        }

        if (property_exists($model, 'accessScopeDimension')) {
            $dimension = $model->accessScopeDimension;
            $scopes->constrainOrganisation($builder, $user, $dimension);

            return;
        }

        $scopes->constrainByEmployee($builder, $user);
    }

    /** Run a callback with access scoping suspended (used to build the scope's own sub-selects). */
    public static function withoutScoping(Closure $callback): mixed
    {
        $previous = self::$disabled;
        self::$disabled = true;

        try {
            return $callback();
        } finally {
            self::$disabled = $previous;
        }
    }
}
