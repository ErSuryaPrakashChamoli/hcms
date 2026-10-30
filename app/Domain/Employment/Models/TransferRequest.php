<?php

namespace App\Domain\Employment\Models;

use App\Domain\Audit\Concerns\Auditable;
use App\Domain\Identity\Concerns\ScopedByEmployee;
use App\Domain\Identity\Models\User;
use App\Domain\Payroll\Models\SalaryComponent;
use App\Support\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A transfer requested before it happens (HROne setup parity, Workforce → Transfer policy): the position dimensions
 * and manager to change from a date, with a reason; approved by someone else, then applied as a position change.
 */
#[Fillable(['tenant_id', 'employee_id', 'effective_date', 'workforce_reason_id', 'changes', 'new_manager_id', 'allowances', 'allowances_payroll_run_id', 'allowances_paid_at', 'note', 'status', 'requested_by', 'reviewed_by', 'reviewed_at', 'review_note'])]
class TransferRequest extends Model
{
    use Auditable, BelongsToTenant;
    use ScopedByEmployee;

    public const STATUSES = ['pending' => 'Pending', 'approved' => 'Approved', 'rejected' => 'Rejected', 'cancelled' => 'Cancelled'];

    protected $attributes = ['status' => 'pending'];

    protected function casts(): array
    {
        return ['effective_date' => 'date', 'changes' => 'array', 'allowances' => 'array', 'allowances_paid_at' => 'datetime', 'reviewed_at' => 'datetime'];
    }

    public function auditModule(): string
    {
        return 'employment';
    }

    public function auditLabel(): string
    {
        return 'Transfer request from '.$this->effective_date?->toDateString();
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function reason(): BelongsTo
    {
        return $this->belongsTo(WorkforceReason::class, 'workforce_reason_id');
    }

    public function newManager(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'new_manager_id');
    }

    public function requester(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by');
    }

    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }

    /** "Department → Sales, Location → Pune" from the request's changes. */
    public function summary(): string
    {
        return collect($this->getAttribute('changes') ?? [])->map(function ($id, string $dimension) {
            [$relation, $label] = EmployeePosition::DIMENSIONS[$dimension] ?? [null, $dimension];
            $model = $relation ? (new EmployeePosition)->{$relation}()->getRelated()->newQuery()->find($id) : null;

            return $label.' → '.($model?->name ?? $id);
        })->push($this->new_manager_id ? 'Manager → '.$this->newManager?->display_name : null)->push($this->allowanceSummary())->filter()->implode(', ');
    }

    /** HROne setup parity 08.6: "Relocation allowance 25,000.00" for each transfer allowance granted; null when none. */
    public function allowanceSummary(): ?string
    {
        $rows = (array) ($this->allowances ?? []);
        if ($rows === []) {
            return null;
        }
        $names = SalaryComponent::query()->whereIn('id', array_column($rows, 'salary_component_id'))->pluck('name', 'id');

        return collect($rows)->map(fn (array $r) => ($names[$r['salary_component_id']] ?? 'Allowance').' '.number_format((float) $r['amount'], 2))->implode(', ');
    }
}
