<?php

namespace App\Domain\Compliance\Models;

use App\Domain\Audit\Concerns\Auditable;
use App\Domain\Organisation\Models\LegalEntity;
use App\Support\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Part L: a legal entity's TDS financial year — open/closed and whether its annual ledger is verified. */
#[Fillable(['tenant_id', 'legal_entity_id', 'financial_year', 'start_date', 'end_date', 'status', 'ledger_verified_at', 'ledger_verified_by', 'ledger_checksum', 'closed_at', 'closed_by'])]
class TdsFinancialYear extends Model
{
    use Auditable, BelongsToTenant;

    protected function casts(): array
    {
        return ['start_date' => 'date', 'end_date' => 'date', 'ledger_verified_at' => 'datetime', 'closed_at' => 'datetime'];
    }

    public function auditModule(): string
    {
        return 'compliance';
    }

    public function auditLabel(): string
    {
        return "TDS FY {$this->financial_year}";
    }

    public function legalEntity(): BelongsTo
    {
        return $this->belongsTo(LegalEntity::class);
    }
}
