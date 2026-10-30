<?php

namespace App\Domain\Payroll\Models;

use App\Domain\Audit\Concerns\Auditable;
use App\Domain\Employment\Models\Employee;
use App\Domain\Identity\Concerns\ScopedByEmployee;
use App\Domain\Identity\Models\User;
use App\Support\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * HROne setup parity BP-9 (Variable payment schedule): an employee's planned variable pay on one earning component —
 * the months it falls due and the target for each. On `payroll.variable.task_day` the manager is asked for the
 * month's payout; what they enter becomes an ordinary variable pay request (approved and paid as before).
 */
#[Fillable(['tenant_id', 'employee_id', 'salary_component_id', 'name', 'schedule', 'status', 'note', 'created_by', 'closed_at', 'closed_by'])]
class VariablePayPlan extends Model
{
    use Auditable, BelongsToTenant;
    use ScopedByEmployee;

    public const STATUSES = ['active' => 'Active', 'closed' => 'Closed'];

    protected $attributes = ['status' => 'active'];

    protected function casts(): array
    {
        return ['schedule' => 'array', 'closed_at' => 'datetime'];
    }

    public function auditModule(): string
    {
        return 'payroll';
    }

    public function auditLabel(): string
    {
        return 'Variable pay plan '.($this->name ?: '#'.$this->id);
    }

    public function auditSensitiveAttributes(): array
    {
        return ['schedule'];
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function component(): BelongsTo
    {
        return $this->belongsTo(SalaryComponent::class, 'salary_component_id');
    }

    public function requests(): HasMany
    {
        return $this->hasMany(VariablePayRequest::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /** @return array<string, float> month (YYYY-MM) => target, in month order */
    public function targets(): array
    {
        $rows = [];
        foreach ((array) ($this->schedule ?? []) as $row) {
            if (is_array($row) && preg_match('/^\d{4}-\d{2}$/', (string) ($row['month'] ?? ''))) {
                $rows[(string) $row['month']] = round((float) ($row['target'] ?? 0), 2);
            }
        }
        ksort($rows);

        return $rows;
    }
}
