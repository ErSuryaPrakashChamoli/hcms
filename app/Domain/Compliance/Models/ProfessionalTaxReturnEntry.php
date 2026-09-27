<?php

namespace App\Domain\Compliance\Models;

use App\Domain\Compliance\Concerns\FrozenWithReturn;
use App\Domain\Employment\Models\Employee;
use App\Domain\Identity\Concerns\ScopedByEmployee;
use App\Support\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** One employee's professional tax for the month, with the state and how the state was found. */
#[Fillable(['tenant_id', 'professional_tax_return_id', 'statutory_return_id', 'employee_id', 'payroll_run_id', 'payroll_entry_id', 'member_name', 'state_code', 'state_source', 'calc_gross', 'calc_pt_amount', 'export_pt_amount', 'compliance_rule_id', 'rule_version', 'rule_checksum', 'status', 'issues'])]
class ProfessionalTaxReturnEntry extends Model
{
    use BelongsToTenant, FrozenWithReturn, ScopedByEmployee;

    protected function casts(): array
    {
        return ['issues' => 'array', 'calc_gross' => 'decimal:2', 'calc_pt_amount' => 'decimal:2', 'export_pt_amount' => 'decimal:2'];
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }
}
