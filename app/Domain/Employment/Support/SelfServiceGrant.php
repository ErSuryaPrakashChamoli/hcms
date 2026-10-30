<?php

namespace App\Domain\Employment\Support;

use App\Domain\Employment\Models\Employee;
use App\Domain\Identity\Models\User;
use App\Support\Tenancy\TenantContext;
use Closure;

/**
 * HROne parity MS-3: the authority of a self-service profile change that applies WITHOUT approval (the organisation lets
 * that section change at once, Setup → Core HR → Profile changes). The employee does not hold the People / Employment
 * rights (employee.create / employee.update), so ProfileChangeGuard accepts, in their place, this grant — and only it:
 *
 * - it exists only inside ProfileChanges' own application of one request (run() sets it, always clears it), never from
 *   the outside, the browser or a stored value;
 * - it names the tenant, the request reference, the employee, the person applying (the employee's own user) and the
 *   rights the request's operations need; anything else is refused by the guard as before.
 *
 * Approved changes never use it: they apply in the approver's name through the normal authorisation.
 */
final class SelfServiceGrant
{
    /** @var array{tenant: int, reference: string, employee: int, actor: int, permissions: list<string>}|null */
    private static ?array $current = null;

    /**
     * @param  list<string>  $permissions
     *
     * @template T
     *
     * @param  Closure(): T  $callback
     * @return T
     */
    public static function run(string $reference, Employee $employee, User $actor, array $permissions, Closure $callback): mixed
    {
        $previous = self::$current;
        self::$current = ['tenant' => (int) app(TenantContext::class)->id(), 'reference' => $reference, 'employee' => (int) $employee->getKey(), 'actor' => (int) $actor->getKey(), 'permissions' => array_values($permissions)];

        try {
            return $callback();
        } finally {
            self::$current = $previous;
        }
    }

    /** Whether the running grant covers this actor, employee, right and origin. */
    public static function allows(User $actor, Employee $employee, string $permission, ?ChangeOrigin $origin): bool
    {
        $grant = self::$current;
        if ($grant === null || $origin === null || ! $origin->isSelfService() || $origin->reference !== $grant['reference']) {
            return false;
        }

        return $grant['tenant'] === (int) app(TenantContext::class)->id()
            && $grant['employee'] === (int) $employee->getKey()
            && $grant['actor'] === (int) $actor->getKey()
            && (int) $employee->getAttribute('user_id') === (int) $actor->getKey()
            && (int) $employee->getAttribute('tenant_id') === $grant['tenant']
            && in_array($permission, $grant['permissions'], true);
    }
}
