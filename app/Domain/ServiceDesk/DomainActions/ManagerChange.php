<?php

namespace App\Domain\ServiceDesk\DomainActions;

use App\Domain\Employment\Actions\ChangeManagerAction;
use App\Domain\Employment\Models\Employee;
use App\Domain\Employment\Support\ChangeOrigin;
use App\Domain\Identity\Models\User;
use App\Domain\Identity\Scopes\AccessScope;
use App\Domain\ServiceDesk\Exceptions\ServiceDeskRuleViolation;
use App\Domain\ServiceDesk\Models\Ticket;
use Illuminate\Database\Eloquent\Model;

/**
 * Phase 12: Manager change request → Employment's ChangeManagerAction::change (employee.position,
 * both people in scope). It is requested by the current manager or HR, approved where the service says
 * so, and executed by HR.
 */
final class ManagerChange extends BaseDomainAction
{
    protected array $sources = ['manager', 'hr'];

    public function __construct(private readonly ChangeManagerAction $action) {}

    public function label(): string
    {
        return 'Manager change (Employment)';
    }

    public function timing(): string
    {
        return 'after_approval';
    }

    public function fields(?Employee $employee): array
    {
        return [
            'manager_employee_code' => ['label' => 'New manager (employee code)', 'type' => 'text', 'required' => true],
            'relationship_type' => ['label' => 'Relationship', 'type' => 'dropdown', 'options' => collect(config('peopleos.people.reporting_types', []))->only(['line', 'functional', 'dotted', 'secondary'])->all()],
            'effective_from' => ['label' => 'Effective from', 'type' => 'date', 'required' => true],
        ];
    }

    public function executorPermission(): ?string
    {
        return 'employee.position';
    }

    public function validate(Employee $employee, array $data): array
    {
        $c = $this->check($data, ['manager_employee_code' => ['required', 'string', 'max:64'], 'relationship_type' => ['nullable', 'in:line,functional,dotted,secondary'], 'effective_from' => ['required', 'date']]);
        if (! Employee::query()->withoutGlobalScope(AccessScope::class)->where('employee_code', $c['manager_employee_code'])->exists()) {
            throw new ServiceDeskRuleViolation('No employee has that code.');
        }

        return $c;
    }

    public function execute(Ticket $ticket, Employee $employee, array $data, User $actor, ChangeOrigin $origin): Model
    {
        $c = $this->validate($employee, $data);
        $manager = Employee::query()->withoutGlobalScope(AccessScope::class)->where('employee_code', $c['manager_employee_code'])->firstOrFail();
        try {
            return $this->action->change($employee, $manager, $actor, $c['relationship_type'] ?? 'line', $c['effective_from'], 'Service request '.$ticket->number);
        } catch (\InvalidArgumentException $e) {
            throw new ServiceDeskRuleViolation($e->getMessage());
        }
    }
}
