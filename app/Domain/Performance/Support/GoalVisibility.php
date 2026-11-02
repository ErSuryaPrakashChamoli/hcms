<?php

namespace App\Domain\Performance\Support;

use App\Domain\Employment\Models\Employee;
use App\Domain\Identity\Models\User;
use App\Domain\Identity\Scopes\AccessScope;
use App\Domain\Identity\Services\AccessScopes;
use App\Domain\Performance\Models\Goal;
use App\Domain\Performance\Scopes\GoalVisibilityScope;
use App\Domain\Performance\Services\PerformanceRelationships;
use App\Domain\Platform\Services\SettingsRepository;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Builder as QueryBuilder;

/**
 * HROne parity GO-4 — who sees a goal (owner decision PER-5 / D-GO5) and who may override its achievement (PER-6 / D-GO7).
 *
 * A public goal is seen exactly as before. A private goal (organisation goals only) is seen by:
 * - the person who created it, its owner (the organisation goal's owner person; the employee of an employee goal),
 * - the employees on its visible-to list,
 * - performance HR (`performance.view` or `performance.manage`) in scope: unscoped HR always; scoped HR when the owner
 *   person is inside their performance access scope (a private goal without an owner person is then not theirs to see).
 * Nobody else — not even through the alignment: the owner of a goal aligned to a private goal sees only that goal's title
 * (parentTitleFor), others "a private goal".
 *
 * The SQL form (constrain, applied by GoalVisibilityScope on every Goal query) and the PHP form (canSee, for policies)
 * are the same rule.
 */
final class GoalVisibility
{
    /** What a viewer who may not see the private goal another goal is aligned to reads instead of its title. */
    public const HIDDEN_PARENT = 'A private goal';

    /** Whether the rule limits this user at all: unscoped performance HR and platform administrators see every goal. */
    public static function unrestricted(User $user): bool
    {
        return $user->isPlatformAdmin() || (self::isHr($user) && ! app(AccessScopes::class)->isScoped($user, 'performance'));
    }

    public static function isHr(User $user): bool
    {
        return $user->hasPermission('performance.view') || $user->hasPermission('performance.manage');
    }

    /** Narrow a goal query to the goals $user may see (public goals, and private goals visible to them). */
    public static function constrain(Builder $query, User $user): Builder
    {
        if (self::unrestricted($user)) {
            return $query;
        }
        $goals = $query->getModel()->getTable();
        $mine = fn (QueryBuilder $q) => $q->select('id')->from('employees')->where('user_id', $user->id);

        return $query->where(function (Builder $q) use ($goals, $user, $mine) {
            $q->where("{$goals}.visibility", '!=', 'private')
                ->orWhere("{$goals}.created_by", $user->id)
                ->orWhereIn("{$goals}.owner_employee_id", $mine)
                ->orWhereIn("{$goals}.employee_id", $mine)
                ->orWhereExists(fn (QueryBuilder $e) => $e->selectRaw('1')->from('goal_viewers')
                    ->whereColumn('goal_viewers.goal_id', "{$goals}.id")
                    ->whereIn('goal_viewers.employee_id', $mine));
            if (self::isHr($user)) {
                // Scoped HR: private goals whose owner person is inside their performance scope.
                $q->orWhereIn("{$goals}.owner_employee_id", app(AccessScopes::class)->employeeKeys($user, 'performance'));
            }
        });
    }

    /** API-key requests (no signed-in person) read public goals only. */
    public static function publicOnly(Builder $query): Builder
    {
        return $query->where($query->getModel()->getTable().'.visibility', '!=', 'private');
    }

    /** The PHP form of constrain() for one goal (policies, actions). */
    public static function canSee(?User $user, Goal $goal): bool
    {
        if (! $goal->isPrivate()) {
            return true;
        }
        if ($user === null) {
            return false;
        }
        if (self::unrestricted($user) || (int) $goal->created_by === (int) $user->id) {
            return true;
        }
        $me = self::employeeOf($user);
        if ($me !== null && (in_array((int) $me->id, [(int) $goal->owner_employee_id, (int) $goal->employee_id], true)
            || $goal->viewers()->withoutGlobalScope(AccessScope::class)->where('employee_id', $me->id)->exists())) {
            return true;
        }

        return self::isHr($user) && $goal->owner_employee_id !== null
            && app(AccessScopes::class)->allowsEmployeeId($user, (int) $goal->owner_employee_id, 'performance');
    }

    /** The person a goal belongs to: the employee of an employee goal, else the organisation goal's owner person. */
    public static function personId(Goal $goal): ?int
    {
        $id = $goal->employee_id ?? $goal->owner_employee_id;

        return $id === null ? null : (int) $id;
    }

    /**
     * The title of the goal $child is aligned to, as $user may read it: the title when they may see that goal or own the
     * aligned goal (D-GO5: owners of aligned goals see only the parent goal's title); HIDDEN_PARENT otherwise; null when the
     * goal is not aligned.
     */
    public static function parentTitleFor(Goal $child, ?User $user): ?string
    {
        if ($child->parent_id === null) {
            return null;
        }
        if ($child->relationLoaded('parent') && $child->parent !== null) {
            return $child->parent->title;
        }
        // The access scope still applies (a goal outside it stays unnamed, as before); only the visibility rule is lifted.
        $parent = Goal::query()->withoutGlobalScope(GoalVisibilityScope::class)->find($child->parent_id);
        if ($parent === null) {
            return null;
        }
        if (! $parent->isPrivate() || ($user !== null && self::canSee($user, $parent))) {
            return $parent->title;
        }
        $me = $user === null ? null : self::employeeOf($user);

        return $me !== null && self::personId($child) === (int) $me->id ? $parent->title : self::HIDDEN_PARENT;
    }

    /** Whether the owner's switch PER-6 / D-GO7 is on. */
    public static function overrideEnabled(): bool
    {
        return (bool) app(SettingsRepository::class)->get('performance.goals.achievement_override', false);
    }

    /**
     * D-GO7: performance HR (`performance.manage`) or the performance manager of the goal's person (`performance.team`) may
     * override its achievement — never on their own goal, never on a goal they may not see. A goal without a person is
     * HR's only.
     */
    public static function mayOverride(?User $user, Goal $goal): bool
    {
        if ($user === null || ! self::canSee($user, $goal)) {
            return false;
        }
        $person = self::personId($goal);
        $me = self::employeeOf($user);
        if ($person !== null && $me !== null && (int) $me->id === $person) {
            return false;
        }
        if ($user->hasPermission('performance.manage')) {
            return true;
        }

        return $person !== null && $me !== null && $user->hasPermission('performance.team')
            && app(PerformanceRelationships::class)->manages($me, $person);
    }

    private static function employeeOf(User $user): ?Employee
    {
        return app(PerformanceRelationships::class)->forUser($user);
    }
}
