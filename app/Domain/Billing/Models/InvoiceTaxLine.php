<?php

namespace App\Domain\Billing\Models;

use App\Domain\Tax\Enums\TaxRegime;
use App\Domain\Tax\Enums\TaxTreatment;
use App\Support\Money\Currency;
use App\Support\Money\Money;
use App\Support\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use RuntimeException;

/**
 * SaaS.7: one tax component on one invoice line, generic for every regime (CGST, IGST, VAT, state sales tax …):
 * regime, jurisdiction, treatment, rate, taxable base, tax and the verified rule it came from. Written at issue,
 * never changed.
 */
#[Fillable(['invoice_id', 'line_no', 'regime', 'country', 'subdivision', 'tax_type', 'treatment', 'rate', 'taxable_minor', 'tax_minor', 'currency',
    'tax_rule_id', 'metadata'])]
class InvoiceTaxLine extends Model
{
    use BelongsToTenant;

    protected static function booted(): void
    {
        static::updating(function (): void {
            throw new RuntimeException('Invoice tax lines never change.');
        });
        static::deleting(function (): void {
            throw new RuntimeException('Invoice tax lines are never deleted.');
        });
    }

    protected function casts(): array
    {
        return ['regime' => TaxRegime::class, 'treatment' => TaxTreatment::class, 'currency' => Currency::class, 'rate' => 'decimal:4',
            'taxable_minor' => 'integer', 'tax_minor' => 'integer', 'line_no' => 'integer', 'metadata' => 'array'];
    }

    public function tax(): Money
    {
        return Money::ofMinor($this->tax_minor, $this->currency);
    }

    public function taxable(): Money
    {
        return Money::ofMinor($this->taxable_minor, $this->currency);
    }
}
