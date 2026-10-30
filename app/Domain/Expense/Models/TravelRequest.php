<?php

namespace App\Domain\Expense\Models;

use App\Domain\Audit\Concerns\Auditable;
use App\Domain\Configuration\Concerns\HasCustomFields;
use App\Domain\Employment\Models\Employee;
use App\Domain\Identity\Concerns\ScopedByEmployee;
use App\Domain\Identity\Models\User;
use App\Support\Tenancy\BelongsToTenant;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * HROne setup parity EX-F: a travel request (HROne "Travel plan") — the trip an employee asks to make, from its start to
 * its end date, with its legs (journeys and stays in travel-linked expense categories), the travel form's fields
 * (currency, estimated cost, international travel with visa and passport numbers, attachments, custom fields of the
 * `travel_request` entity) and an optional advance decided with it. It is approved (then `booking` while company bookings
 * are open and `booked` once the travel desk has recorded them), rejected, or cancelled — an approved trip only through
 * approval. Expense receipts, advances and reports point at it; per-trip limits count over it.
 *
 * Owner decision 2026-10-29: a trip whose legs fall in several workflow groups stays one record (workflow_group `mixed`)
 * and is approved by every group — one TravelRequestPart per group, each deciding its own legs; the trip is approved when
 * every part is and rejected when any part is. A trip of one group carries that group and is decided as one (no parts).
 */
#[Fillable([
    'tenant_id', 'number', 'employee_id', 'purpose', 'start_date', 'end_date', 'is_international', 'currency', 'estimated_cost', 'exchange_rate',
    'estimated_cost_base', 'visa_number', 'passport_number', 'attachments', 'backdated_reason', 'advance_amount', 'expense_advance_id', 'workflow_group',
    'status', 'requested_by', 'submitted_at', 'decided_at', 'decided_by', 'decision_note', 'cancel_reason', 'cancel_requested_at', 'cancel_refused_at', 'cancelled_at',
])]
class TravelRequest extends Model
{
    use Auditable, BelongsToTenant, HasCustomFields, ScopedByEmployee;

    public const STATUSES = [
        'submitted' => 'Waiting for approval', 'approved' => 'Approved', 'booking' => 'Approved · booking', 'booked' => 'Approved · booked',
        'rejected' => 'Rejected', 'cancel_requested' => 'Cancellation requested', 'cancelled' => 'Cancelled',
    ];

    /** Approved trips (whatever their bookings): expenses may point at them and they show on the attendance calendar. */
    public const APPROVED = ['approved', 'booking', 'booked'];

    /** Owner decision 2026-10-29: the workflow group of a trip approved by several groups (one part per group). */
    public const MIXED = 'mixed';

    /** Trips that still hold their dates (overlap checks). */
    public const OPEN = ['submitted', 'approved', 'booking', 'booked', 'cancel_requested'];

    protected $attributes = ['status' => 'submitted', 'is_international' => false, 'exchange_rate' => 1];

    protected function casts(): array
    {
        return [
            'start_date' => 'date', 'end_date' => 'date', 'submitted_at' => 'datetime', 'decided_at' => 'datetime', 'cancel_requested_at' => 'datetime', 'cancel_refused_at' => 'datetime', 'cancelled_at' => 'datetime',
            'is_international' => 'boolean', 'estimated_cost' => 'decimal:2', 'exchange_rate' => 'decimal:8', 'estimated_cost_base' => 'decimal:2', 'advance_amount' => 'decimal:2',
            'visa_number' => 'encrypted', 'passport_number' => 'encrypted', 'attachments' => 'array',
        ];
    }

    public function auditLabel(): string
    {
        return 'Travel request '.($this->getAttributes()['number'] ?? '');
    }

    public function auditSensitiveAttributes(): array
    {
        return [...config('peopleos.audit.sensitive_attributes', []), 'visa_number', 'passport_number'];
    }

    public function auditExcludedAttributes(): array
    {
        return [...config('peopleos.audit.ignored_attributes', []), 'attachments'];
    }

    public function isApproved(): bool
    {
        return in_array($this->status, self::APPROVED, true);
    }

    /** Whether the trip covers a day (its start and end dates included). */
    public function covers(CarbonInterface|string $date): bool
    {
        $day = $date instanceof CarbonInterface ? $date->toDateString() : substr((string) $date, 0, 10);

        return $day >= $this->start_date->toDateString() && $day <= $this->end_date->toDateString();
    }

    /** Upcoming, live (travelling today) or completed, for approved trips; null otherwise. */
    public function tripState(?CarbonInterface $today = null): ?string
    {
        if (! $this->isApproved()) {
            return null;
        }
        $day = ($today ?? now())->toDateString();

        return match (true) {
            $day < $this->start_date->toDateString() => 'upcoming',
            $day > $this->end_date->toDateString() => 'completed',
            default => 'live',
        };
    }

    /** "Mumbai, Pune": where the legs go (each place once). */
    public function destinations(): string
    {
        return $this->legs->pluck('to_city')->filter()->unique()->implode(', ');
    }

    /** "TRV-2026-00001 · Mumbai, Pune" */
    public function displayLabel(): string
    {
        $places = $this->relationLoaded('legs') ? $this->destinations() : '';

        return trim(($this->getAttributes()['number'] ?? '').($places !== '' ? ' · '.$places : '').' · '.$this->start_date?->format('j M').'–'.$this->end_date?->format('j M Y'));
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function legs(): HasMany
    {
        return $this->hasMany(TravelRequestLeg::class)->orderBy('sequence')->orderBy('id');
    }

    /** Owner decision 2026-10-29: the approval parts of a trip spanning several workflow groups (none otherwise). */
    public function parts(): HasMany
    {
        return $this->hasMany(TravelRequestPart::class)->orderBy('id');
    }

    /** Whether the trip is approved part by part (several workflow groups). */
    public function hasParts(): bool
    {
        return ($this->getAttributes()['workflow_group'] ?? null) === self::MIXED;
    }

    public function advance(): BelongsTo
    {
        return $this->belongsTo(ExpenseAdvance::class, 'expense_advance_id');
    }

    public function receipts(): HasMany
    {
        return $this->hasMany(ExpenseReceipt::class);
    }

    public function requester(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by');
    }

    public function decider(): BelongsTo
    {
        return $this->belongsTo(User::class, 'decided_by');
    }
}
