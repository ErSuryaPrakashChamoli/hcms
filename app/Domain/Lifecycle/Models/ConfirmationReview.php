<?php

namespace App\Domain\Lifecycle\Models;

use App\Domain\Audit\Concerns\Auditable;
use App\Domain\Employment\Models\Employee;
use App\Domain\Exit\Models\ExitCase;
use App\Domain\Identity\Concerns\ScopedByEmployee;
use App\Domain\Identity\Models\User;
use App\Support\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A confirmation review (HROne setup parity, Workforce → Confirmation policy): opened ahead of a probation end, decided
 * by confirming, extending the probation within the policy's limits, or terminating. The decision and who took it stay.
 */
#[Fillable(['tenant_id', 'employee_id', 'probation_end_date', 'due_on', 'status', 'extension_months', 'extended_to', 'extension_number', 'assessment', 'decision_note', 'decided_by', 'decided_at', 'exit_case_id'])]
class ConfirmationReview extends Model
{
    use Auditable, BelongsToTenant;
    use ScopedByEmployee;

    public const STATUSES = ['pending' => 'Pending', 'confirmed' => 'Confirmed', 'extended' => 'Probation extended', 'terminated' => 'Terminated', 'cancelled' => 'Cancelled'];

    protected $attributes = ['status' => 'pending', 'extension_number' => 0];

    protected function casts(): array
    {
        return ['probation_end_date' => 'date', 'due_on' => 'date', 'extended_to' => 'date', 'decided_at' => 'datetime', 'extension_months' => 'integer', 'extension_number' => 'integer'];
    }

    public function auditModule(): string
    {
        return 'lifecycle';
    }

    public function auditLabel(): string
    {
        return 'Confirmation review '.$this->probation_end_date?->toDateString();
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function decider(): BelongsTo
    {
        return $this->belongsTo(User::class, 'decided_by');
    }

    public function exitCase(): BelongsTo
    {
        return $this->belongsTo(ExitCase::class);
    }
}
