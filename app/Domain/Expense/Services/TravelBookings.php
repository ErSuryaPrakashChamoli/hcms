<?php

namespace App\Domain\Expense\Services;

use App\Domain\Employment\Models\Employee;
use App\Domain\Enterprise\Services\CurrencyRates;
use App\Domain\Expense\Events\ExpenseEvent;
use App\Domain\Expense\Exceptions\ExpenseRuleViolation;
use App\Domain\Expense\Models\ExpenseCategory;
use App\Domain\Expense\Models\TravelRequest;
use App\Domain\Expense\Models\TravelRequestLeg;
use App\Domain\Expense\Policies\TravelRequestPolicy;
use App\Domain\Identity\Models\User;
use App\Domain\Identity\Scopes\AccessScope;
use App\Domain\ServiceDesk\Models\ServiceDefinition;
use App\Domain\ServiceDesk\Models\Ticket;
use App\Domain\ServiceDesk\Models\TicketCategory;
use App\Domain\ServiceDesk\Services\ServiceRequests;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * HROne setup parity EX-F (travel booking through the helpdesk): when a trip is approved, the legs the company books
 * (the category's "who books": always the company, or either and the employee chose the company) are raised for the
 * travel desk as helpdesk requests — in the category's booking service (HROne's "assign a task to a travel booking
 * agent through helpdesk", `booking_service_id`), else in the active request category whose purpose is travel booking
 * (journeys) or accommodation booking (stays); one request per trip and desk. With no desk set up the legs simply wait
 * for the travel desk on the trip. The travel desk (`expense.travel_desk`) records each booking — reference (PNR),
 * provider, cost — on the leg; the employee records their own bookings the same way. When the last company booking is
 * recorded the trip is `booked`.
 */
final class TravelBookings
{
    public function __construct(private readonly CurrencyRates $rates) {}

    /**
     * Raises the company's bookings of an approved trip (inside its approval). Returns how many legs wait for booking.
     */
    public function open(TravelRequest $trip, User $actor): int
    {
        $legs = TravelRequestLeg::query()->withoutGlobalScope(AccessScope::class)->with('category')->where('travel_request_id', $trip->id)
            ->where('booked_by', 'company')->where('status', 'pending')->orderBy('sequence')->get();
        if ($legs->isEmpty()) {
            return 0;
        }
        $employee = Employee::query()->withoutGlobalScope(AccessScope::class)->with('person')->findOrFail($trip->employee_id);
        foreach ($legs->groupBy(fn (TravelRequestLeg $leg) => $this->deskKey($leg->category, $leg->kind)) as $key => $group) {
            $desk = $this->desk($key);
            $ticket = null;
            if ($desk !== null) {
                $lines = $group->map(fn (TravelRequestLeg $l) => '- '.$l->summary().' on '.$l->starts_on->format('j M Y').($l->ends_on ? ' to '.$l->ends_on->format('j M Y') : '')
                    .($l->preferred_time ? ' (around '.$l->preferred_time.')' : '').' · '.$l->category->name)->implode("\n");
                try {
                    $ticket = app(ServiceRequests::class)->raiseForProcess($employee, $actor, $desk, 'Book travel '.$trip->number.' for '.($employee->person?->full_name ?? $employee->employee_code),
                        "Travel request {$trip->number} ({$trip->start_date->format('j M Y')} to {$trip->end_date->format('j M Y')}) was approved. Please book:\n{$lines}\n\nRecord the booking details on the travel request.",
                        mb_substr('travel-'.$trip->id.'-'.$key, 0, 64));
                } catch (RuntimeException) {
                    // The desk is not available now: the legs wait for the travel desk on the trip.
                    $ticket = null;
                }
            }
            foreach ($group as $leg) {
                $leg->withAuditReason('Company booking requested')->update(['status' => 'booking', 'booking_ticket_id' => $ticket?->id]);
            }
        }
        ExpenseEvent::dispatch('expense.travel_booking_requested', $employee, $trip, ['number' => $trip->number, 'legs' => $legs->count(),
            'start_date' => $trip->start_date->toDateString(), 'end_date' => $trip->end_date->toDateString()], $this->deskUsers(TravelRequestLeg::query()->withoutGlobalScope(AccessScope::class)
                ->where('travel_request_id', $trip->id)->whereNotNull('booking_ticket_id')->pluck('booking_ticket_id')->all()));

        return $legs->count();
    }

    /**
     * Records a booking on a leg: the travel desk for a company leg (in scope, never its own trip), the employee for a leg
     * they book themselves. Cost in any currency, kept in the base currency at today's rate.
     *
     * @param  array<string, mixed>  $details  reference, provider, cost, currency, notes
     */
    public function record(TravelRequestLeg $leg, array $details, ?User $actor = null): TravelRequestLeg
    {
        $actor ??= auth()->user();
        $reference = trim((string) ($details['reference'] ?? ''));
        if ($reference === '' || mb_strlen($reference) > 64) {
            throw new ExpenseRuleViolation('Give the booking reference (PNR or confirmation number, at most 64 characters).');
        }
        $base = $this->rates->baseCurrency();
        $currency = strtoupper(trim((string) ($details['currency'] ?? ''))) ?: $base;
        if (! preg_match('/^[A-Z]{3}$/', $currency)) {
            throw new ExpenseRuleViolation('Give the currency as a three-letter code, e.g. INR or USD.');
        }
        $cost = is_numeric($details['cost'] ?? null) ? round((float) $details['cost'], 2) : null;
        if ($cost !== null && $cost < 0) {
            throw new ExpenseRuleViolation('The booking cost cannot be negative.');
        }
        $costBase = null;
        if ($cost !== null) {
            try {
                $costBase = round($cost * $this->rates->rate($currency, $base, now()), 2);
            } catch (RuntimeException) {
                throw new ExpenseRuleViolation("There is no exchange rate from {$currency} to {$base} today. Ask HR to add it under Exchange rates.");
            }
        }

        return DB::transaction(function () use ($leg, $actor, $reference, $currency, $cost, $costBase, $details) {
            $trip = TravelRequest::query()->withoutGlobalScope(AccessScope::class)->whereKey($leg->travel_request_id)->lockForUpdate()->firstOrFail();
            $locked = TravelRequestLeg::query()->withoutGlobalScope(AccessScope::class)->whereKey($leg->getKey())->lockForUpdate()->firstOrFail();
            if ($actor === null || ! $this->canRecord($actor, $locked)) {
                throw new ExpenseRuleViolation('You cannot record the booking of this leg.');
            }
            if (! $trip->isApproved()) {
                throw new ExpenseRuleViolation('Bookings are recorded on approved travel requests.');
            }
            if ($locked->status === 'cancelled') {
                throw new ExpenseRuleViolation('This leg is cancelled.');
            }
            $locked->withAuditReason('Booking recorded: '.$reference)->update([
                'status' => 'booked', 'booked_at' => now(), 'booked_by_user_id' => $actor->id, 'booking_cost_base' => $costBase,
                'booking_details' => array_filter(['reference' => $reference, 'provider' => filled($details['provider'] ?? null) ? mb_substr(trim((string) $details['provider']), 0, 120) : null,
                    'cost' => $cost, 'currency' => $cost !== null ? $currency : null, 'notes' => filled($details['notes'] ?? null) ? mb_substr(trim((string) $details['notes']), 0, 500) : null], fn ($v) => $v !== null),
            ]);
            $open = TravelRequestLeg::query()->withoutGlobalScope(AccessScope::class)->where('travel_request_id', $trip->id)->where('booked_by', 'company')->where('status', 'booking')->exists();
            if (! $open && $trip->status === 'booking') {
                $trip->withAuditReason('Company bookings recorded')->update(['status' => 'booked']);
            }
            $employee = Employee::query()->withoutGlobalScope(AccessScope::class)->findOrFail($trip->employee_id);
            ExpenseEvent::dispatch('expense.travel_booked', $employee, $trip, ['number' => $trip->number, 'leg' => $locked->summary(), 'reference' => $reference,
                'starts_on' => $locked->starts_on->toDateString()], (int) $employee->user_id === (int) $actor->id ? [] : array_filter([$employee->user_id]));

            return $locked->refresh();
        });
    }

    /** The travel desk records company bookings; the employee records the legs they book themselves. */
    public function canRecord(User $user, TravelRequestLeg $leg): bool
    {
        if ($leg->booked_by === 'company') {
            return app(TravelRequestPolicy::class)->book($user, $leg);
        }

        return (int) Employee::query()->withoutGlobalScope(AccessScope::class)->whereKey($leg->employee_id)->value('user_id') === (int) $user->id;
    }

    /**
     * The people working these booking requests (their assignees), for the in-app note when no rule is set up.
     *
     * @param  list<int>  $ticketIds
     * @return list<int>
     */
    public function deskUsers(array $ticketIds): array
    {
        if ($ticketIds === []) {
            return [];
        }

        return Ticket::query()->withoutGlobalScope(AccessScope::class)->whereKey(array_values(array_unique($ticketIds)))->whereNotNull('assignee_id')
            ->pluck('assignee_id')->map(fn ($id) => (int) $id)->unique()->values()->all();
    }

    /** "service:<id>" (the category's booking service), "category:<purpose>" or "desk" (none). */
    private function deskKey(ExpenseCategory $category, string $kind): string
    {
        if ($category->booking_service_id !== null) {
            return 'service:'.$category->booking_service_id;
        }

        return 'category:'.($kind === 'accommodation' ? 'accommodation_booking' : 'travel_booking');
    }

    private function desk(string $key): ServiceDefinition|TicketCategory|null
    {
        [$type, $value] = explode(':', $key, 2);
        if ($type === 'service') {
            $service = ServiceDefinition::query()->find((int) $value);
            if ($service !== null && $service->isActive()) {
                return $service;
            }
            $value = 'travel_booking';
        }

        return TicketCategory::query()->where('purpose', $value)->where('status', 'active')->orderBy('sort_order')->orderBy('id')->first()
            ?? ($value === 'accommodation_booking' ? TicketCategory::query()->where('purpose', 'travel_booking')->where('status', 'active')->orderBy('sort_order')->orderBy('id')->first() : null);
    }
}
