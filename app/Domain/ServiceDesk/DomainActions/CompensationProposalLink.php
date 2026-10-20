<?php

namespace App\Domain\ServiceDesk\DomainActions;

use App\Domain\Compensation\Models\CompensationChange;
use App\Domain\Employment\Models\Employee;
use App\Domain\Employment\Support\ChangeOrigin;
use App\Domain\Identity\Models\User;
use App\Domain\ServiceDesk\Exceptions\ServiceDeskRuleViolation;
use App\Domain\ServiceDesk\Models\Ticket;
use Illuminate\Database\Eloquent\Model;

/**
 * Phase 12: Salary change request → Compensation proposal. The service desk never mutates salary:
 * - an HR proposer (compensation.propose, never the requester, never their own compensation) creates
 *   the proposal in Compensation;
 * - the proposer links its reference to the case;
 * - the proposal follows the Compensation chain (review → approval → execution).
 *
 * The request carries no amounts.
 */
final class CompensationProposalLink extends BaseDomainAction
{
    protected array $sources = ['web', 'manager', 'hr'];

    public function label(): string
    {
        return 'Salary change request (Compensation proposal)';
    }

    public function timing(): string
    {
        return 'link';
    }

    public function fields(?Employee $employee): array
    {
        return [];
    }

    public function executionFields(Employee $employee, User $executor): array
    {
        $options = CompensationChange::query()->where('employee_id', $employee->id)->where('proposed_by', $executor->id)
            ->whereIn('status', ['draft', 'submitted', 'under_review', 'approved', 'scheduled'])->orderByDesc('id')->pluck('reference', 'reference')->all();

        return ['compensation_reference' => ['label' => 'Your compensation proposal', 'type' => 'dropdown', 'required' => true, 'options' => $options]];
    }

    public function executorPermission(): ?string
    {
        return 'compensation.propose';
    }

    public function viewPermission(): string
    {
        return 'compensation.propose';
    }

    public function validate(Employee $employee, array $data): array
    {
        return [];
    }

    public function execute(Ticket $ticket, Employee $employee, array $data, User $actor, ChangeOrigin $origin): Model
    {
        $reference = (string) ($data['compensation_reference'] ?? '');

        return CompensationChange::query()->where('reference', $reference)->where('employee_id', $employee->id)->where('proposed_by', $actor->id)->first()
            ?? throw new ServiceDeskRuleViolation('Link a compensation proposal you created for this employee.');
    }
}
