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

/** Annexure-I deductee row of a Form No. 138 statement, from one ledger row; PAN encrypted and masked. */
#[Fillable(['tenant_id', 'tds_quarterly_return_id', 'statutory_return_id', 'employee_id', 'tds_annual_ledger_id', 'pan', 'pan_hash', 'pan_last4', 'member_name', 'section_code', 'payment_date', 'calc_amount_paid', 'calc_tax_deducted', 'export_amount_paid', 'export_tax_deducted', 'reason_code', 'compliance_rule_id', 'rule_version', 'rule_checksum', 'status', 'issues'])]
#[Hidden(['pan', 'pan_hash'])]
class TdsQuarterlyReturnEntry extends Model
{
    use BelongsToTenant, FrozenWithReturn, ScopedByEmployee;

    protected function casts(): array
    {
        return ['pan' => 'encrypted', 'issues' => 'array', 'payment_date' => 'date', 'calc_amount_paid' => 'decimal:2', 'calc_tax_deducted' => 'decimal:2', 'export_amount_paid' => 'decimal:2', 'export_tax_deducted' => 'decimal:2'];
    }

    public function maskedPan(): string
    {
        return $this->pan_last4 ? '••••••'.$this->pan_last4 : 'PAN NOT AVAILABLE';
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }
}
