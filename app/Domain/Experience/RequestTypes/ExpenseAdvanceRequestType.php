<?php

namespace App\Domain\Experience\RequestTypes;

use App\Domain\Employment\Models\Employee;
use App\Domain\Expense\Models\ExpenseAdvance;
use App\Domain\Expense\Services\ExpensePolicies;
use App\Domain\Experience\Enums\RequestStatus;
use App\Domain\Identity\Models\User;
use App\Filament\Resources\ExpenseAdvances\ExpenseAdvanceResource;
use Illuminate\Database\Eloquent\Model;

/** HROne parity RQ-1: expense advance (Expense advances → "Request an advance"). */
final class ExpenseAdvanceRequestType extends BaseRequestType
{
    public function key(): string
    {
        return 'expense_advance';
    }

    public function label(): string
    {
        return 'Expense advance';
    }

    public function description(): string
    {
        return 'Money in advance for expenses you will report later';
    }

    public function module(): string
    {
        return 'Expense & travel';
    }

    public function icon(): string
    {
        return 'heroicon-o-wallet';
    }

    public function keywords(): array
    {
        return ['advance', 'expense advance', 'cash advance', 'imprest'];
    }

    public function permission(): string
    {
        return 'expense.claim';
    }

    /** The person's expense advance policy must allow advance requests (the form shows its refusal otherwise). */
    public function availableTo(User $user, ?Employee $employee): bool
    {
        return $this->signedIn($user) && $employee !== null && $user->can('expense.claim') && ExpenseAdvanceResource::canAccess()
            && app(ExpensePolicies::class)->advanceRules($employee, today())->refusal() === null;
    }

    public function formUrl(User $user, ?Employee $employee): ?string
    {
        return $this->withAction(ExpenseAdvanceResource::getUrl('index'), 'requestAdvance');
    }

    public function model(): ?string
    {
        return ExpenseAdvance::class;
    }

    protected function resource(): ?string
    {
        return ExpenseAdvanceResource::class;
    }

    public function statusMap(): array
    {
        return [
            'requested' => RequestStatus::Pending,
            'cancel_requested' => RequestStatus::Pending,
            'approved' => RequestStatus::Approved,
            'disbursed' => RequestStatus::Approved,
            'settled' => RequestStatus::Approved,
            'recovered' => RequestStatus::Approved,
            'written_off' => RequestStatus::Approved,
            'rejected' => RequestStatus::Rejected,
            'cancelled' => RequestStatus::Withdrawn,
        ];
    }

    public function dateFilters(): array
    {
        return [
            'requested' => ['label' => 'Requested on', 'column' => 'created_at'],
            'effective' => ['label' => 'Needed on', 'column' => 'needed_on'],
            'decided' => ['label' => 'Decided on', 'column' => 'decided_at'],
        ];
    }

    public function searchColumns(): array
    {
        return ['number', 'purpose'];
    }

    public function summary(Model $record): array
    {
        /** @var ExpenseAdvance $record */
        return [
            'title' => $this->join($record->number, trim(($record->currency ?? '').' '.number_format((float) $record->amount, 2))),
            'detail' => $this->short($record->purpose),
        ];
    }

    // -- approval route (RQ-2) ----------------------------------------------------------------------------------

    public function raisedEvents(): array
    {
        return ['expense.advance_requested'];
    }

    public function builtInApprovers(): string
    {
        return 'Expense approvers in the Approval Center';
    }

    public function approverPermissions(): array
    {
        return ['expense.approve'];
    }

    public function approvalKind(Model $record): ?string
    {
        return $this->kindByStatus($record, 'expense_advance', 'expense_advance_cancellation');
    }

    public function knowledgeCategory(): ?string
    {
        return 'expense';
    }
}
