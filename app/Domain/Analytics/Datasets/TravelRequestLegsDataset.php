<?php

namespace App\Domain\Analytics\Datasets;

use App\Domain\Expense\Models\TravelRequest;
use App\Domain\Expense\Models\TravelRequestLeg;
use Illuminate\Database\Eloquent\Builder;

/**
 * HROne setup parity EX-F: the legs of travel requests — journeys (mode, from, to, date, return) and stays (city,
 * check-in, check-out, nights) — with who books them and the booking (reference, provider, cost, helpdesk request).
 * HROne's "Travel details" and "Accommodation details" reports read it.
 */
class TravelRequestLegsDataset extends Dataset
{
    public function key(): string
    {
        return 'travel_request_legs';
    }

    public function label(): string
    {
        return 'Travel and accommodation details (trip legs)';
    }

    public function category(): string
    {
        return 'Expense & travel';
    }

    public function permissions(): array
    {
        return ['expense.view'];
    }

    public function fields(): array
    {
        $details = fn ($l, string $key) => is_array($l->booking_details) ? ($l->booking_details[$key] ?? null) : null;

        return [
            'trip' => ['label' => 'Travel request', 'type' => 'string', 'value' => fn ($l) => $l->trip?->number],
            ...$this->employeeFields(fn ($l) => $l->trip?->employee),
            'trip_status' => ['label' => 'Trip status', 'type' => 'string', 'value' => fn ($l) => $l->trip ? (TravelRequest::STATUSES[$l->trip->status] ?? $l->trip->status) : null],
            'sequence' => ['label' => 'Leg', 'type' => 'number', 'value' => fn ($l) => (int) $l->sequence],
            'kind' => ['label' => 'Kind', 'type' => 'string', 'value' => fn ($l) => TravelRequestLeg::KINDS[$l->kind] ?? $l->kind],
            'category' => ['label' => 'Category', 'type' => 'string', 'value' => fn ($l) => $l->category?->name],
            'mode' => ['label' => 'Mode', 'type' => 'string', 'value' => fn ($l) => $l->mode ? config('peopleos.expense.travel_modes.'.$l->mode, $l->mode) : null],
            'from_city' => ['label' => 'From', 'type' => 'string', 'value' => fn ($l) => $l->from_city],
            'from_state' => ['label' => 'From state', 'type' => 'string', 'value' => fn ($l) => $l->from_state],
            'to_city' => ['label' => 'To / stay city', 'type' => 'string', 'value' => fn ($l) => $l->to_city],
            'to_state' => ['label' => 'To / stay state', 'type' => 'string', 'value' => fn ($l) => $l->to_state],
            'starts_on' => ['label' => 'Travel date / check-in', 'type' => 'date', 'value' => fn ($l) => $l->starts_on],
            'ends_on' => ['label' => 'Return / check-out', 'type' => 'date', 'value' => fn ($l) => $l->ends_on],
            'nights' => ['label' => 'Nights', 'type' => 'number', 'value' => fn ($l) => $l->kind === 'accommodation' && $l->ends_on ? (int) $l->starts_on->diffInDays($l->ends_on) : null],
            'preferred_time' => ['label' => 'Preferred time', 'type' => 'string', 'value' => fn ($l) => $l->preferred_time],
            'booked_by' => ['label' => 'Booked by', 'type' => 'string', 'value' => fn ($l) => $l->booked_by === 'company' ? 'Company' : 'Employee'],
            'booking_status' => ['label' => 'Booking', 'type' => 'string', 'value' => fn ($l) => TravelRequestLeg::STATUSES[$l->status] ?? $l->status],
            'booking_reference' => ['label' => 'Booking reference', 'type' => 'string', 'value' => fn ($l) => $details($l, 'reference')],
            'provider' => ['label' => 'Provider', 'type' => 'string', 'value' => fn ($l) => $details($l, 'provider')],
            'booking_cost' => ['label' => 'Booking cost', 'type' => 'number', 'value' => fn ($l) => $l->booking_cost_base !== null ? (float) $l->booking_cost_base : null],
            'booking_ticket' => ['label' => 'Helpdesk request', 'type' => 'string', 'value' => fn ($l) => $l->bookingTicket?->number],
            'booked_on' => ['label' => 'Booked on', 'type' => 'date', 'value' => fn ($l) => $l->booked_at],
            'count' => ['label' => 'Legs (1 per row)', 'type' => 'number', 'value' => fn ($l) => 1],
        ];
    }

    public function query(): Builder
    {
        return TravelRequestLeg::query()->with([...self::employeeWith('trip.employee'), 'category', 'bookingTicket'])->orderByDesc('starts_on')->orderBy('id');
    }
}
