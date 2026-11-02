<?php

namespace App\Domain\Compensation\Models;

use App\Domain\Audit\Concerns\Auditable;
use App\Domain\Employment\Models\Employee;
use App\Domain\Identity\Concerns\ScopedByEmployee;
use App\Domain\Identity\Models\User;
use App\Support\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * HROne parity PO-17 (matrix 25 §25.14 #5): a manager's proposed annual CTC for one person of their team on a shared
 * increment plan. It changes nothing by itself: the plan's preparer accepts it (the line is adjusted through
 * CompensationChanges, still reviewed, approved and executed by other people) or declines it. A recalculation or a
 * submission of the plan lapses the proposals still open.
 */
#[Fillable(['tenant_id', 'compensation_cycle_id', 'compensation_change_id', 'employee_id', 'proposed_by', 'ctc_annual', 'base_ctc_annual', 'increase_percent', 'note', 'status', 'decided_by', 'decided_at', 'decision_note'])]
class CompensationCycleProposal extends Model
{
    use Auditable, BelongsToTenant;
    use ScopedByEmployee;

    public const STATUSES = ['proposed' => 'Proposed', 'accepted' => 'Accepted', 'declined' => 'Declined', 'lapsed' => 'Lapsed'];

    protected $attributes = ['status' => 'proposed'];

    protected function casts(): array
    {
        return ['ctc_annual' => 'decimal:2', 'base_ctc_annual' => 'decimal:2', 'increase_percent' => 'decimal:2', 'decided_at' => 'datetime'];
    }

    public function auditModule(): string
    {
        return 'compensation';
    }

    public function auditLabel(): string
    {
        return 'Increment proposal';
    }

    public function auditSensitiveAttributes(): array
    {
        return ['ctc_annual', 'base_ctc_annual', 'increase_percent', 'note', 'decision_note'];
    }

    public function increase(): float
    {
        return round((float) $this->ctc_annual - (float) $this->base_ctc_annual, 2);
    }

    public function cycle(): BelongsTo
    {
        return $this->belongsTo(CompensationCycle::class, 'compensation_cycle_id');
    }

    public function line(): BelongsTo
    {
        return $this->belongsTo(CompensationChange::class, 'compensation_change_id');
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function proposer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'proposed_by');
    }
}
