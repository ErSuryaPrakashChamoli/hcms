<?php

namespace App\Domain\Exit\Support;

use App\Domain\Employment\Models\Employee;
use App\Domain\Exit\Models\ExitClearance;
use App\Domain\Identity\Models\User;
use App\Domain\Identity\Scopes\AccessScope;
use App\Domain\Workflow\Models\TaskDelegation;
use App\Domain\Workflow\Services\TaskDelegations;
use Illuminate\Support\Collection;

/**
 * HROne parity WO-11 (§23.6.3 #9): clearance stage owners honour P4 delegation. While an out-of-office delegation runs
 * that covers the request type `exit_clearance` (Setup → Automation → Inbox actions → Delegate; nothing is delegated
 * unless the organisation lists it), the delegate acts on the stages the delegator owns (as owner user or through an
 * owner role) — never on the stages of the delegate's own exit. Without such a delegation, ownership is as before.
 */
final class ClearanceOwners
{
    public const KIND = 'exit_clearance';

    /** @return Collection<int, User> the people the user stands in for on clearance stages now */
    public static function delegators(User $user): Collection
    {
        if (! TaskDelegations::switchedOn()) {
            return collect();
        }
        $delegations = app(TaskDelegations::class);

        return $delegations->running($user)->filter(fn (TaskDelegation $d) => $delegations->covers($d, self::KIND))
            ->map(fn (TaskDelegation $d) => $d->delegator)->filter()->values();
    }

    /**
     * The user ids and role ids whose stages the user acts on: their own and their delegators'.
     *
     * @return array{users: list<int>, roles: list<int>}
     */
    public static function for(User $user): array
    {
        $people = collect([$user])->merge(self::delegators($user));

        return [
            'users' => $people->pluck('id')->map(fn ($id) => (int) $id)->unique()->values()->all(),
            'roles' => $people->flatMap(fn (User $u) => $u->roles()->pluck('roles.id'))->map(fn ($id) => (int) $id)->unique()->values()->all(),
        ];
    }

    /** The delegator through whom the user owns this stage, or null (not delegated, or the delegate's own exit). */
    public static function delegatorFor(ExitClearance $clearance, User $delegate): ?User
    {
        $delegators = self::delegators($delegate);
        if ($delegators->isEmpty()) {
            return null;
        }
        $employeeId = $clearance->exitCase()->withoutGlobalScope(AccessScope::class)->value('employee_id');
        if ($employeeId !== null && (int) Employee::query()->withoutGlobalScope(AccessScope::class)->whereKey($employeeId)->value('user_id') === (int) $delegate->id) {
            return null;
        }

        return $delegators->first(fn (User $delegator) => (int) $clearance->owner_user_id === (int) $delegator->id
            || ($clearance->owner_role_id !== null && $delegator->roles()->where('roles.id', $clearance->owner_role_id)->exists()));
    }
}
