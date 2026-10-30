<?php

namespace App\Domain\Workflow\Services;

use App\Domain\Employment\Models\Employee;
use App\Domain\Employment\Models\ReportingRelationship;
use App\Domain\Identity\Enums\UserStatus;
use App\Domain\Identity\Models\Role;
use App\Domain\Identity\Models\User;
use App\Domain\Identity\Scopes\AccessScope;
use App\Domain\Notifications\Services\NotificationContext;
use App\Domain\Platform\Services\SettingsRepository;
use App\Domain\Workflow\Models\WorkflowInstance;
use Illuminate\Support\Arr;

/**
 * Turns an approver spec into either a concrete user or a role (blueprint §45: single, hierarchy,
 * role, dynamic). Returns ['user' => ?User, 'role' => ?Role].
 *
 * HROne setup parity (Role → reliever), all inert until configured:
 * - A role nobody active holds goes to the role's reliever when it names an active user; an inactive role resolves to
 *   its reliever or to nobody (so the node's fallback applies). An active role without a reliever keeps today's
 *   behaviour: the task waits in the role's queue.
 * - A manager approver whose account is not active goes to the manager reliever (workflow.manager_reliever_user_id).
 *   With no manager at all, the node's fallback is tried first and the manager reliever after it (reliever()).
 *
 * HROne setup parity P5: `functional_manager` and `hrbp` resolve the subject employee's current reporting relationship of
 * that type (people.reporting_types `functional` / `hrbp`, effective today). A functional manager whose account is
 * inactive goes to the manager reliever like a line manager; an HR business partner who cannot act resolves to nobody,
 * so the node's fallback applies.
 */
final class ApproverResolver
{
    private const MANAGER_TYPES = ['manager', 'hierarchy_level', 'initiator_manager', 'functional_manager'];

    public function __construct(private readonly NotificationContext $context, private readonly SettingsRepository $settings) {}

    /**
     * @param  array<string, mixed>  $spec
     * @return array{user: ?User, role: ?Role}
     */
    public function resolve(array $spec, WorkflowInstance $instance): array
    {
        $employee = $this->context->employeeOf($instance->subject);

        return match ($spec['type'] ?? null) {
            'manager' => ['user' => $this->activeOrReliever($this->managerUser($employee, 1)), 'role' => null],
            'hierarchy_level' => ['user' => $this->activeOrReliever($this->managerUser($employee, max(1, (int) ($spec['level'] ?? 1)))), 'role' => null],
            'initiator_manager' => ['user' => $this->activeOrReliever($this->managerUser($this->initiatorEmployee($instance), 1)), 'role' => null],
            'functional_manager' => ['user' => $this->activeOrReliever($this->relationshipUser($employee, 'functional')), 'role' => null],
            'hrbp' => ['user' => $this->activeUser($this->relationshipUser($employee, 'hrbp')?->id), 'role' => null],
            'role' => $this->role(isset($spec['role_id']) ? Role::query()->find($spec['role_id']) : Role::query()->where('slug', $spec['role'] ?? '')->first()),
            'user' => ['user' => isset($spec['user_id']) ? User::query()->forCurrentTenant()->find($spec['user_id']) : null, 'role' => null],
            'field' => ['user' => ($id = Arr::get($instance->context, $spec['field'] ?? '')) ? User::query()->forCurrentTenant()->find($id) : null, 'role' => null],
            default => ['user' => null, 'role' => null],
        };
    }

    /**
     * The last resort before a node is left without an assignee: the manager reliever for a manager approver that found
     * no manager (after the node's own fallback was tried). Null when none is configured.
     *
     * @param  array<string, mixed>  $spec
     */
    public function reliever(array $spec): ?User
    {
        return in_array($spec['type'] ?? null, self::MANAGER_TYPES, true) ? $this->managerReliever() : null;
    }

    /** @return array{user: ?User, role: ?Role} */
    private function role(?Role $role): array
    {
        if ($role === null) {
            return ['user' => null, 'role' => null];
        }
        $held = ! $role->isInactive() && $role->users()->where('users.status', UserStatus::Active->value)->exists();
        if ($held) {
            return ['user' => null, 'role' => $role];
        }
        $reliever = $this->activeUser($role->getAttributes()['reliever_user_id'] ?? null);

        return match (true) {
            $reliever !== null => ['user' => $reliever, 'role' => null],
            $role->isInactive() => ['user' => null, 'role' => null],
            default => ['user' => null, 'role' => $role],
        };
    }

    private function activeOrReliever(?User $manager): ?User
    {
        if ($manager === null || $manager->isActive()) {
            return $manager;
        }

        return $this->managerReliever() ?? $manager;
    }

    private function managerReliever(): ?User
    {
        return $this->activeUser((int) $this->settings->get('workflow.manager_reliever_user_id', 0));
    }

    private function activeUser(int|string|null $id): ?User
    {
        if ($id === null || (int) $id <= 0) {
            return null;
        }
        $user = User::query()->forCurrentTenant()->find((int) $id);

        return $user?->isActive() ? $user : null;
    }

    private function managerUser(?Employee $employee, int $levels): ?User
    {
        $current = $employee;

        for ($i = 0; $i < $levels && $current !== null; $i++) {
            $current = $current->currentManager?->manager;
        }

        return $current?->user_id ? User::query()->find($current->user_id) : null;
    }

    /** The user of the employee's current reporting relationship of a type (functional manager, HR business partner). */
    private function relationshipUser(?Employee $employee, string $type): ?User
    {
        if ($employee === null) {
            return null;
        }
        $managerId = ReportingRelationship::query()->withoutGlobalScope(AccessScope::class)->where('employee_id', $employee->id)->where('type', $type)
            ->effectiveOn()->orderByDesc('effective_from')->orderByDesc('id')->value('manager_id');
        $userId = $managerId ? Employee::query()->withoutGlobalScope(AccessScope::class)->whereKey($managerId)->value('user_id') : null;

        return $userId ? User::query()->find($userId) : null;
    }

    private function initiatorEmployee(WorkflowInstance $instance): ?Employee
    {
        return $instance->started_by ? Employee::query()->where('user_id', $instance->started_by)->first() : null;
    }
}
