<?php

namespace App\Domain\ServiceDesk\DomainActions;

use App\Domain\Employment\Actions\ChangeStatutoryIdentityAction;
use App\Domain\Employment\Models\Employee;
use App\Domain\Employment\Support\ChangeOrigin;
use App\Domain\Identity\Models\User;
use App\Domain\ServiceDesk\Exceptions\ServiceDeskRuleViolation;
use App\Domain\ServiceDesk\Models\Ticket;
use Illuminate\Database\Eloquent\Model;

/** Phase 12: PAN / UAN / ESIC / PF / Aadhaar reference update → Employment's ChangeStatutoryIdentityAction. */
final class StatutoryIdentityChange extends ProfileChangeHandler
{
    public function __construct(private readonly ChangeStatutoryIdentityAction $action) {}

    public function label(): string
    {
        return 'Statutory identity update — PAN / UAN / ESIC (Employment)';
    }

    public function fields(?Employee $employee): array
    {
        $s = ['class' => 'sensitive'];

        return [
            'pan' => ['label' => 'PAN', 'type' => 'text'] + $s,
            'uan' => ['label' => 'UAN', 'type' => 'text'] + $s,
            'esic_number' => ['label' => 'ESIC number', 'type' => 'text'] + $s,
            'pf_number' => ['label' => 'PF number', 'type' => 'text'] + $s,
            'aadhaar_reference' => ['label' => 'Aadhaar reference', 'type' => 'text'] + $s,
        ];
    }

    public function executorPermission(): ?string
    {
        return 'employee.sensitive.update';
    }

    public function viewPermission(): string
    {
        return 'employee.sensitive.view';
    }

    public function validate(Employee $employee, array $data): array
    {
        $clean = array_filter($this->check($data, [
            'pan' => ['nullable', 'string', 'size:10', 'regex:/^[A-Z]{5}[0-9]{4}[A-Z]$/'],
            'aadhaar_reference' => ['nullable', 'string', 'max:16'],
            'uan' => ['nullable', 'string', 'max:12'],
            'pf_number' => ['nullable', 'string', 'max:32'],
            'esic_number' => ['nullable', 'string', 'max:32'],
        ]), fn ($v) => filled($v));

        return $clean !== [] ? $clean : throw new ServiceDeskRuleViolation('Give at least one identifier to update.');
    }

    public function execute(Ticket $ticket, Employee $employee, array $data, User $actor, ChangeOrigin $origin): Model
    {
        return $this->action->handle($employee, $this->validate($employee, $data), $actor, $this->reason($ticket->number), $origin);
    }
}
