<?php

namespace App\Domain\Exit\Services;

use App\Domain\Employment\Actions\ChangeManagerAction;
use App\Domain\Employment\Models\Employee;
use App\Domain\Employment\Models\ReportingRelationship;
use App\Domain\Exit\Models\ExitCase;
use App\Domain\Identity\Models\Role;
use App\Domain\Identity\Models\User;
use App\Domain\Identity\Scopes\AccessScope;
use App\Domain\Identity\Services\AccessScopes;
use App\Domain\Identity\Services\RoleMembers;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use RuntimeException;

/**
 * HROne parity WO-11 (§23.6.3 #12–13, owner decision WRK-6 / D-WO10 — HR chooses): the "Roles held" hand-over step of the
 * HR clearance stage.
 *
 * - Roles held: the roles the leaver's sign-in holds by hand (not the standard employee / alumni roles; grants made by a
 *   membership rule follow their rule and are only listed), with the leaver's data-access scope. HR replaces a role (the
 *   replacement is given it — for the same company when the grant was limited to one — and the leaver loses it) or
 *   removes it, through RoleMembers (`user.assign_roles`, the delegation ceiling, audited as ROLE_CHANGED with the reason).
 * - Team: the people who report to the leaver (any reporting type) on the day after the last working day. "Change
 *   manager" moves one or several to a new manager from that day, through ChangeManagerAction::change
 *   (`employee.position`, both people in scope, audited and on the timeline). Bulk change manager is WO-16.
 * - unresolved(): what is left — shown before "Complete exit" as a warning; it never blocks the exit.
 */
final class ExitHandover
{
    /** Roles everyone has by being an employee (or alumnus): never a hand-over item. */
    public const BASELINE_ROLES = ['employee', 'alumni'];

    public function __construct(private readonly RoleMembers $members) {}

    public function leaverUser(ExitCase $case): ?User
    {
        $userId = Employee::query()->withoutGlobalScope(AccessScope::class)->whereKey($case->employee_id)->value('user_id');

        return $userId !== null ? User::forCurrentTenant()->find($userId) : null;
    }

    /** @return list<array{role_id: int, name: string, company_id: ?int, company: ?string, by_rule: bool}> */
    public function rolesHeld(ExitCase $case): array
    {
        $user = $this->leaverUser($case);
        if ($user === null) {
            return [];
        }

        return DB::table('role_user')->join('roles', 'roles.id', '=', 'role_user.role_id')
            ->leftJoin('companies', 'companies.id', '=', 'role_user.company_id')
            ->where('role_user.user_id', $user->id)->where('roles.tenant_id', $user->tenant_id)->whereNotIn('roles.slug', self::BASELINE_ROLES)
            ->orderBy('roles.name')->get(['roles.id as role_id', 'roles.name as name', 'role_user.company_id as company_id', 'companies.name as company', 'role_user.source as source'])
            ->map(fn ($row) => ['role_id' => (int) $row->role_id, 'name' => (string) $row->name, 'company_id' => $row->company_id !== null ? (int) $row->company_id : null,
                'company' => $row->company, 'by_rule' => $row->source === 'rule'])
            ->values()->all();
    }

    /** @return list<string> the leaver's data-access scope ("Department: Finance"), empty = the whole organisation */
    public function scopes(ExitCase $case): array
    {
        $user = $this->leaverUser($case);
        $scope = $user !== null ? app(AccessScopes::class)->for($user) : null;

        return collect($scope ?? [])->map(fn (array $ids, string $dimension) => ucfirst(str_replace('_', ' ', $dimension)).': '.count($ids).' unit(s)')->values()->all();
    }

    /** @return Collection<int, ReportingRelationship> who still reports to the leaver the day after the last working day */
    public function reportees(ExitCase $case): Collection
    {
        return ReportingRelationship::query()->withoutGlobalScope(AccessScope::class)->with('employee.person')
            ->where('manager_id', $case->employee_id)->effectiveOn($this->dayAfter($case))
            ->whereHas('employee', fn ($q) => $q->withoutGlobalScope(AccessScope::class)->whereNotIn('lifecycle_state', ['exited', 'alumni', 'not_joined']))
            ->orderBy('employee_id')->get();
    }

