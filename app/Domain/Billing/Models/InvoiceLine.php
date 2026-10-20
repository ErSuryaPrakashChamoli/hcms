<?php

namespace App\Domain\Billing\Models;

use App\Support\Money\Currency;
use App\Support\Money\Money;
use App\Support\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use RuntimeException;

/**
 * SaaS.7: one priced line of an invoice (whole quantity × unit amount, in the invoice's currency; a partial first or
 * last month is that amount × days billed ÷ days in the month, rounded once half up). A generated line names its
 * billing period and freezes the quantity evidence it was priced from. Never changes.
 */
#[Fillable(['invoice_id', 'line_no', 'description', 'tax_category', 'quantity', 'unit_amount_minor', 'amount_minor', 'currency',
    'plan_price_version_id', 'negotiated_price_version_id', 'plan_version_id', 'period_start', 'period_end', 'billing_period_id', 'days_billed', 'days_in_period', 'quantity_evidence'])]
class InvoiceLine extends Model
{
    use BelongsToTenant;

    protected static function booted(): void
    {
        static::updating(function (): void {
            throw new RuntimeException('Invoice lines never change.');
        });
        static::deleting(function (): void {
            throw new RuntimeException('Invoice lines are never deleted.');
        });
    }

    protected function casts(): array
    {
        return ['currency' => Currency::class, 'quantity' => 'integer', 'unit_amount_minor' => 'integer', 'amount_minor' => 'integer',
            'line_no' => 'integer', 'period_start' => 'date', 'period_end' => 'date', 'days_billed' => 'integer', 'days_in_period' => 'integer',
            'quantity_evidence' => 'array'];
    }

    public function unitAmount(): Money
    {
        return Money::ofMinor($this->unit_amount_minor, $this->currency);
    }

    public function amount(): Money
    {
        return Money::ofMinor($this->amount_minor, $this->currency);
    }
}
