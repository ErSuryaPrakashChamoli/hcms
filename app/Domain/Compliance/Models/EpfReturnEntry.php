<?php

namespace App\Domain\Compliance\Models;

use App\Domain\Compliance\Concerns\FrozenWithReturn;
use App\Domain\Employment\Models\Employee;
use App\Domain\Identity\Concerns\ScopedByEmployee;
use App\Support\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One member line of an EPF return. `calc_*` are the bases taken from finalized payroll lines;
 * `export_*` are the integer values written to the ECR file. The UAN is encrypted, hashed for
 * the per-return uniqueness check and shown masked.
 */
#[Fillable(['tenant_id', 'epf_return_run_id', 'statutory_return_id', 'employee_id', 'payroll_run_id', 'payroll_entry_id', 'uan', 'uan_hash', 'uan_last4', 'member_name', 'calc_gross_wages', 'calc_epf_wages', 'calc_eps_wages', 'calc_edli_wages', 'calc_ee_share', 'calc_eps_share', 'calc_er_share', 'calc_ncp_days', 'calc_refund_of_advances', 'export_gross_wages', 'export_epf_wages', 'export_eps_wages', 'export_edli_wages', 'export_ee_share', 'export_eps_share', 'export_er_share', 'export_ncp_days', 'export_refund_of_advances', 'compliance_rule_id', 'rule_version', 'rule_checksum', 'status', 'issues'])]
#[Hidden(['uan', 'uan_hash'])]
class EpfReturnEntry extends Model
{
    use BelongsToTenant, FrozenWithReturn, ScopedByEmployee;

    protected function casts(): array
    {
        return [
            'uan' => 'encrypted',
            'issues' => 'array',
            'calc_gross_wages' => 'decimal:2', 'calc_epf_wages' => 'decimal:2', 'calc_eps_wages' => 'decimal:2', 'calc_edli_wages' => 'decimal:2',
            'calc_ee_share' => 'decimal:2', 'calc_eps_share' => 'decimal:2', 'calc_er_share' => 'decimal:2', 'calc_ncp_days' => 'decimal:2', 'calc_refund_of_advances' => 'decimal:2',
        ];
    }

    public function maskedUan(): string
    {
        return $this->uan_last4 ? '••••••••'.$this->uan_last4 : '—';
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function run(): BelongsTo
    {
        return $this->belongsTo(EpfReturnRun::class, 'epf_return_run_id');
    }
}
