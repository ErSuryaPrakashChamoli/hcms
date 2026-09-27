<?php

namespace App\Domain\Compliance\Models;

use App\Domain\Audit\Concerns\Auditable;
use App\Domain\Employment\Models\Employee;
use App\Domain\Identity\Concerns\ScopedByEmployee;
use App\Support\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Part L: an investment / deduction proof against an employee's tax declaration (employee_tax_declarations). */
#[Fillable(['tenant_id', 'employee_id', 'employee_tax_declaration_id', 'financial_year', 'section', 'description', 'declared_amount', 'proof_amount', 'proof_status', 'employee_document_id', 'verified_by', 'verified_at', 'notes'])]
class TdsEmployeeInvestment extends Model
{
    use Auditable, BelongsToTenant, ScopedByEmployee;

    public const STATUSES = ['pending' => 'Pending', 'verified' => 'Verified', 'rejected' => 'Rejected'];

    protected function casts(): array
    {
        return ['declared_amount' => 'decimal:2', 'proof_amount' => 'decimal:2', 'verified_at' => 'datetime'];
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
