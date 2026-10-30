<?php

namespace App\Domain\Experience\RequestTypes;

use App\Domain\Employment\Models\Employee;
use App\Domain\Experience\Enums\RequestStatus;
use App\Domain\Identity\Models\User;
use App\Domain\Loans\Models\Loan;
use App\Filament\Resources\Loans\LoanResource;
use Illuminate\Database\Eloquent\Model;

/** HROne parity RQ-1: loan or salary advance (Loans → "Request a loan or advance"; on behalf: "Raise on behalf"). */
final class LoanRequestType extends BaseRequestType
{
    public function key(): string
    {
        return 'loan';
    }

    public function label(): string
    {
        return 'Loan';
    }

    public function description(): string
    {
        return 'A loan or salary advance, repaid in monthly instalments';
    }

    public function module(): string
    {
        return 'Payroll';
    }

    public function icon(): string
    {
        return 'heroicon-o-building-library';
    }

    public function keywords(): array
    {
        return ['loan', 'advance', 'salary advance', 'borrow'];
    }

    public function permission(): string
    {
        return 'payroll.request';
    }

    public function availableTo(User $user, ?Employee $employee): bool
    {
        return $this->signedIn($user) && $employee !== null && $user->can('payroll.request') && LoanResource::canAccess();
    }

    public function formUrl(User $user, ?Employee $employee): ?string
    {
        return $this->withAction(LoanResource::getUrl('index'), 'requestLoan');
    }

    public function onBehalfPermission(): ?string
    {
        return 'payroll.manage';
    }

    public function onBehalfUrl(User $user): ?string
    {
        return $this->signedIn($user) && LoanResource::canAccess() && $user->can('payroll.manage')
            ? $this->withAction(LoanResource::getUrl('index'), 'onBehalf') : null;
    }

    public function model(): ?string
    {
        return Loan::class;
    }

    protected function resource(): ?string
    {
        return LoanResource::class;
    }

    public function statusMap(): array
    {
        return [
            'pending' => RequestStatus::Pending,
            'approved' => RequestStatus::Approved,
            'active' => RequestStatus::Approved,
            'closed' => RequestStatus::Approved,
            'rejected' => RequestStatus::Rejected,
            'cancelled' => RequestStatus::Withdrawn,
        ];
    }

    public function dateFilters(): array
    {
        return [
            'requested' => ['label' => 'Requested on', 'column' => 'created_at'],
            'effective' => ['label' => 'Disbursed on', 'column' => 'disbursed_on'],
            'decided' => ['label' => 'Decided on', 'column' => 'reviewed_at'],
        ];
    }

    public function searchColumns(): array
    {
        return ['number', 'reason'];
    }

    public function summary(Model $record): array
    {
        /** @var Loan $record */
        return [
            'title' => $this->join($record->number, $record->typeLabel(), number_format((float) $record->principal, 2)),
            'detail' => $this->join($record->installments ? $record->installments.' instalment(s)' : null, $this->short($record->reason)),
        ];
    }

    // -- approval route (RQ-2) ----------------------------------------------------------------------------------

    public function raisedEvents(): array
    {
        return ['payroll.loan_requested'];
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
        return 'loan';
    }

    public function knowledgeCategory(): ?string
    {
        return 'payroll';
    }
}
