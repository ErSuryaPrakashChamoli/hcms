<?php

namespace App\Domain\ServiceDesk\DomainActions;

use App\Domain\Employment\Models\Employee;
use App\Domain\Employment\Support\ChangeOrigin;
use App\Domain\Identity\Models\User;
use App\Domain\Leave\Models\LeaveRequest;
use App\Domain\Leave\Models\LeaveType;
use App\Domain\Leave\Services\Leaves;
use App\Domain\ServiceDesk\Exceptions\ServiceDeskRuleViolation;
use App\Domain\ServiceDesk\Models\Ticket;
use Illuminate\Database\Eloquent\Model;

/**
 * Phase 12: Leave application → Leave domain (Leaves::request). It runs at submission and is keyed to
 * the request's correlation id, so a retry never creates a second leave request. Balance, overlap and
 * approval stay in Leave: the case only tracks the leave request and closes when Leave decides it.
 */
final class LeaveRequestAction extends BaseDomainAction
{
    protected array $sources = ['web', 'hr'];

    public function __construct(private readonly Leaves $leaves) {}

    public function label(): string
    {
        return 'Leave application (Leave)';
    }

    public function timing(): string
    {
        return 'on_submit';
    }

    public function fields(?Employee $employee): array
    {
        return [
            'leave_type_id' => ['label' => 'Leave type', 'type' => 'dropdown', 'required' => true, 'options' => LeaveType::query()->where('status', 'active')->orderBy('sort_order')->pluck('name', 'id')->mapWithKeys(fn ($n, $id) => [(string) $id => $n])->all()],
            'from_date' => ['label' => 'From', 'type' => 'date', 'required' => true],
            'to_date' => ['label' => 'To', 'type' => 'date', 'required' => true],
            'from_session' => ['label' => 'Start', 'type' => 'dropdown', 'options' => LeaveRequest::SESSIONS],
            'to_session' => ['label' => 'End', 'type' => 'dropdown', 'options' => LeaveRequest::SESSIONS],
            'leave_reason' => ['label' => 'Reason', 'type' => 'textarea', 'required' => true],
        ];
    }

    public function authorizeRequest(User $requester, Employee $employee, string $source): void
    {
        parent::authorizeRequest($requester, $employee, $source);
        if ($source === 'web' && ! $requester->hasPermission('leave.apply')) {
            throw new ServiceDeskRuleViolation('This needs leave.apply.');
        }
    }

    public function executorPermission(): ?string
    {
        return null;
    }

    public function validate(Employee $employee, array $data): array
    {
        return $this->check($data, [
            'leave_type_id' => ['required', 'integer'], 'from_date' => ['required', 'date'], 'to_date' => ['required', 'date', 'after_or_equal:from_date'],
            'from_session' => ['nullable', 'in:'.implode(',', array_keys(LeaveRequest::SESSIONS))], 'to_session' => ['nullable', 'in:'.implode(',', array_keys(LeaveRequest::SESSIONS))],
            'leave_reason' => ['required', 'string', 'max:1000'],
        ]);
    }

    public function execute(Ticket $ticket, Employee $employee, array $data, User $actor, ChangeOrigin $origin): Model
    {
        $clean = $this->validate($employee, $data);
        $type = LeaveType::query()->where('status', 'active')->findOrFail($clean['leave_type_id']);

        return $this->leaves->request($employee, $type, $clean['from_date'], $clean['to_date'], $clean['leave_reason'], $clean['from_session'] ?? 'full', $clean['to_session'] ?? ($clean['from_session'] ?? 'full'),
            requester: $actor, idempotencyKey: 'sd-'.$ticket->correlation_id);
    }
}
