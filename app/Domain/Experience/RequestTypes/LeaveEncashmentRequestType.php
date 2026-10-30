<?php

namespace App\Domain\Experience\RequestTypes;

use App\Domain\Employment\Models\Employee;
use App\Domain\Experience\Enums\RequestStatus;
use App\Domain\Identity\Models\User;
use App\Domain\Leave\Models\LeaveEncashment;
use App\Domain\Leave\Models\LeaveType;
use App\Filament\Resources\MyLeaveEncashments\MyLeaveEncashmentResource;
use Illuminate\Database\Eloquent\Model;

/** HROne parity RQ-1: leave encashment (My encashments → "Request encashment"). */
final class LeaveEncashmentRequestType extends BaseRequestType
{
    public function key(): string
    {
        return 'leave_encashment';
    }

    public function label(): string
    {
        return 'Leave encashment';
    }

    public function description(): string
    {
        return 'Turn unused leave into money, as your leave rules allow';
    }

    public function module(): string
    {
        return 'Leave';
    }

    public function icon(): string
    {
        return 'heroicon-o-banknotes';
    }

    public function keywords(): array
    {
        return ['encash', 'encashment', 'leave encashment'];
    }

    public function permission(): string
    {
        return 'leave.apply';
    }

    /** My encashments opens (leave.apply + an employee record) and offers at least one encashable leave type. */
    public function availableTo(User $user, ?Employee $employee): bool
    {
        return $this->signedIn($user) && $employee !== null && MyLeaveEncashmentResource::canAccess()
            && LeaveType::query()->where('is_encashable', true)->where('status', 'active')->exists();
    }

    public function formUrl(User $user, ?Employee $employee): ?string
    {
        return $this->withAction(MyLeaveEncashmentResource::getUrl('index'), 'requestEncashment');
    }

    public function model(): ?string
    {
        return LeaveEncashment::class;
    }

    protected function resource(): ?string
    {
        return MyLeaveEncashmentResource::class;
    }

    protected function with(): array
    {
        return ['leaveType'];
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
        return ['reason'];
    }

    public function summary(Model $record): array
    {
        /** @var LeaveEncashment $record */
        return [
            'title' => $this->join($record->leaveType?->name ?? 'Leave', rtrim(rtrim(number_format((float) $record->days, 1), '0'), '.').' day(s)', (string) $record->period_year),
            'detail' => $this->short($record->reason),
        ];
    }

    // -- approval route (RQ-2) ----------------------------------------------------------------------------------

    public function raisedEvents(): array
    {
        return ['leave.encashment_requested'];
    }

    public function builtInApprovers(): string
    {
        return 'Leave encashment approvers in the Approval Center (HR)';
    }

    public function approverPermissions(): array
    {
        return ['leave.manage'];
    }

    public function approvalKind(Model $record): ?string
    {
        return 'leave_encashment';
    }

    public function knowledgeCategory(): ?string
    {
        return 'leave';
    }
}
