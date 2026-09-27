<?php

namespace App\Domain\Compliance\Models;

use App\Domain\Compliance\Concerns\FrozenWithReturn;
use App\Domain\Employment\Models\Employee;
use App\Domain\Identity\Concerns\ScopedByEmployee;
use App\Support\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** One employee's labour welfare fund contribution (employee and employer) for the month. */
#[Fillable(['tenant_id', 'lwf_return_id', 'statutory_return_id', 'employee_id', 'payroll_run_id', 'payroll_entry_id', 'member_name', 'state_code', 'calc_gross', 'calc_ee_contribution', 'calc_er_contribution', 'export_ee_contribution', 'export_er_contribution', 'compliance_rule_id', 'rule_version', 'rule_checksum', 'status', 'issues'])]
class LwfReturnEntry extends Model
{
    use BelongsToTenant, FrozenWithReturn, ScopedByEmployee;

    protected function casts(): array
    {
        return ['issues' => 'array', 'calc_gross' => 'decimal:2', 'calc_ee_contribution' => 'decimal:2', 'calc_er_contribution' => 'decimal:2', 'export_ee_contribution' => 'decimal:2', 'export_er_contribution' => 'decimal:2'];
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }
}
