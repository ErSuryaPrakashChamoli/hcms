<?php

namespace App\Domain\Expense\Models;

use App\Domain\Audit\Concerns\Auditable;
use App\Support\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * HROne setup parity EX-C: the tax a receipt's supplier charged, split by tax head (CGST, SGST …), as printed on the
 * receipt. Recorded, never computed: no rate comes from PeopleOS.
 */
#[Fillable(['tenant_id', 'expense_receipt_id', 'expense_tax_type_id', 'rate', 'amount'])]
class ExpenseReceiptTax extends Model
{
    use Auditable, BelongsToTenant;

    protected function casts(): array
    {
        return ['rate' => 'decimal:2', 'amount' => 'decimal:2'];
    }

    public function auditModule(): string
    {
        return 'expense';
    }

    public function auditLabel(): string
    {
        return 'Tax line of expense receipt #'.($this->getAttributes()['expense_receipt_id'] ?? '');
    }

    public function receipt(): BelongsTo
    {
        return $this->belongsTo(ExpenseReceipt::class, 'expense_receipt_id');
    }

    public function taxType(): BelongsTo
    {
        return $this->belongsTo(ExpenseTaxType::class, 'expense_tax_type_id');
    }
}
