<?php

namespace App\Domain\Payroll\Models;

use App\Domain\Audit\Concerns\Auditable;
use App\Domain\Identity\Models\User;
use App\Domain\Organisation\Models\Company;
use App\Support\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * HROne parity PO-22: the statutory bonus run of a company for an accounting year ("2025-26"), prepared only under VERIFIED
 * BONUS rule versions (recorded in `rules`), with the employer's bonus rate used and the payment deadline from the rule.
 * Its lines carry each employee's bonus; see BonusRuns.
 */
#[Fillable(['tenant_id', 'company_id', 'financial_year', 'period_start', 'period_end', 'bonus_rate', 'rate_source', 'rules', 'payment_deadline', 'prepared_by', 'prepared_at', 'recalculated_at'])]
class BonusRun extends Model
{
    use Auditable, BelongsToTenant;

    protected function casts(): array
    {
        return ['period_start' => 'date', 'period_end' => 'date', 'payment_deadline' => 'date', 'bonus_rate' => 'decimal:6', 'rules' => 'array',
            'prepared_at' => 'datetime', 'recalculated_at' => 'datetime'];
    }

    public function auditModule(): string
    {
        return 'payroll';
    }

    public function auditLabel(): string
    {
        return "Bonus {$this->financial_year}";
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function lines(): HasMany
    {
        return $this->hasMany(BonusRunLine::class, 'bonus_run_id');
    }

    public function preparer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'prepared_by');
    }

    /** The employer's bonus rate as a percentage ("8.33"). */
    public function percentLabel(): string
    {
        return rtrim(rtrim(number_format((float) $this->bonus_rate * 100, 2), '0'), '.').'%';
    }
}
