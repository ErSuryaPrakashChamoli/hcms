<?php

namespace App\Domain\Payroll\Models;

use App\Domain\Audit\Concerns\Auditable;
use App\Domain\Identity\Models\User;
use App\Domain\Organisation\Models\Company;
use App\Support\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Streamline S2 (decision ST-3): one pre-payroll check ticked for a company's pay month — who checked it, when, and what
 * it counted then (seen_signature). PayrollRunChecklist treats a tick as lapsed once the item no longer counts the same.
 */
#[Fillable(['tenant_id', 'company_id', 'year', 'month', 'check_key', 'seen_count', 'seen_signature', 'checked_by', 'checked_at', 'note'])]
class PayrollPrerunCheck extends Model
{
    use Auditable, BelongsToTenant;

    protected function casts(): array
    {
        return ['year' => 'integer', 'month' => 'integer', 'seen_count' => 'integer', 'checked_at' => 'datetime'];
    }

    public function auditModule(): string
    {
        return 'payroll';
    }

    public function auditLabel(): string
    {
        return 'Pre-payroll check '.($this->getAttributes()['check_key'] ?? '');
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function checker(): BelongsTo
    {
        return $this->belongsTo(User::class, 'checked_by');
    }
}
