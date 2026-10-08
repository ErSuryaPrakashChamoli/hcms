<?php

namespace App\Domain\Identity\Services;

use App\Domain\Employment\Models\Employee;
use App\Domain\Identity\Models\User;
use App\Domain\Identity\Scopes\AccessScope;
use App\Support\Tenancy\TenantContext;

/**
 * UX.18: the signed-in person's own employee record, looked up once per request. Several screens and the
 * navigation each asked again (`Employee where user_id = me`, up to a dozen times per page). This runs that very
 * query, with the same global scopes, and keeps the answer for the rest of the request, keyed by tenant, user and
 * whether access scoping or tenancy is suspended at the moment, so it always equals what the query would return
 * here. Request-scoped (forgotten after each request) and cleared whenever an employee record is saved.
 */
final class CurrentEmployee
{
    /** @var array<string, ?Employee> */
    private array $records = [];

    public function of(?User $user): ?Employee
    {
        if ($user === null) {
            return null;
        }
        $tenants = app(TenantContext::class);
        $key = implode(':', [$tenants->id() ?? 0, $user->getKey(), AccessScope::suspended() ? 1 : 0, $tenants->isBypassed() ? 1 : 0]);
        if (! array_key_exists($key, $this->records)) {
            $this->records[$key] = Employee::query()->where('user_id', $user->getKey())->first();
        }

        return $this->records[$key];
    }

    public function forget(): void
    {
        $this->records = [];
    }
}
