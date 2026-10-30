<?php

namespace App\Domain\Experience\RequestTypes;

use App\Domain\Employment\Models\Employee;
use App\Domain\Expense\Models\TravelRequest;
use App\Domain\Expense\Models\TravelRequestPart;
use App\Domain\Experience\Enums\RequestStatus;
use App\Domain\Identity\Models\User;
use App\Filament\Resources\TravelRequests\TravelRequestResource;
use Illuminate\Database\Eloquent\Model;

/** HROne parity RQ-1: travel (Travel requests → "Request travel"; for an employee: "Request travel for an employee"). */
final class TravelRequestType extends BaseRequestType
{
    public function key(): string
    {
        return 'travel';
    }

    public function label(): string
    {
        return 'Travel';
    }

    public function description(): string
    {
        return 'A trip with its journeys, stays and estimated cost';
    }

    public function module(): string
    {
        return 'Expense & travel';
    }

    public function icon(): string
    {
        return 'heroicon-o-paper-airplane';
    }

    public function keywords(): array
    {
        return ['travel', 'trip', 'business trip', 'flight', 'hotel'];
    }

    public function permission(): string
    {
        return 'expense.claim';
    }

    public function availableTo(User $user, ?Employee $employee): bool
    {
        return $this->signedIn($user) && $employee !== null && $user->can('expense.claim') && TravelRequestResource::canAccess();
    }

    public function formUrl(User $user, ?Employee $employee): ?string
    {
        return $this->withAction(TravelRequestResource::getUrl('index'), 'requestTravel');
    }

    public function onBehalfPermission(): ?string
    {
        return 'expense.approve';
    }

    public function onBehalfUrl(User $user): ?string
    {
        return $this->signedIn($user) && TravelRequestResource::canAccess() && $user->can('expense.approve')
            ? $this->withAction(TravelRequestResource::getUrl('index'), 'requestForEmployee') : null;
    }

    public function model(): ?string
    {
        return TravelRequest::class;
    }

    protected function resource(): ?string
    {
        return TravelRequestResource::class;
    }

    public function statusMap(): array
    {
        return [
            'submitted' => RequestStatus::Pending,
            'cancel_requested' => RequestStatus::Pending,
            'approved' => RequestStatus::Approved,
            'booking' => RequestStatus::Approved,
            'booked' => RequestStatus::Approved,
            'rejected' => RequestStatus::Rejected,
            'cancelled' => RequestStatus::Withdrawn,
        ];
    }

    public function dateFilters(): array
    {
        return [
            'requested' => ['label' => 'Requested on', 'column' => 'created_at'],
            'effective' => ['label' => 'Travel from', 'column' => 'start_date'],
            'decided' => ['label' => 'Decided on', 'column' => 'decided_at'],
        ];
    }

    public function searchColumns(): array
    {
        return ['number', 'purpose'];
    }

    public function summary(Model $record): array
    {
        /** @var TravelRequest $record */
        return [
            'title' => $this->join($record->number, $this->span($record->start_date, $record->end_date)),
            'detail' => $this->short($record->purpose),
        ];
    }

    // -- approval route (RQ-2) ----------------------------------------------------------------------------------

    public function raisedEvents(): array
    {
        return ['expense.travel_requested'];
    }

    public function builtInApprovers(): string
    {
        return 'Travel approvers in the Approval Center';
    }

    public function approverPermissions(): array
    {
        return ['expense.approve'];
    }

    public function approvalKind(Model $record): ?string
    {
        return $this->kindByStatus($record, 'travel_request', 'travel_cancellation');
    }

    public function knowledgeCategory(): ?string
    {
        return 'travel';
    }

    /** A trip spanning several workflow groups is approved part by part as well. */
    public function waitingRecords(Model $record): array
    {
        if (! $record instanceof TravelRequest || ! $record->hasParts()) {
            return [$record];
        }

        return [$record, ...TravelRequestPart::query()->where('travel_request_id', $record->getKey())->orderBy('id')->get()->all()];
    }
}
