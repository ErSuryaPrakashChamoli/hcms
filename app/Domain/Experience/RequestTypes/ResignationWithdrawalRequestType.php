<?php

namespace App\Domain\Experience\RequestTypes;

use App\Domain\Employment\Models\Employee;
use App\Domain\Exit\Models\ExitCase;
use App\Domain\Exit\Models\ExitWithdrawalRequest;
use App\Domain\Exit\Services\ExitWithdrawals;
use App\Domain\Experience\Enums\RequestStatus;
use App\Domain\Identity\Models\User;
use App\Filament\Resources\ExitCases\ExitCaseResource;
use Illuminate\Database\Eloquent\Model;
use Throwable;

/**
 * HROne parity WO-11 (§23.6.2, D-WO8): a request to withdraw one's resignation, offered only while the organisation asks
 * withdrawals to be approved (exit.withdrawal.requires_approval; otherwise "Withdraw resignation" on the exit case takes
 * effect at once, as before, and there is no request to follow). The form is the exit case's own "Withdraw resignation".
 */
final class ResignationWithdrawalRequestType extends BaseRequestType
{
    public function key(): string
    {
        return 'resignation_withdrawal';
    }

    public function label(): string
    {
        return 'Withdraw resignation';
    }

    public function description(): string
    {
        return 'Ask to withdraw your resignation';
    }

    public function module(): string
    {
        return 'Separation';
    }

    public function icon(): string
    {
        return 'heroicon-o-arrow-uturn-left';
    }

    public function keywords(): array
    {
        return ['withdraw', 'withdrawal', 'cancel resignation', 'stay', 'retract'];
    }

    public function permission(): string
    {
        return 'exit.resign';
    }

    /** The employee's own open resignation, no withdrawal request waiting, withdrawals needing approval. */
    public function availableTo(User $user, ?Employee $employee): bool
    {
        if (! $this->signedIn($user) || $employee === null || ! $user->can('exit.resign') || ! app(ExitWithdrawals::class)->requiresApproval() || ! ExitCaseResource::canAccess()) {
            return false;
        }
        $case = $this->openResignation($employee);

        return $case !== null && ExitWithdrawalRequest::query()->where('exit_case_id', $case->id)->where('status', 'pending')->doesntExist();
    }

    public function formUrl(User $user, ?Employee $employee): ?string
    {
        $case = $employee !== null ? $this->openResignation($employee) : null;
        if ($case === null) {
            return null;
        }
        try {
            return $this->withAction(ExitCaseResource::getUrl('view', ['record' => $case]), 'withdraw');
        } catch (Throwable) {
            return null;
        }
    }

    public function model(): ?string
    {
        return ExitWithdrawalRequest::class;
    }

    protected function resource(): ?string
    {
        return ExitCaseResource::class;
    }

    protected function with(): array
    {
        return ['exitCase'];
    }

    public function statusMap(): array
    {
        return ['pending' => RequestStatus::Pending, 'approved' => RequestStatus::Approved, 'rejected' => RequestStatus::Rejected, 'cancelled' => RequestStatus::Withdrawn];
    }

    public function dateFilters(): array
    {
        return [
            'requested' => ['label' => 'Requested on', 'column' => 'created_at'],
            'decided' => ['label' => 'Decided on', 'column' => 'decided_at'],
        ];
    }

    public function searchColumns(): array
    {
        return ['reason'];
    }

    public function summary(Model $record): array
    {
        /** @var ExitWithdrawalRequest $record */
        return [
            'title' => $this->join($record->reference(), 'Withdraw resignation', $record->exitCase?->number),
            'detail' => $this->short($record->reason),
        ];
    }

    /** The exit case's page (where the request was raised and is followed), when the person may open it. */
    public function recordUrl(User $user, Model $record): ?string
    {
        /** @var ExitWithdrawalRequest $record */
        $case = $record->exitCase()->first();
        if ($case === null || ! $this->signedIn($user) || ! ExitCaseResource::canAccess()) {
            return null;
        }
        try {
            return $user->can('view', $case) ? ExitCaseResource::getUrl('view', ['record' => $case]) : ExitCaseResource::getUrl('index');
        } catch (Throwable) {
            return null;
        }
    }

    // -- approval route (RQ-2) ----------------------------------------------------------------------------------

    public function raisedEvents(): array
    {
        return ['exit.withdrawal_requested'];
    }

    public function builtInApprovers(): string
    {
        return 'HR (exit cases) in the Approval Center, unless an approval workflow runs on resignation withdrawals';
    }

    public function approverPermissions(): array
    {
        return ['exit.manage'];
    }

    public function approvalKind(Model $record): ?string
    {
        return 'exit_withdrawal';
    }

    public function knowledgeCategory(): ?string
    {
        return 'hr_policy';
    }

    private function openResignation(Employee $employee): ?ExitCase
    {
        return ExitCase::query()->where('employee_id', $employee->getKey())->where('type', 'resignation')->whereIn('status', ExitCase::OPEN)->latest('id')->first();
    }
}
