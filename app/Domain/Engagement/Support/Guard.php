<?php

namespace App\Domain\Engagement\Support;

use App\Domain\Engagement\Exceptions\EngagementRuleViolation;
use App\Domain\Identity\Enums\UserStatus;
use App\Domain\Identity\Models\User;

/** Phase 13: permission checks and approver lookup shared by engagement and communication services. */
final class Guard
{
    public static function authorise(?User $actor, string ...$permissions): User
    {
        if ($actor === null) {
            throw new EngagementRuleViolation('This needs a signed-in person.');
        }
        foreach ($permissions as $permission) {
            if ($actor->hasPermission($permission)) {
                return $actor;
            }
        }
        throw new EngagementRuleViolation('This needs '.implode(' or ', $permissions).'.');
    }

    /** Separation of duties: whoever prepared it never decides it. */
    public static function notPreparer(?int $preparedBy, User $actor, string $verb): void
    {
        if ($preparedBy !== null && $preparedBy === (int) $actor->id) {
            throw new EngagementRuleViolation("The person who prepared it cannot {$verb} it.");
        }
    }

    /**
     * Active users of the current tenant holding the permission, minus $except. Resolved in SQL (one
     * query, however many users the tenant has).
     *
     * @return list<int>
     */
    public static function holders(string $permission, array $except = []): array
    {
        $except = array_map('intval', array_filter($except));

        return User::query()->forCurrentTenant()->where('status', UserStatus::Active)
            ->whereHas('roles.permissions', fn ($q) => $q->where('key', $permission))
            ->when($except !== [], fn ($q) => $q->whereNotIn('id', $except))
            ->orderBy('id')->pluck('id')->map(fn ($id) => (int) $id)->values()->all();
    }
}
