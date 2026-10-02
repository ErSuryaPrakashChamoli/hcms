<?php

namespace App\Domain\ServiceDesk\DomainActions;

use App\Domain\Employment\Models\Employee;
use App\Domain\Employment\Support\ChangeOrigin;
use App\Domain\Identity\Models\User;
use App\Domain\Letters\Models\LetterTemplate;
use App\Domain\Letters\Services\Letters;
use App\Domain\ServiceDesk\Models\Ticket;
use Illuminate\Database\Eloquent\Model;

/**
 * Phase 12: Salary / employment / experience letter → Letters (Letters::generate). It runs at
 * submission with the request as the letter's source. Letters keeps generation, approval (approver ≠
 * requester) and issue, and consumes approved data through its own contracts: the service desk
 * retrieves no salary or employment data. The case tracks the letter and closes when it is issued.
 */
final class LetterRequestAction extends BaseDomainAction
{
    protected array $sources = ['web', 'hr'];

    public function __construct(private readonly Letters $letters) {}

    public function label(): string
    {
        return 'Letter or certificate (Letters)';
    }

    public function timing(): string
    {
        return 'on_submit';
    }

    public function fields(?Employee $employee): array
    {
        return [
            'template' => ['label' => 'Letter', 'type' => 'dropdown', 'required' => true, 'options' => LetterTemplate::query()->where('status', 'active')->orderBy('name')->pluck('name', 'code')->all()],
            'purpose' => ['label' => 'Purpose (printed on the letter where the template uses it)', 'type' => 'text'],
        ];
    }

    public function executorPermission(): ?string
    {
        return null;
    }

    public function validate(Employee $employee, array $data): array
    {
        return $this->check($data, ['template' => ['required', 'string', 'max:64'], 'purpose' => ['nullable', 'string', 'max:255']]);
    }

    public function execute(Ticket $ticket, Employee $employee, array $data, User $actor, ChangeOrigin $origin): Model
    {
        $c = $this->validate($employee, $data);

        return $this->letters->generate((string) $c['template'], $employee, ['request' => ['number' => $ticket->number, 'purpose' => $c['purpose'] ?? null]], $actor, $ticket);
    }
}
