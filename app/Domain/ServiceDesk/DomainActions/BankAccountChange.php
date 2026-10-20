<?php

namespace App\Domain\ServiceDesk\DomainActions;

use App\Domain\Employment\Actions\ChangeBankAccountAction;
use App\Domain\Employment\Models\Employee;
use App\Domain\Employment\Support\ChangeOrigin;
use App\Domain\Identity\Models\User;
use App\Domain\ServiceDesk\Models\Ticket;
use Illuminate\Database\Eloquent\Model;

/** Phase 12: Bank Account Change → Employment's ChangeBankAccountAction::add (the new account). */
final class BankAccountChange extends ProfileChangeHandler
{
    public function __construct(private readonly ChangeBankAccountAction $action) {}

    public function label(): string
    {
        return 'Bank account change (Employment)';
    }

    public function fields(?Employee $employee): array
    {
        $s = ['class' => 'sensitive'];

        return [
            'account_holder_name' => ['label' => 'Account holder name', 'type' => 'text', 'required' => true] + $s,
            'bank_name' => ['label' => 'Bank', 'type' => 'text', 'required' => true] + $s,
            'branch_name' => ['label' => 'Branch', 'type' => 'text'] + $s,
            'ifsc' => ['label' => 'IFSC', 'type' => 'text', 'help' => 'e.g. HDFC0001234'] + $s,
            'account_number' => ['label' => 'Account number', 'type' => 'text', 'required' => true] + $s,
            'account_type' => ['label' => 'Account type', 'type' => 'dropdown', 'options' => ['savings' => 'Savings', 'current' => 'Current', 'salary' => 'Salary']] + $s,
            'is_primary' => ['label' => 'Make this the salary account', 'type' => 'checkbox'] + $s,
        ];
    }

    public function executorPermission(): ?string
    {
        return ChangeBankAccountAction::PERMISSION;
    }

    public function viewPermission(): string
    {
        return 'employee.sensitive.view';
    }

    public function validate(Employee $employee, array $data): array
    {
        return $this->check($data, $this->action->rules(true));
    }

    public function execute(Ticket $ticket, Employee $employee, array $data, User $actor, ChangeOrigin $origin): Model
    {
        return $this->action->add($employee, $this->validate($employee, $data), $actor, $this->reason($ticket->number), $origin);
    }
}
