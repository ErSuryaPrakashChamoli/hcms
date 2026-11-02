<?php

namespace App\Domain\Leave\Models;

use App\Domain\Audit\Concerns\Auditable;
use App\Domain\Identity\Models\User;
use App\Domain\Leave\Support\PayMonth;
use App\Support\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * HROne parity PO-8 (§25.6.14, "Encash leaves"): HR encashes one leave type for many employees at once — as the leave
 * type's encashment rule allows (TO-7) or a set number of days, never above what the rule allows now. With
 * `payroll.encashment.batch_approval` on (the default) the batch waits for another person's approval; the encashments
 * are created, already approved, only when it is processed (LeaveEncashmentBatches). Statuses: pending (waiting for
 * approval) → processing → processed; rejected / withdrawn before processing; unprocessed once none of its encashments
 * is left (each unprocess cancels the ones not paid yet).
 *
 * @property array{lines?: list<array{employee_id: int, days: float, note?: string}>, skipped?: list<array{employee_id: int, reason: string}>}|null $plan
 */
#[Fillable(['tenant_id', 'leave_type_id', 'period_year', 'pay_month', 'mode', 'specified_days', 'applicability', 'plan', 'results', 'employee_count', 'encashed_count', 'encashed_days', 'status', 'note', 'created_by', 'approved_by', 'approved_at', 'decision_note', 'processed_at', 'unprocessed_by', 'unprocessed_at', 'unprocess_reason'])]
class LeaveEncashmentBatch extends Model
{
    use Auditable, BelongsToTenant;

    public const STATUSES = [
        'pending' => 'Waiting for approval', 'processing' => 'Processing', 'processed' => 'Processed',
        'rejected' => 'Rejected', 'withdrawn' => 'Withdrawn', 'unprocessed' => 'Unprocessed',
    ];

    public const MODES = ['policy' => 'As per policy', 'specified' => 'Specify days'];

    protected function casts(): array
    {
        return [
            'period_year' => 'integer', 'specified_days' => 'decimal:2', 'applicability' => 'array', 'plan' => 'array', 'results' => 'array',
            'employee_count' => 'integer', 'encashed_count' => 'integer', 'encashed_days' => 'decimal:2',
            'approved_at' => 'datetime', 'processed_at' => 'datetime', 'unprocessed_at' => 'datetime',
        ];
    }

    public function auditModule(): string
    {
        return 'leave';
    }

    public function auditLabel(): string
    {
        return 'Encashment batch #'.$this->getKey();
    }

    /** "As per policy" or "3 days". */
    public function encashLabel(): string
    {
        if ($this->mode !== 'specified') {
            return self::MODES['policy'];
        }
        $days = (float) $this->specified_days;

        return rtrim(rtrim(number_format($days, 2), '0'), '.').' '.($days == 1.0 ? 'day' : 'days');
    }

    /** "Nov 2026", or the next pay run when no month was given. */
    public function payMonthLabel(): string
    {
        return PayMonth::label($this->pay_month);
    }

    public function leaveType(): BelongsTo
    {
        return $this->belongsTo(LeaveType::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    public function unprocessor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'unprocessed_by');
    }

    public function encashments(): HasMany
    {
        return $this->hasMany(LeaveEncashment::class, 'leave_encashment_batch_id');
    }

    /** @return list<int> the employees of the reviewed lines */
    public function plannedEmployeeIds(): array
    {
        return array_values(array_map(fn (array $l) => (int) $l['employee_id'], (array) ($this->plan['lines'] ?? [])));
    }
}
