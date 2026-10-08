<?php

namespace App\Domain\Experience\Services;

use App\Domain\Identity\Models\User;
use Filament\Pages\Page;
use Filament\Resources\Pages\CreateRecord;
use Filament\Resources\Pages\EditRecord;
use Filament\Resources\Pages\Page as ResourcePage;
use Filament\Resources\Pages\ViewRecord;
use Illuminate\Http\Request;
use Illuminate\Routing\Router;
use Throwable;

/**
 * UX.19: may the signed-in person open this PeopleOS screen? Decided by the screen's own checks, the ones it runs when
 * it is opened:
 * - a page's canAccess();
 * - for a resource page, the resource's access and, on a record page, the record check (view or edit) on the record
 *   the URL names.
 *
 * It only decides what is offered (reminders, AI suggestions). It never allows anything: the screen still authorises
 * itself. A path that matches no screen is a dead end. Links to controllers outside the panel are left to those
 * controllers.
 */
final class ScreenAccess
{
    public function __construct(private readonly Router $router) {}

    /** The same question for a given person (who may not be the one signed in, e.g. a question asked on their behalf). */
    public function allowsFor(User $user, string $url): bool
    {
        return self::as($user, fn () => $this->allows($url));
    }

    /**
     * Run a check as the given person: screens answer canAccess() for the signed-in user, so the guard holds them for
     * the duration and the previous user is restored afterwards. Nothing is written to the session.
     *
     * @template T
     *
     * @param  callable(): T  $check
     * @return T
     */
    public static function as(User $user, callable $check): mixed
    {
        $guard = auth()->guard();
        $previous = $guard->user();
        if ($previous !== null && (int) $previous->getAuthIdentifier() === (int) $user->id) {
            return $check();
        }
        $guard->setUser($user);
        try {
            return $check();
        } finally {
            $previous !== null ? $guard->setUser($previous) : $guard->forgetUser();
        }
    }

    public function allows(string $url): bool
    {
        $path = parse_url($url, PHP_URL_PATH);
        if (! is_string($path) || $path === '') {
            return false;
        }
        try {
            $route = $this->router->getRoutes()->match(Request::create($path, 'GET'));
        } catch (Throwable) {
            return false;
        }
        $class = $route->getActionName();
        if (! class_exists($class)) {
            return true;
        }

        return (bool) rescue(fn () => $this->screenAllows($class, $route->parameters()), false, false);
    }

    /** @param array<string, mixed> $parameters */
    private function screenAllows(string $class, array $parameters): bool
    {
        if (is_subclass_of($class, ResourcePage::class)) {
            $resource = $class::getResource();
            // A page may open to people without the resource's list access (the Employee 360 for its own subject).
            $needsResource = ! method_exists($class, 'needsResourceAccess') || $class::needsResourceAccess();
            if ($needsResource && ! $resource::canAccess()) {
                return false;
            }
            $record = isset($parameters['record']) ? $resource::resolveRecordRouteBinding($parameters['record']) : null;

            return match (true) {
                is_subclass_of($class, ViewRecord::class) => $record !== null && $resource::canView($record),
                is_subclass_of($class, EditRecord::class) => $record !== null && $resource::canEdit($record),
                is_subclass_of($class, CreateRecord::class) => $resource::canCreate(),
                default => true,
            };
        }

        return ! is_subclass_of($class, Page::class) || $class::canAccess();
    }
}
