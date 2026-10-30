<?php

namespace App\Domain\Experience\RequestTypes;

use App\Domain\Employment\Models\Employee;
use App\Domain\Expense\Models\ExpenseReceipt;
use App\Domain\Experience\Enums\RequestStatus;
use App\Domain\Identity\Models\User;
use App\Filament\Resources\ExpenseReceipts\ExpenseReceiptResource;
use Illuminate\Database\Eloquent\Model;

/**
 * HROne parity RQ-1: an expense (Expenses → "Add expense"; for an employee: "Add expense for an employee"). An expense not
 * yet in a report is a draft; it is decided through its report.
 */
final class ExpenseReceiptRequestType extends BaseRequestType
{
    public function key(): string
    {
        return 'expense';
    }

    public function label(): string
    {
        return 'Expense';
    }

    public function description(): string
    {
        return 'Add a receipt, a mileage trip or a daily allowance';
    }

    public function module(): string
    {
        return 'Expense & travel';
    }

    public function icon(): string
    {
        return 'heroicon-o-receipt-refund';
    }

    public function keywords(): array
    {
        return ['expense', 'receipt', 'bill', 'mileage', 'add expense'];
    }

    public function permission(): string
    {
        return 'expense.claim';
    }

    public function availableTo(User $user, ?Employee $employee): bool
    {
        return $this->signedIn($user) && $employee !== null && $user->can('expense.claim') && ExpenseReceiptResource::canAccess();
    }

    public function formUrl(User $user, ?Employee $employee): ?string
    {
        return $this->withAction(ExpenseReceiptResource::getUrl('index'), 'addExpense');
    }

    public function onBehalfPermission(): ?string
    {
        return 'expense.approve';
    }

    public function onBehalfUrl(User $user): ?string
    {
        return $this->signedIn($user) && ExpenseReceiptResource::canAccess() && $user->can('expense.approve')
            ? $this->withAction(ExpenseReceiptResource::getUrl('index'), 'addForEmployee') : null;
    }

    public function model(): ?string
    {
        return ExpenseReceipt::class;
    }

    protected function resource(): ?string
    {
        return ExpenseReceiptResource::class;
    }

    protected function with(): array
    {
        return ['category'];
    }

    public function statusMap(): array
    {
        return [
            'unreported' => RequestStatus::Draft,
            'reported' => RequestStatus::Pending,
            'approved' => RequestStatus::Approved,
            'partly_approved' => RequestStatus::Approved,
            'rejected' => RequestStatus::Rejected,
            'withdrawn' => RequestStatus::Withdrawn,
            // Never put in a report before it lapsed: closed without a decision.
            'expired' => RequestStatus::Withdrawn,
        ];
    }

    public function dateFilters(): array
    {
        return [
            'requested' => ['label' => 'Added on', 'column' => 'created_at'],
            'effective' => ['label' => 'Expense date', 'column' => 'expense_date'],
        ];
    }

    public function searchColumns(): array
    {
        return ['number', 'merchant', 'description', 'invoice_number'];
    }

    public function summary(Model $record): array
    {
        /** @var ExpenseReceipt $record */
        return [
            'title' => $this->join($record->number, $record->category?->name, trim(($record->currency ?? '').' '.number_format((float) $record->amount, 2))),
            'detail' => $this->join($record->expense_date?->format('d M Y'), $this->short($record->merchant ?: $record->description)),
        ];
    }

    // -- approval route (RQ-2) ----------------------------------------------------------------------------------

    public function raisedEvents(): array
    {
        return [];
    }

    public function builtInApprovers(): string
    {
        return 'Expenses are approved with the expense report you put them in';
    }

    public function approverPermissions(): array
    {
        return [];
    }

    public function knowledgeCategory(): ?string
    {
        return 'expense';
    }
}
