<?php

namespace App\Domain\Exit\Models;

use App\Domain\Audit\Concerns\Auditable;
use App\Domain\Employment\Models\Employee;
use App\Domain\Identity\Concerns\ScopedByEmployee;
use App\Domain\Identity\Models\User;
use App\Support\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * HROne parity WO-11 (§23.6.2, owner decision WRK-3 / D-WO8): a request to withdraw a resignation, raised when the
 * organisation asks withdrawals to be approved (exit.withdrawal.requires_approval; off = withdrawn at once, as before).
 * pending → approved (the resignation is withdrawn through Exits::withdraw) / rejected (the exit goes on) / cancelled
 * (by the requester, or because the exit closed meanwhile). `open_key` ("case:<id>") is set only while pending; unique
 * per tenant, so one open request per exit. Written only by ExitWithdrawals; never deleted.
 */
#[Fillable(['tenant_id', 'exit_case_id', 'employee_id', 'requested_by', 'reason', 'status', 'open_key', 'decided_by', 'decided_at', 'decision_note'])]
class ExitWithdrawalRequest extends Model
{
    use Auditable, BelongsToTenant;
    use ScopedByEmployee;

    public const STATUSES = ['pending' => 'Pending', 'approved' => 'Approved', 'rejected' => 'Rejected', 'cancelled' => 'Cancelled'];

    protected $attributes = ['status' => 'pending', 'decided_by' => null, 'decided_at' => null, 'decision_note' => null];

    protected function casts(): array
    {
        return ['decided_at' => 'datetime'];
    }

    public function auditModule(): string
    {
        return 'exit';
    }

    public function auditLabel(): string
    {
        return 'Withdrawal request '.$this->reference();
    }

    public function auditSensitiveAttributes(): array
    {
        return ['reason', 'decision_note'];
    }

    public function reference(): string
    {
        return 'EWR-'.str_pad((string) $this->getKey(), 6, '0', STR_PAD_LEFT);
    }

    public function exitCase(): BelongsTo
    {
        return $this->belongsTo(ExitCase::class);
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function requester(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by');
    }

    public function decider(): BelongsTo
    {
        return $this->belongsTo(User::class, 'decided_by');
    }

    public function isPending(): bool
    {
        return $this->status === 'pending';
    }
}
