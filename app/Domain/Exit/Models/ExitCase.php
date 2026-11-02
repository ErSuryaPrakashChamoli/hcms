<?php

namespace App\Domain\Exit\Models;

use App\Domain\Audit\Concerns\Auditable;
use App\Domain\Employment\Models\Employee;
use App\Domain\Employment\Models\WorkforceReason;
use App\Domain\Identity\Concerns\ScopedByEmployee;
use App\Domain\Identity\Models\User;
use App\Domain\Workflow\Models\WorkflowInstance;
use App\Support\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

/** One separation (§59): initiated → notice → clearance → settlement → completed (→ alumni); or withdrawn / cancelled. */
#[Fillable(['tenant_id', 'number', 'employee_id', 'type', 'reason', 'status', 'initiated_by', 'initiated_on', 'resignation_accepted_at', 'resignation_accepted_by', 'resignation_date', 'notice_days', 'notice_end_date', 'last_working_day', 'manager_id', 'knowledge_transfer_to', 'knowledge_transfer_notes', 'is_rehire_eligible', 'workforce_reason_id', 'rehire_block_reason_id', 'notes', 'workflow_instance_id', 'clearance_started_at', 'completed_at', 'completed_by', 'alumni_created_at',
    // HROne parity WO-11: the approver's reason, notice relaxation, the rehire comment and the settlement month.
    'approver_reason_id', 'approver_remark', 'notice_relaxation_days', 'rehire_comment', 'settlement_month'])]
class ExitCase extends Model
{
    use Auditable, BelongsToTenant;
    use ScopedByEmployee;

    public const OPEN = ['initiated', 'notice', 'clearance', 'settlement'];

    protected $attributes = ['status' => 'initiated', 'notice_relaxation_days' => 0];

    protected function casts(): array
    {
        return [
            'initiated_on' => 'date', 'resignation_date' => 'date', 'notice_days' => 'integer', 'notice_end_date' => 'date', 'last_working_day' => 'date',
            'is_rehire_eligible' => 'boolean', 'clearance_started_at' => 'datetime', 'completed_at' => 'datetime', 'alumni_created_at' => 'datetime',
            'resignation_accepted_at' => 'datetime',
            'notice_relaxation_days' => 'integer', 'settlement_month' => 'date',
        ];
    }

    public function auditModule(): string
    {
        return 'exit';
    }

    public function auditLabel(): string
    {
        return $this->number;
    }

    public function auditSensitiveAttributes(): array
    {
        return ['reason', 'notes', 'approver_remark', 'rehire_comment'];
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    /** HROne setup parity CA-3: the separation reason chosen from the reason master. */
    public function separationReason(): BelongsTo
    {
        return $this->belongsTo(WorkforceReason::class, 'workforce_reason_id');
    }

    /** HROne setup parity CA-3: why the employee may not be rehired (a blacklist reason). */
    public function rehireBlockReason(): BelongsTo
    {
        return $this->belongsTo(WorkforceReason::class, 'rehire_block_reason_id');
    }

    /** HROne parity WO-11 (§23.6.1 #8, #17): the approver's separation reason, kept apart from the employee's. */
    public function approverReason(): BelongsTo
    {
        return $this->belongsTo(WorkforceReason::class, 'approver_reason_id');
    }

    /** HROne parity WO-11 (§23.6.2, D-WO8): requests to withdraw this resignation (when withdrawals need approval). */
    public function withdrawalRequests(): HasMany
    {
        return $this->hasMany(ExitWithdrawalRequest::class)->orderByDesc('id');
    }

    public function manager(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'manager_id');
    }

    public function knowledgeTransferTo(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'knowledge_transfer_to');
    }

    public function initiator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'initiated_by');
    }

    public function clearances(): HasMany
    {
        return $this->hasMany(ExitClearance::class)->orderBy('sort_order');
    }

    public function interview(): HasOne
    {
        return $this->hasOne(ExitInterview::class);
    }

    public function settlement(): HasOne
    {
        return $this->hasOne(FinalSettlement::class);
    }

    public function workflowInstance(): BelongsTo
    {
        return $this->belongsTo(WorkflowInstance::class);
    }

    public function isOpen(): bool
    {
        return in_array($this->status, self::OPEN, true);
    }

    public function allCleared(): bool
    {
        $clearances = $this->relationLoaded('clearances') ? $this->clearances : $this->clearances()->get();

        return $clearances->isNotEmpty() && $clearances->every(fn (ExitClearance $c) => in_array($c->status, ['cleared', 'na'], true));
    }
}
