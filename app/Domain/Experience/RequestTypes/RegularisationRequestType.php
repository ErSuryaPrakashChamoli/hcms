<?php

namespace App\Domain\Experience\RequestTypes;

use App\Domain\Attendance\Models\AttendanceRegularisation;
use App\Domain\Employment\Models\Employee;
use App\Domain\Experience\Enums\RequestStatus;
use App\Domain\Identity\Models\User;
use App\Filament\Pages\Home;
use App\Filament\Resources\AttendanceRegularisations\AttendanceRegularisationResource;
use Illuminate\Database\Eloquent\Model;

/** HROne parity RQ-1: attendance regularisation (Home "Fix my attendance"; on behalf: Regularisations → "Raise on behalf"). */
final class RegularisationRequestType extends BaseRequestType
{
    public function key(): string
    {
        return 'attendance_regularisation';
    }

    public function label(): string
    {
        return 'Attendance regularisation';
    }

    public function description(): string
    {
        return 'Missed punch or wrong time on a day';
    }

    public function module(): string
    {
        return 'Time office';
    }

    public function icon(): string
    {
        return 'heroicon-o-finger-print';
    }

    public function keywords(): array
    {
        return ['attendance', 'regularise', 'regularize', 'ar', 'missed punch', 'fix attendance'];
    }

    public function permission(): string
    {
        return 'attendance.regularise';
    }

    public function family(): ?string
    {
        return 'time_office';
    }

    /** Home's "Fix my attendance" is offered to the signed-in person with an employee record and attendance.regularise. */
    public function availableTo(User $user, ?Employee $employee): bool
    {
        return $this->signedIn($user) && $employee !== null && $user->can('attendance.regularise');
    }

    public function formUrl(User $user, ?Employee $employee): ?string
    {
        return $this->withAction(Home::getUrl(), 'regularise');
    }

    public function onBehalfPermission(): ?string
    {
        return 'attendance.manage';
    }

    public function onBehalfUrl(User $user): ?string
    {
        return $this->signedIn($user) && AttendanceRegularisationResource::canAccess() && $user->can('attendance.manage')
            ? $this->withAction(AttendanceRegularisationResource::getUrl('index'), 'raiseOnBehalf') : null;
    }

    public function model(): ?string
    {
        return AttendanceRegularisation::class;
    }

    protected function resource(): ?string
    {
        return AttendanceRegularisationResource::class;
    }

    public function statusMap(): array
    {
        return [
            'pending' => RequestStatus::Pending,
            'approved' => RequestStatus::Approved,
            'rejected' => RequestStatus::Rejected,
            'cancelled' => RequestStatus::Withdrawn,
        ];
    }

    public function dateFilters(): array
    {
        return [
            'requested' => ['label' => 'Requested on', 'column' => 'created_at'],
            'effective' => ['label' => 'Attendance date', 'column' => 'date'],
            'decided' => ['label' => 'Decided on', 'column' => 'reviewed_at'],
        ];
    }

    public function searchColumns(): array
    {
        return ['reason'];
    }

    public function summary(Model $record): array
    {
        /** @var AttendanceRegularisation $record */
        $types = (array) config('peopleos.attendance.regularisation_types');

        return [
            'title' => $this->join('Regularisation', $record->date?->format('d M Y'), $types[$record->type] ?? null),
            'detail' => $this->short($record->reason),
        ];
    }

    // -- approval route (RQ-2) ----------------------------------------------------------------------------------

    public function raisedEvents(): array
    {
        return ['attendance.regularisation_requested'];
    }

    public function builtInApprovers(): string
    {
        return 'Attendance approvers in the Approval Center (your manager or HR, as your organisation set it up)';
    }

    public function approverPermissions(): array
    {
        return ['attendance.approve', 'attendance.manage'];
    }

    public function approvalKind(Model $record): ?string
    {
        return 'regularisation';
    }

    public function knowledgeCategory(): ?string
    {
        return 'attendance';
    }
}
