<?php

namespace App\Domain\Identity\Services;

use App\Domain\Employment\Models\Employee;
use App\Domain\Identity\Models\User;
use App\Support\Tenancy\TenantContext;

/**
 * "Is this the viewer's own record?" for employee-keyed models (policies use it so nobody approves their own
 * request). Outside an authorisation pass it runs the original query for each check; inside one it reads the
 * viewer's own employee ids once, with the same query and the same scopes, and checks membership.
 */
final class OwnRecords
{
    public function __construct(private readonly AuthorizationContext $context, private readonly TenantContext $tenants) {}

    public function isOwn(User $user, mixed $employeeId): bool
    {
        if (! $this->context->active()) {
            return Employee::query()->where('user_id', $user->id)->where('id', $employeeId)->exists();
        }
        $own = $this->context->remember('own:'.$this->tenants->id().':'.$user->getKey(),
            fn () => Employee::query()->where('user_id', $user->id)->pluck('id')->map(fn ($id) => (int) $id)->all());

        return $employeeId !== null && in_array((int) $employeeId, $own, true);
    }
}
