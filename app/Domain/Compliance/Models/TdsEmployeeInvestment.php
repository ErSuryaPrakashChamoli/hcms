<?php

namespace App\Domain\Compliance\Models;

use App\Domain\Audit\Concerns\Auditable;
use App\Domain\Employment\Models\Employee;
use App\Domain\Identity\Concerns\ScopedByEmployee;
use App\Support\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Part L: an investment / deduction proof against an employee's tax declaration (employee_tax_declarations).
 * Rows filed through the declaration wizard carry an InvestmentCatalogue item_key; details (the landlord) is encrypted.
 */
#[Fillable(['tenant_id', 'employee_id', 'employee_tax_declaration_id', 'financial_year', 'section', 'item_key', 'description', 'details', 'declared_amount', 'proof_amount', 'proof_status', 'employee_document_id', 'verified_by', 'verified_at', 'notes'])]
class TdsEmployeeInvestment extends Model
{
    use Auditable, BelongsToTenant, ScopedByEmployee;

    public const STATUSES = ['pending' => 'Pending', 'verified' => 'Verified', 'rejected' => 'Rejected'];

    protected function casts(): array
    {
        return ['declared_amount' => 'decimal:2', 'proof_amount' => 'decimal:2', 'details' => 'encrypted:array', 'verified_at' => 'datetime'];
    }

    /** @return list<string> */
    public function auditSensitiveAttributes(): array
    {
        return [...config('peopleos.audit.sensitive_attributes', []), 'details'];
    }

    public function auditModule(): string
    {
        return 'compliance';
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function declaration(): BelongsTo
    {
        return $this->belongsTo(EmployeeTaxDeclaration::class, 'employee_tax_declaration_id');
    }
}
