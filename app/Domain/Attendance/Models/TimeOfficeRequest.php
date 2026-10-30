<?php

namespace App\Domain\Attendance\Models;

use App\Domain\Audit\Concerns\Auditable;
use App\Domain\Employment\Models\Employee;
use App\Domain\Identity\Concerns\ScopedByEmployee;
use App\Domain\Identity\Models\User;
use App\Support\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * An employee's time-office request (HROne parity HP-1): short leave, on duty, shift change, an overtime
 * claim, a comp-off claim or a restricted holiday. One table, one approval path; what approval does to
 * the attendance day lives in TimeOfficeRequests and is recorded in `effects`.
 */
#[Fillable(['tenant_id', 'employee_id', 'type', 'status', 'from_date', 'to_date', 'session', 'start_time', 'end_time', 'minutes', 'days', 'shift_id', 'swap_date', 'category', 'attachments', 'holiday_id', 'expires_on', 'reason', 'requested_by', 'reviewed_by', 'reviewed_at', 'review_note', 'cancel_reason', 'cancel_requested_at', 'cancelled_at', 'cancelled_by', 'effects'])]
class TimeOfficeRequest extends Model
{
    use Auditable, BelongsToTenant;
    use ScopedByEmployee;

    public const TYPES = [
        'short_leave' => 'Short leave',
        'on_duty' => 'On duty',
        'shift_change' => 'Shift change',
        'overtime' => 'Overtime claim',
        'comp_off' => 'Comp-off claim',
        'restricted_holiday' => 'Restricted holiday',
        // HROne setup parity (TO-3): work a weekly off and take another day off instead.
        'week_off_change' => 'Week-off change',
    ];

    public const STATUSES = ['pending' => 'Pending', 'approved' => 'Approved', 'rejected' => 'Rejected', 'cancel_requested' => 'Cancellation requested', 'cancelled' => 'Cancelled'];

    /** Requests whose effect is live on the attendance day. A cancellation request keeps the effect until decided. */
    public const EFFECTIVE = ['approved', 'cancel_requested'];

    protected $attributes = ['status' => 'pending', 'session' => 'full'];

    protected function casts(): array
    {
        return [
            'from_date' => 'date',
            'to_date' => 'date',
            'expires_on' => 'date',
            'swap_date' => 'date',
            'attachments' => 'array',
            'minutes' => 'integer',
            'days' => 'decimal:2',
            'reviewed_at' => 'datetime',
            'cancel_requested_at' => 'datetime',
            'cancelled_at' => 'datetime',
            'effects' => 'array',
        ];
    }

    public function auditModule(): string
    {
        return 'attendance';
    }

    public function auditLabel(): string
    {
        $span = $this->from_date?->format('d M Y').($this->to_date && ! $this->to_date->equalTo($this->from_date) ? ' – '.$this->to_date->format('d M Y') : '');

        return $this->typeLabel().' '.$span;
    }

    public function auditExcludedAttributes(): array
    {
        return [...config('peopleos.audit.ignored_attributes', []), 'effects'];
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function shift(): BelongsTo
    {
        return $this->belongsTo(Shift::class);
    }

    public function holiday(): BelongsTo
    {
        return $this->belongsTo(Holiday::class);
    }

    public function requester(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by');
    }

    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }

    public function typeLabel(): string
    {
        return self::TYPES[$this->type] ?? ucfirst(str_replace('_', ' ', (string) $this->type));
    }

    public function isEffective(): bool
    {
        return in_array($this->status, self::EFFECTIVE, true);
    }

    /** @param  Builder<TimeOfficeRequest>  $query */
    public function scopeEffective(Builder $query): void
    {
        $query->whereIn('status', self::EFFECTIVE);
    }

    /** @param  Builder<TimeOfficeRequest>  $query */
    public function scopeCovering(Builder $query, Carbon|string $date): void
    {
        $day = Carbon::parse($date)->toDateString();
        $query->whereDate('from_date', '<=', $day)->whereDate('to_date', '>=', $day);
    }

    /** Duration of a part-day request in minutes (short leave, hourly on duty). */
    public function spanMinutes(): int
    {
        if ($this->minutes !== null) {
            return (int) $this->minutes;
        }
        if ($this->start_time && $this->end_time) {
            return max(0, (int) Carbon::parse($this->start_time)->diffInMinutes(Carbon::parse($this->end_time), false));
        }

        return 0;
    }
}