    /** @return list<string> what is left to hand over (a warning before "Complete exit"; never a block) */
    public function unresolved(ExitCase $case): array
    {
        $items = [];
        foreach ($this->rolesHeld($case) as $role) {
            if (! $role['by_rule']) {
                $items[] = 'Still holds the role "'.$role['name'].'"'.($role['company'] ? ' ('.$role['company'].')' : '');
            }
        }
        $team = $this->reportees($case)->pluck('employee_id')->unique()->count();
        if ($team > 0) {
            $items[] = $team.' team member(s) still report to them after the last working day';
        }

        return $items;
    }

    /** Replace (another person takes the role) or remove a role the leaver holds by hand. */
    public function handOverRole(ExitCase $case, int $roleId, ?User $replacement, User $actor, string $reason): void
    {
        $this->assertHr($case, $actor);
        $reason = trim(strip_tags($reason));
        if ($reason === '') {
            throw new RuntimeException('Give the reason for the hand-over.');
        }
        $leaver = $this->leaverUser($case) ?? throw new RuntimeException('The employee has no sign-in, so holds no role.');
        $held = collect($this->rolesHeld($case))->where('role_id', $roleId)->reject(fn (array $r) => $r['by_rule']);
        if ($held->isEmpty()) {
            throw new RuntimeException('The employee does not hold that role by hand.');
        }
        $role = Role::query()->findOrFail($roleId);
        if ($replacement !== null && (int) $replacement->id === (int) $leaver->id) {
            throw new RuntimeException('Choose someone else to take the role.');
        }
        $note = "Exit hand-over {$case->number}: {$reason}";

        DB::transaction(function () use ($role, $replacement, $leaver, $held, $actor, $note) {
            if ($replacement !== null) {
                foreach ($held as $grant) {
                    $already = DB::table('role_user')->where('role_id', $role->id)->where('user_id', $replacement->id)
                        ->when($grant['company_id'] === null, fn ($q) => $q->whereNull('company_id'), fn ($q) => $q->where('company_id', $grant['company_id']))
                        ->where('source', '!=', 'rule')->exists();
                    if (! $already) {
                        $this->members->add($role, $replacement, $grant['company_id'], $actor, $note);
                    }
                }
            }
            $this->members->remove($role, $leaver, $actor, $note);
        });
    }

    /**
     * Move team members of the leaver to a new manager from the day after the last working day.
     *
     * @param  list<int>  $employeeIds
     * @return int how many moved
     */
    public function changeManager(ExitCase $case, array $employeeIds, Employee $manager, User $actor, string $reason): int
    {
        $this->assertHr($case, $actor);
        $reason = trim(strip_tags($reason));
        if ($reason === '') {
            throw new RuntimeException('Give the reason for the change of manager.');
        }
        if ((int) $manager->id === (int) $case->employee_id) {
            throw new RuntimeException('Choose a manager other than the leaver.');
        }
        $relationships = $this->reportees($case)->whereIn('employee_id', array_map('intval', $employeeIds));
        if ($relationships->isEmpty()) {
            throw new RuntimeException('Choose team members who report to the leaver.');
        }
        $from = $this->dayAfter($case);

        return DB::transaction(function () use ($relationships, $manager, $actor, $from, $case, $reason) {
            $moved = 0;
            foreach ($relationships as $relationship) {
                $employee = Employee::query()->findOrFail($relationship->employee_id);
                try {
                    app(ChangeManagerAction::class)->change($employee, $manager, $actor, (string) $relationship->type, $from, "Exit hand-over {$case->number}: {$reason}");
                } catch (InvalidArgumentException $e) {
                    throw new RuntimeException($e->getMessage());
                }
                $moved++;
            }

            return $moved;
        });
    }

    private function dayAfter(ExitCase $case): Carbon
    {
        return $case->last_working_day->copy()->addDay()->startOfDay();
    }

    private function assertHr(ExitCase $case, User $actor): void
    {
        if (! $actor->hasPermission('exit.manage') || ! app(AccessScopes::class)->allows($actor, $case)) {
            throw new RuntimeException('You cannot hand over for this exit.');
        }
        if (! $case->isOpen()) {
            throw new RuntimeException('The exit is not open.');
        }
    }
}
