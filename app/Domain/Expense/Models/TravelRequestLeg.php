<?php

namespace App\Domain\Expense\Models;

use App\Domain\Audit\Concerns\Auditable;
use App\Domain\Identity\Concerns\ScopedByEmployee;
use App\Domain\Identity\Models\User;
use App\Domain\ServiceDesk\Models\Ticket;
use App\Support\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * HROne setup parity EX-F: one leg of a trip — a journey (travel form: mode, from, to, date, return) or a stay
 * (accommodation form: city, check-in, check-out) — in a travel-linked expense category. Who books it follows the
 * category (always the employee, always the company, or either, chosen on the leg). Company legs are booked by the travel
 * desk through a helpdesk request (`booking_ticket_id`); the booking's reference, provider and cost come back here. On a
 * trip spanning several workflow groups the leg belongs to the approval part of its group (`travel_request_part_id`).
 */
#[Fillable([
    'tenant_id', 'travel_request_id', 'travel_request_part_id', 'employee_id', 'sequence', 'kind', 'expense_category_id', 'mode', 'from_city', 'from_state', 'to_city', 'to_state',
    'starts_on', 'ends_on', 'preferred_time', 'booked_by', 'status', 'booking_ticket_id', 'booking_details', 'booking_cost_base', 'booked_at', 'booked_by_user_id', 'notes',
])]
class TravelRequestLeg extends Model
{
    use Auditable, BelongsToTenant, ScopedByEmployee;

    public const KINDS = ['travel' => 'Travel', 'accommodation' => 'Accommodation'];

    public const STATUSES = ['pending' => 'Not booked by the company', 'booking' => 'Booking requested', 'booked' => 'Booked', 'cancelled' => 'Cancelled'];

    protected $attributes = ['status' => 'pending', 'booked_by' => 'employee', 'sequence' => 1];

    protected function casts(): array
    {
        return ['starts_on' => 'date', 'ends_on' => 'date', 'booked_at' => 'datetime', 'booking_details' => 'array', 'booking_cost_base' => 'decimal:2', 'sequence' => 'integer'];
    }

    public function auditLabel(): string
    {
        return 'Travel leg '.($this->getAttributes()['sequence'] ?? '').' ('.($this->getAttributes()['kind'] ?? '').')';
    }

    /** "Air · Delhi → Mumbai" or "Stay · Mumbai" */
    public function summary(): string
    {
        $attributes = $this->getAttributes();

        return ($attributes['kind'] ?? null) === 'accommodation'
            ? 'Stay · '.($attributes['to_city'] ?? '')
            : trim((config('peopleos.expense.travel_modes.'.($attributes['mode'] ?? ''), 'Travel')).' · '.($attributes['from_city'] ?? '').' → '.($attributes['to_city'] ?? ''));
    }

    public function trip(): BelongsTo
    {
        return $this->belongsTo(TravelRequest::class, 'travel_request_id');
    }

    /** Owner decision 2026-10-29: the approval part (workflow group) of a trip spanning several groups. */
    public function part(): BelongsTo
    {
        return $this->belongsTo(TravelRequestPart::class, 'travel_request_part_id');
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(ExpenseCategory::class, 'expense_category_id');
    }

    public function bookingTicket(): BelongsTo
    {
        return $this->belongsTo(Ticket::class, 'booking_ticket_id');
    }

    public function booker(): BelongsTo
    {
        return $this->belongsTo(User::class, 'booked_by_user_id');
    }
}
