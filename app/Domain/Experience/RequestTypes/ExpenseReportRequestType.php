<?php

namespace App\Domain\Experience\RequestTypes;

use App\Domain\Employment\Models\Employee;
use App\Domain\Expense\Models\ExpenseReport;
use App\Domain\Expense\Models\ExpenseReportPart;
use App\Domain\Experience\Enums\RequestStatus;
use App\Domain\Identity\Models\User;
use App\Filament\Resources\ExpenseReports\ExpenseReportResource;
use Illuminate\Database\Eloquent\Model;

/** HROne parity RQ-1: expense report (Expense reports → "New expense report"; drafts and reports sent back count as drafts). */
final class ExpenseReportRequestType extends BaseRequestType
{
    public function key(): string
    {
        return 'expense_report';
    }

    public function label(): string
    {
        return 'Expense report';
    }

    public function description(): string
    {
        return 'Put your expenses in a report and send it for approval';
    }

    public function module(): string
    {
        return 'Expense & travel';
    }

    public function icon(): string
    {
        return 'heroicon-o-document-text';
    }

    public function keywords(): array
    {
        return ['expense report', 'expenses', 'claim expenses', 'report'];
    }

    public function permission(): string
    {
        return 'expense.claim';
    }

    public function availableTo(User $user, ?Employee $employee): bool
    {
        return $this->signedIn($user) && $employee !== null && $user->can('expense.claim') && ExpenseReportResource::canAccess();
    }

    public function formUrl(User $user, ?Employee $employee): ?string
    {
        return $this->withAction(ExpenseReportResource::getUrl('index'), 'newReport');
    }

    public function model(): ?string
    {
        return ExpenseReport::class;
    }

    protected function resource(): ?string
    {
        return ExpenseReportResource::class;
    }

    public function statusMap(): array
    {
        return [
            'draft' => RequestStatus::Draft,
            // Sent back to the employee to change and resubmit.
            'returned' => RequestStatus::Draft,
            'submitted' => RequestStatus::Pending,
            'approved' => RequestStatus::Approved,
            'partly_approved' => RequestStatus::Approved,
            'paid' => RequestStatus::Approved,
            'rejected' => RequestStatus::Rejected,
            'cancelled' => RequestStatus::Withdrawn,
        ];
    }

    public function dateFilters(): array
    {
        return [
            'requested' => ['label' => 'Created on', 'column' => 'created_at'],
            'effective' => ['label' => 'Period from', 'column' => 'period_from'],
            'decided' => ['label' => 'Decided on', 'column' => 'decided_at'],
        ];
    }

    public function searchColumns(): array
    {
        return ['number', 'title'];
    }

    public function summary(Model $record): array
    {
        /** @var ExpenseReport $record */
        return [
            'title' => $this->join($record->number, $record->title),
            'detail' => $this->join(trim(($record->currency ?? '').' '.number_format((float) $record->total_claimed, 2)), $this->span($record->period_from, $record->period_to)),
        ];
    }

    // -- approval route (RQ-2) ----------------------------------------------------------------------------------

    public function raisedEvents(): array
    {
        return ['expense.report_submitted'];
    }

    public function workflowSubject(): ?string
    {
        return ExpenseReportPart::class;
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
        return 'expense_report';
    }

    public function knowledgeCategory(): ?string
    {
        return 'expense';
    }

    /** A submitted report is approved as its parts (one per workflow group, plus the exceptional part). */
    public function waitingRecords(Model $record): array
    {
        $parts = $record instanceof ExpenseReport ? ExpenseReportPart::query()->where('expense_report_id', $record->getKey())->orderBy('id')->get()->all() : [];

        return $parts === [] ? [$record] : $parts;
    }
}
