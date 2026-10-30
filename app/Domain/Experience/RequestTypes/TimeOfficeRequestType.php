<?php

namespace App\Domain\Experience\RequestTypes;

use App\Domain\Attendance\Models\TimeOfficeRequest;
use App\Domain\Attendance\Services\TimeOfficeRequests;
use App\Domain\Employment\Models\Employee;
use App\Domain\Experience\Enums\RequestStatus;
use App\Domain\Identity\Models\User;
use App\Filament\Resources\TimeOfficeRequests\TimeOfficeRequestResource;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * HROne parity RQ-1: one time-office request kind (Time-office requests → "New request" with the kind chosen; on behalf:
 * "Raise on behalf"). Each kind is its own request type, as in HROne's request list.
 */
abstract class TimeOfficeRequestType extends BaseRequestType
{
    /** The TimeOfficeRequest type this request type stands for. */
    abstract protected function kind(): string;

    public function key(): string
    {
        return 'time_office.'.$this->kind();
    }

    public function module(): string
    {
        return 'Time office';
    }

    public function permission(): string
    {
        return 'attendance.regularise';
    }

    public function family(): ?string
    {
        return 'time_office';
    }

    /** "New request" on Time-office requests (attendance.regularise + an employee record) and the kind on in the policy. */
    public function availableTo(User $user, ?Employee $employee): bool
    {
        return $this->signedIn($user) && $employee !== null && $user->can('attendance.regularise') && TimeOfficeRequestResource::canAccess()
            && app(TimeOfficeRequests::class)->offers($employee, $this->kind());
    }

    public function formUrl(User $user, ?Employee $employee): ?string
    {
        return $this->withAction(TimeOfficeRequestResource::getUrl('index'), 'newRequest', ['type' => $this->kind()]);
    }

    public function onBehalfPermission(): ?string
    {
        return 'attendance.manage';
    }

    public function onBehalfUrl(User $user): ?string
    {
        return $this->signedIn($user) && TimeOfficeRequestResource::canAccess() && $user->can('attendance.manage')
            ? $this->withAction(TimeOfficeRequestResource::getUrl('index'), 'onBehalf', ['type' => $this->kind()]) : null;
    }

    public function model(): ?string
    {
        return TimeOfficeRequest::class;
    }

    protected function resource(): ?string
    {
        return TimeOfficeRequestResource::class;
    }

    protected function with(): array
    {
        return ['shift', 'holiday'];
    }

    public function ownRecords(User $user, ?Employee $employee): ?Builder
    {
        return parent::ownRecords($user, $employee)?->where('time_office_requests.type', $this->kind());
    }

    public function statusMap(): array
    {
        return [
            'pending' => RequestStatus::Pending,
            // An approved request whose cancellation waits for the approver.
            'cancel_requested' => RequestStatus::Pending,
            'approved' => RequestStatus::Approved,
            'rejected' => RequestStatus::Rejected,
            'cancelled' => RequestStatus::Withdrawn,
        ];
    }

    public function dateFilters(): array
    {
        return [
            'requested' => ['label' => 'Requested on', 'column' => 'created_at'],
            'effective' => ['label' => 'Date asked for', 'column' => 'from_date'],
            'decided' => ['label' => 'Decided on', 'column' => 'reviewed_at'],
        ];
    }

    public function searchColumns(): array
    {
        return ['reason', 'category'];
    }

    public function summary(Model $record): array
    {
        /** @var TimeOfficeRequest $record */
        return [
            'title' => $this->join($this->label(), $this->span($record->from_date, $record->to_date), $this->extra($record)),
            'detail' => $this->short($record->reason),
        ];
    }

    /** What the request asks for beyond its dates. */
    protected function extra(TimeOfficeRequest $record): ?string
    {
        return null;
    }

    // -- approval route (RQ-2) ----------------------------------------------------------------------------------

    public function raisedEvents(): array
    {
        return ['attendance.timeoffice_requested'];
    }

    public function builtInApprovers(): string
    {
        return 'Time-office approvers in the Approval Center (your manager or HR, as your organisation set it up)';
    }

    public function approverPermissions(): array
    {
        return ['attendance.approve', 'attendance.manage'];
    }

    public function approvalKind(Model $record): ?string
    {
        return $this->kindByStatus($record, 'time_office', 'time_office_cancellation');
    }

    public function knowledgeCategory(): ?string
    {
        return 'attendance';
    }

    /** Every time-office kind shares the model: a record is of this type when it is of this kind. */
    public function owns(Model $record): bool
    {
        return parent::owns($record) && (string) ($record->getAttributes()['type'] ?? '') === $this->kind();
    }
}
