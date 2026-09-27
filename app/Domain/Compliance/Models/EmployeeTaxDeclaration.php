<?php

namespace App\Domain\Compliance\Models;

use App\Domain\Audit\Concerns\Auditable;
use App\Domain\Employment\Models\Employee;
use App\Support\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Regime choice and investment declarations for one financial year (§32 TDS). */
#[Fillable(['tenant_id', 'employee_id', 'financial_year', 'regime', 'declarations', 'previous_employer_income', 'previous_employer_tds', 'status', 'verified_by', 'verified_at'])]
class EmployeeTaxDeclaration extends Model
{
    use Auditable, BelongsToTenant;

    public const STATUSES = ['draft' => 'Draft', 'submitted' => 'Submitted', 'verified' => 'Verified'];

    public const SECTIONS = ['80C' => '80C – PF, PPF, LIC, ELSS, tuition, home loan principal', '80CCD1B' => '80CCD(1B) – NPS (self)', '80CCD2' => '80CCD(2) – NPS (employer)', '80D' => '80D – Medical insurance', '80E' => '80E – Education loan interest', '80G' => '80G – Donations', '80TTA' => '80TTA – Savings interest', 'HRA_RENT' => 'Annual rent paid (HRA exemption)', 'METRO' => 'Lives in a metro city (1 = yes)'];

    protected function casts(): array
    {
        return [
            'declarations' => 'array',
            'previous_employer_income' => 'decimal:2',
            'previous_employer_tds' => 'decimal:2',
            'verified_at' => 'datetime',
        ];
    }

    public function auditModule(): string
    {
        return 'compliance';
    }

    public function auditLabel(): string
    {
        return "Tax declaration {$this->financial_year} ({$this->regime})";
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function amount(string $section): float
    {
        return (float) data_get($this->declarations, $section, 0);
    }
}
