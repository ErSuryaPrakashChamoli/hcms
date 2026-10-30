<?php

namespace App\Domain\Experience\RequestTypes;

use App\Domain\Employment\Models\Employee;
use App\Domain\Experience\Enums\RequestStatus;
use App\Domain\Identity\Models\User;
use App\Domain\Leave\Models\LeaveRequest;
use App\Filament\Pages\Home;
use App\Filament\Resources\LeaveRequests\LeaveRequestResource;
use Illuminate\Database\Eloquent\Model;

/** HROne parity RQ-1: leave (Home "Request leave"; on behalf: Leave requests → "Apply on behalf"). */
final class LeaveRequestType extends BaseRequestType
{
    public function key(): string
    {
        return 'leave';
    }

    public function label(): string
    {
        return 'Leave';
    }

    public function description(): string
    {
        return 'Pick dates, see your balance, submit';
    }

    public function module(): string
    {
        return 'Leave';
    }

    public function icon(): string
    {
        return 'heroicon-o-calendar-days';
    }

    public function keywords(): array
    {
        return ['leave', 'apply leave', 'time off', 'holiday', 'vacation', 'sick'];
    }

    public function permission(): string
    {
        return 'leave.apply';
    }

    public function family(): ?string
    {
        return 'time_office';
    }

    /** Home's "Request leave" is offered to the signed-in person with an employee record and leave.apply. */
    public function availableTo(User $user, ?Employee $employee): bool
    {
        return $this->signedIn($user) && $employee !== null && $user->can('leave.apply');
    }

    public function formUrl(User $user, ?Employee $employee): ?string
    {
        return $this->withAction(Home::getUrl(), 'requestLeave');
    }

    public function onBehalfPermission(): ?string
    {
        return 'leave.manage';
    }

    public function onBehalfUrl(User $user): ?string
    {
        return $this->signedIn($user) && LeaveRequestResource::canAccess() && $user->can('leave.manage')
            ? $this->withAction(LeaveRequestResource::getUrl('index'), 'applyOnBehalf') : null;
    }

    public function model(): ?string
    {
        return LeaveRequest::class;
    }

    protected function resource(): ?string
    {
        return LeaveRequestResource::class;
    }

    protected function with(): array
    {
        return ['leaveType'];
    }

    public function statusMap(): array
    {
        return [
            'pending' => RequestStatus::Pending,
            // A cancellation of approved leave waits for its approver.
            'cancel_requested' => RequestStatus::Pending,
            'approved' => RequestStatus::Approved,
            'rejected' => RequestStatus::Rejected,
            'cancelled' => RequestStatus::Withdrawn,
        ];
    }

    public function statusLabel(Model $record): string
    {
        return $this->rawStatus($record) === 'cancel_requested' ? 'Cancellation requested' : parent::statusLabel($record);
    }

    public function dateFilters(): array
    {
        return [
            'requested' => ['label' => 'Requested on', 'column' => 'created_at'],
            'effective' => ['label' => 'Leave from', 'column' => 'from_date'],
            'decided' => ['label' => 'Decided on', 'column' => 'reviewed_at'],
        ];
    }

    public function searchColumns(): array
    {
        return ['reason'];
    }

    public function summary(Model $record): array
    {
        /** @var LeaveRequest $record */
        return [
            'title' => $this->join($record->leaveType?->name ?? 'Leave', $this->span($record->from_date, $record->to_date)),
            'detail' => $this->join($record->days !== null ? rtrim(rtrim(number_format((float) $record->days, 1), '0'), '.').' day(s)' : null, $this->short($record->reason)),
        ];
    }

    // -- approval route (RQ-2) ----------------------------------------------------------------------------------

    public function raisedEvents(): array
    {
        return ['leave.requested'];
    }

    public function builtInApprovers(): string
    {
        return 'Leave approvers in the Approval Center (your manager or HR, as your organisation set it up)';
    }

    public function approverPermissions(): array
    {
        return ['leave.approve'];
    }

    public function approvalKind(Model $record): ?string
    {
        return $this->kindByStatus($record, 'leave', 'leave_cancellation');
    }

    public function knowledgeCategory(): ?string
    {
        return 'leave';
    }
}
