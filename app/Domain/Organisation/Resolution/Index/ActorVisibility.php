<?php

namespace App\Domain\Organisation\Resolution\Index;

use App\Domain\Employment\Models\Employee;
use App\Domain\Identity\Models\User;
use App\Domain\Identity\Scopes\AccessScope;
use App\Domain\Identity\Services\AccessScopes;
use Illuminate\Database\Eloquent\Model;

/**
 * Which records of a model an actor may reach under their organisation access scope. The AccessScope global scope
 * follows the SIGNED-IN user, but an upload is re-checked in a queued job as its uploader with nobody signed in, so the
 * resolver applies the actor's scope explicitly, the same way AccessScope::apply() does for a signed-in user.
 */
final class ActorVisibility
{
    /**
     * @param  class-string<Model>  $class
     * @param  list<int>|null  $ids  limit the check to these records (null = all)
     * @return array<int, true>|null the reachable ids, or null when nothing limits the actor
     */
    public static function ids(string $class, ?User $actor, ?array $ids = null): ?array
    {
        if ($actor === null || $actor->isPlatformAdmin()) {
            return null;
        }
        $model = new $class; // boots the model, so its global scopes are registered before we ask
        if (! $class::hasGlobalScope(AccessScope::class)) {
            return null;
        }
        $scopes = app(AccessScopes::class);
        if (! $scopes->isScoped($actor, $scopes->moduleFor($model))) {
            return null;
        }

        $query = $model->newQueryWithoutScope(AccessScope::class)->select($model->qualifyColumn($model->getKeyName()));
        if ($ids !== null) {
            $query->whereKey($ids);
        }
        if ($model instanceof Employee) {
            $scopes->constrainEmployees($query, $actor);
        } elseif (method_exists($model, 'applyAccessScope')) {
            $model->applyAccessScope($query, $actor, $scopes);
        } elseif (property_exists($model, 'accessScopeDimension')) {
            $scopes->constrainOrganisation($query, $actor, $model->accessScopeDimension);
        } else {
            return null;
        }

        return array_fill_keys($query->toBase()->pluck($model->getKeyName())->map(fn ($id) => (int) $id)->all(), true);
    }
}
