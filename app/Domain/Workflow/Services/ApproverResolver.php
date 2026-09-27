<?php

namespace App\Domain\Workflow\Services;

use App\Domain\Employment\Models\Employee;
use App\Domain\Identity\Models\Role;
use App\Domain\Identity\Models\User;
use App\Domain\Notifications\Services\NotificationContext;
use App\Domain\Workflow\Models\WorkflowInstance;
use Illuminate\Support\Arr;

/**
 * Turns an approver spec into either a concrete user or a role (blueprint §45: single, hierarchy,
 * role, dynamic). Returns ['user' => ?User, 'role' => ?Role].
 */
final class ApproverResolver
{
    public function __construct(private readonly NotificationContext $context) {}

    /**
     * @param  array<string, mixed>  $spec
     * @return array{user: ?User, role: ?Role}
     */
    public function resolve(array $spec, WorkflowInstance $instance): array
    {
        $employee = $this->context->employeeOf($instance->subject);

        return match ($spec['type'] ?? null) {
            'manager' => ['user' => $this->managerUser($employee, 1), 'role' => null],
            'hierarchy_level' => ['user' => $this->managerUser($employee, max(1, (int) ($spec['level'] ?? 1))), 'role' => null],
            'initiator_manager' => ['user' => $this->managerUser($this->initiatorEmployee($instance), 1), 'role' => null],
            'role' => ['user' => null, 'role' => isset($spec['role_id']) ? Role::query()->find($spec['role_id']) : Role::query()->where('slug', $spec['role'] ?? '')->first()],
            'user' => ['user' => isset($spec['user_id']) ? User::query()->forCurrentTenant()->find($spec['user_id']) : null, 'role' => null],
            'field' => ['user' => ($id = Arr::get($instance->context, $spec['field'] ?? '')) ? User::query()->forCurrentTenant()->find($id) : null, 'role' => null],
            default => ['user' => null, 'role' => null],
        };
    }

    private function managerUser(?Employee $employee, int $levels): ?User
    {
        $current = $employee;

        for ($i = 0; $i < $levels && $current !== null; $i++) {
            $current = $current->currentManager?->manager;
        }

        return $current?->user_id ? User::query()->find($current->user_id) : null;
    }

    private function initiatorEmployee(WorkflowInstance $instance): ?Employee
    {
        return $instance->started_by ? Employee::query()->where('user_id', $instance->started_by)->first() : null;
    }
}
