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

/** One insured person's ESI line: days, wages and contributions from finalized payroll; IP number encrypted and masked. */
#[Fillable(['tenant_id', 'esi_return_run_id', 'statutory_return_id', 'employee_id', 'payroll_run_id', 'payroll_entry_id', 'ip_number', 'ip_number_hash', 'ip_number_last4', 'member_name', 'contribution_period', 'calc_days', 'calc_wages', 'calc_ee_contribution', 'calc_er_contribution', 'export_days', 'export_wages', 'compliance_rule_id', 'rule_version', 'rule_checksum', 'status', 'issues'])]
#[Hidden(['ip_number', 'ip_number_hash'])]
class EsiReturnEntry extends Model
{
    use BelongsToTenant, FrozenWithReturn, ScopedByEmployee;

    protected function casts(): array
    {
        return [
            'ip_number' => 'encrypted',
            'issues' => 'array',
            'calc_days' => 'decimal:2', 'calc_wages' => 'decimal:2', 'calc_ee_contribution' => 'decimal:2', 'calc_er_contribution' => 'decimal:2', 'export_wages' => 'decimal:2',
        ];
    }

    public function maskedIp(): string
    {
        return $this->ip_number_last4 ? '••••••'.$this->ip_number_last4 : '—';
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }
}
