<?php

namespace App\Domain\Experience\RequestTypes;

use App\Domain\Employment\Models\Employee;
use App\Domain\Experience\Enums\RequestStatus;
use App\Domain\Identity\Models\User;
use App\Domain\Payroll\Models\SalaryComponent;
use App\Domain\Payroll\Models\VariablePayRequest;
use App\Filament\Resources\VariablePayRequests\VariablePayRequestResource;
use Illuminate\Database\Eloquent\Model;

/** HROne parity RQ-1: variable pay (Variable pay → "Request variable pay"; for an employee: "For an employee"). */
final class VariablePayRequestType extends BaseRequestType
{
    public function key(): string
    {
        return 'variable_pay';
    }

    public function label(): string
    {
        return 'Variable pay';
    }

    public function description(): string
    {
        return 'Incentive, bonus or another variable amount in a pay month';
    }

    public function module(): string
    {
        return 'Payroll';
    }

    public function icon(): string
    {
        return 'heroicon-o-currency-rupee';
    }

    public function keywords(): array
    {
        return ['variable', 'variable pay', 'incentive', 'bonus', 'commission'];
    }

    public function permission(): string
    {
        return 'payroll.request';
    }

    /** The form offers non-statutory earnings; without one there is nothing to request. */
    public function availableTo(User $user, ?Employee $employee): bool
    {
        return $this->signedIn($user) && $employee !== null && $user->can('payroll.request') && VariablePayRequestResource::canAccess()
            && SalaryComponent::query()->where('type', 'earning')->where('is_statutory', false)->exists();
    }

    public function formUrl(User $user, ?Employee $employee): ?string
    {
        return $this->withAction(VariablePayRequestResource::getUrl('index'), 'request');
    }

    public function onBehalfPermission(): ?string
    {
        return 'payroll.manage';
    }

    /** "For an employee" is offered with payroll.manage or leave.approve (a manager for their reports). */
    public function onBehalfUrl(User $user): ?string
    {
        return $this->signedIn($user) && VariablePayRequestResource::canAccess() && ($user->can('payroll.manage') || $user->can('leave.approve'))
            ? $this->withAction(VariablePayRequestResource::getUrl('index'), 'forEmployee') : null;
    }

    public function model(): ?string
    {
        return VariablePayRequest::class;
    }

    protected function resource(): ?string
    {
        return VariablePayRequestResource::class;
    }

    protected function with(): array
    {
        return ['component'];
    }

    public function statusMap(): array
    {
        return [
            'pending' => RequestStatus::Pending,
            'approved' => RequestStatus::Approved,
            'paid' => RequestStatus::Approved,
            'rejected' => RequestStatus::Rejected,
            'cancelled' => RequestStatus::Withdrawn,
        ];
    }

    public function dateFilters(): array
    {
        return [
            'requested' => ['label' => 'Requested on', 'column' => 'created_at'],
            'decided' => ['label' => 'Decided on', 'column' => 'reviewed_at'],
        ];
    }

    public function searchColumns(): array
    {
        return ['reason', 'pay_month'];
    }

    public function summary(Model $record): array
    {
        /** @var VariablePayRequest $record */
        return [
            'title' => $this->join($record->component?->name ?? 'Variable pay', number_format((float) $record->amount, 2), $record->pay_month ? 'pay month '.$record->pay_month : null),
            'detail' => $this->short($record->reason),
        ];
    }

    // -- approval route (RQ-2) ----------------------------------------------------------------------------------

    public function raisedEvents(): array
    {
        return ['payroll.variable_pay_requested'];
    }

    public function builtInApprovers(): string
    {
        return 'Payroll approvers in the Approval Center';
    }

    public function approverPermissions(): array
    {
        return ['payroll.manage'];
    }

    public function approvalKind(Model $record): ?string
    {
        return 'variable_pay';
    }

    public function knowledgeCategory(): ?string
    {
        return 'payroll';
    }
}
