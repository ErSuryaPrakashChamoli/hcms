<?php

namespace App\Filament\Resources\ExpenseReceipts\Pages;

use App\Domain\Expense\Models\ExpenseReceipt;
use App\Filament\Resources\ExpenseReceipts\ExpenseReceiptResource;
use App\Filament\Support\Pages\PeopleViewRecord;
use Illuminate\Contracts\Support\Htmlable;

/**
 * HROne parity XO-2 (matrix 26 §26.4.1 #17): one expense receipt in full, read-only, for whoever may view it (the
 * employee's own, or in the viewer's expense access scope). Changing or withdrawing stays on the register; decisions stay
 * on the report and in the Approval Center.
 */
class ViewExpenseReceipt extends PeopleViewRecord
{
    protected static string $resource = ExpenseReceiptResource::class;

    public function getTitle(): string|Htmlable
    {
        /** @var ExpenseReceipt $receipt */
        $receipt = $this->getRecord();

        return $receipt->auditLabel();
    }

    protected function getHeaderActions(): array
    {
        return [];
    }
}
