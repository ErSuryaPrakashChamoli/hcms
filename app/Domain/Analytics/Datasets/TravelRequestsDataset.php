<?php

namespace App\Domain\Analytics\Datasets;

use App\Domain\Expense\Models\ExpenseReceipt;
use App\Domain\Expense\Models\TravelRequest;
use Illuminate\Database\Eloquent\Builder;

/**
 * HROne setup parity EX-F: travel requests (trips) — dates, places, international travel, estimated cost, the advance
 * asked for with the trip, approval, bookings, what was claimed against the trip, and whether it is upcoming, live
 * (travelling today) or completed. HROne's "Live trip" and "Travel summary" reports read it. Visa and passport numbers
 * are never part of it.
 */
class TravelRequestsDataset extends Dataset
{
    public function key(): string
    {
        return 'travel_requests';
    }

    public function label(): string
    {
        return 'Travel requests (trips)';
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
        $claimed = fn ($t) => $t->receipts->whereIn('status', ExpenseReceipt::COUNTING);

        return [
            'number' => ['label' => 'Travel request', 'type' => 'string', 'value' => fn ($t) => $t->number],
            ...$this->employeeFields(fn ($t) => $t->employee),
            'purpose' => ['label' => 'Purpose', 'type' => 'string', 'value' => fn ($t) => $t->purpose],
            'start_date' => ['label' => 'Starts on', 'type' => 'date', 'value' => fn ($t) => $t->start_date],
            'end_date' => ['label' => 'Ends on', 'type' => 'date', 'value' => fn ($t) => $t->end_date],
            'month' => ['label' => 'Month of travel', 'type' => 'string', 'value' => fn ($t) => $t->start_date?->format('Y-m')],
            'days' => ['label' => 'Days', 'type' => 'number', 'value' => fn ($t) => (int) $t->start_date->diffInDays($t->end_date) + 1],
            'destinations' => ['label' => 'Destinations', 'type' => 'string', 'value' => fn ($t) => $t->destinations()],
            'is_international' => ['label' => 'International', 'type' => 'boolean', 'value' => fn ($t) => (bool) $t->is_international],
            'legs' => ['label' => 'Legs', 'type' => 'number', 'value' => fn ($t) => $t->legs->count()],
            'journeys' => ['label' => 'Journeys', 'type' => 'number', 'value' => fn ($t) => $t->legs->where('kind', 'travel')->count()],
            'stays' => ['label' => 'Stays', 'type' => 'number', 'value' => fn ($t) => $t->legs->where('kind', 'accommodation')->count()],
            'currency' => ['label' => 'Currency', 'type' => 'string', 'value' => fn ($t) => $t->currency],
            'estimated_cost' => ['label' => 'Estimated cost (that currency)', 'type' => 'number', 'value' => fn ($t) => $t->estimated_cost !== null ? (float) $t->estimated_cost : null],
            'estimated_cost_base' => ['label' => 'Estimated cost', 'type' => 'number', 'value' => fn ($t) => $t->estimated_cost_base !== null ? (float) $t->estimated_cost_base : null],
            'advance' => ['label' => 'Advance', 'type' => 'string', 'value' => fn ($t) => $t->advance?->number],
            'advance_amount' => ['label' => 'Advance asked for (trip currency)', 'type' => 'number', 'value' => fn ($t) => $t->advance_amount !== null ? (float) $t->advance_amount : null],
            'booked_cost' => ['label' => 'Booked by the company (cost)', 'type' => 'number', 'value' => fn ($t) => round((float) $t->legs->where('status', 'booked')->sum('booking_cost_base'), 2)],
            'bookings_open' => ['label' => 'Company bookings to make', 'type' => 'number', 'value' => fn ($t) => $t->legs->where('status', 'booking')->count()],
            'status' => ['label' => 'Status', 'type' => 'string', 'value' => fn ($t) => TravelRequest::STATUSES[$t->status] ?? $t->status],
            'trip_state' => ['label' => 'Trip', 'type' => 'string', 'value' => fn ($t) => match ($t->tripState()) {
                'live' => 'Live', 'upcoming' => 'Upcoming', 'completed' => 'Completed', default => null,
            }],
            'backdated' => ['label' => 'Backdated', 'type' => 'boolean', 'value' => fn ($t) => $t->backdated_reason !== null],
            'submitted_on' => ['label' => 'Requested on', 'type' => 'date', 'value' => fn ($t) => $t->submitted_at],
            'decided_on' => ['label' => 'Decided on', 'type' => 'date', 'value' => fn ($t) => $t->decided_at],
            'decided_by' => ['label' => 'Decided by', 'type' => 'string', 'value' => fn ($t) => $t->decider?->name],
            'expenses' => ['label' => 'Expenses claimed against the trip', 'type' => 'number', 'value' => fn ($t) => $claimed($t)->count()],
            'claimed_amount' => ['label' => 'Claimed against the trip', 'type' => 'number', 'value' => fn ($t) => round((float) $claimed($t)->sum(fn ($r) => (float) $r->claimable_amount), 2)],
            'approved_amount' => ['label' => 'Approved against the trip', 'type' => 'number', 'value' => fn ($t) => round((float) $claimed($t)->sum(fn ($r) => (float) ($r->approved_amount ?? 0)), 2)],
            'count' => ['label' => 'Trips (1 per row)', 'type' => 'number', 'value' => fn ($t) => 1],
        ];
    }

    public function query(): Builder
    {
        return TravelRequest::query()->with([...self::employeeWith('employee'), 'legs', 'advance', 'decider', 'receipts'])->orderByDesc('start_date')->orderByDesc('id');
    }
}
