<?php

namespace App\Domain\Notifications\Services;

use App\Domain\Identity\Models\Role;
use App\Domain\Identity\Models\User;
use Illuminate\Support\Arr;
use Illuminate\Support\Collection;

/**
 * Audience spec: [{type: subject|manager|initiator|role|user|assignee, role_id?, user_id?}] resolved
 * against a notification context into concrete users.
 */
final class AudienceResolver
{
    /**
     * @param  array<int, array<string, mixed>>  $specs
     * @param  array<string, mixed>  $context
     * @return Collection<int, User>
     */
    public function resolve(array $specs, array $context): Collection
    {
        $ids = collect();

        foreach ($specs as $spec) {
            $ids = $ids->merge(match ($spec['type'] ?? null) {
                'subject' => [Arr::get($context, 'employee.user_id')],
                'manager' => [Arr::get($context, 'employee.manager_user_id')],
                'initiator' => [Arr::get($context, 'initiator.id')],
                'assignee' => (array) Arr::get($context, 'task.assignee_user_ids', []),
                'user' => [$spec['user_id'] ?? null],
                'role' => $this->roleUserIds($spec),
                default => [],
            });
        }

        $ids = $ids->filter()->unique()->values();

        return $ids->isEmpty()
            ? Collection::make()
            : User::query()->forCurrentTenant()->whereIn('id', $ids)->where('status', 'active')->get();
    }

    /** @return array<int, int> */
    private function roleUserIds(array $spec): array
    {
        $role = isset($spec['role_id'])
            ? Role::query()->find($spec['role_id'])
            : (isset($spec['role']) ? Role::query()->where('slug', $spec['role'])->first() : null);

        return $role?->users()->pluck('users.id')->all() ?? [];
    }
}
