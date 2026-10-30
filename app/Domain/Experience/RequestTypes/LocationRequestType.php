<?php

namespace App\Domain\Experience\RequestTypes;

use App\Domain\Attendance\Models\LocationRequest;
use App\Domain\Employment\Models\Employee;
use App\Domain\Experience\Enums\RequestStatus;
use App\Domain\Identity\Models\User;
use App\Filament\Resources\LocationRequests\LocationRequestResource;
use Illuminate\Database\Eloquent\Model;

/** HROne parity RQ-1: location approval (Location requests → "Request a location"). */
final class LocationRequestType extends BaseRequestType
{
    public function key(): string
    {
        return 'location';
    }

    public function label(): string
    {
        return 'Location approval';
    }

    public function description(): string
    {
        return 'Ask for a place to count as a check-in location';
    }

    public function module(): string
    {
        return 'Time office';
    }

    public function icon(): string
    {
        return 'heroicon-o-map-pin';
    }

    public function keywords(): array
    {
        return ['location', 'geo', 'check-in location', 'place', 'location approval'];
    }

    public function permission(): string
    {
        return 'attendance.regularise';
    }

    public function availableTo(User $user, ?Employee $employee): bool
    {
        return $this->signedIn($user) && $employee !== null && $user->can('attendance.regularise') && LocationRequestResource::canAccess();
    }

    public function formUrl(User $user, ?Employee $employee): ?string
    {
        return $this->withAction(LocationRequestResource::getUrl('index'), 'requestLocation');
    }

    public function model(): ?string
    {
        return LocationRequest::class;
    }

    protected function resource(): ?string
    {
        return LocationRequestResource::class;
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
            'effective' => ['label' => 'Needed from', 'column' => 'valid_from'],
            'decided' => ['label' => 'Decided on', 'column' => 'reviewed_at'],
        ];
    }

    public function searchColumns(): array
    {
        return ['name', 'reason'];
    }

    public function summary(Model $record): array
    {
        /** @var LocationRequest $record */
        return [
            'title' => $this->join('Location', $record->name, $record->valid_from ? 'from '.$record->valid_from->format('d M Y') : null),
            'detail' => $this->short($record->reason),
        ];
    }

    // -- approval route (RQ-2) ----------------------------------------------------------------------------------

    public function raisedEvents(): array
    {
        return ['attendance.location_requested'];
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
        return 'location_request';
    }

    public function knowledgeCategory(): ?string
    {
        return 'attendance';
    }
}
