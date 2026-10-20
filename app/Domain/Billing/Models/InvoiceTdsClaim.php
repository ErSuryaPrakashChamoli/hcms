<?php

namespace App\Domain\Billing\Models;

use App\Support\Money\Currency;
use App\Support\Money\Money;
use App\Support\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use RuntimeException;

/**
 * SaaS.7 completion (B-11): income-tax TDS a customer in India deducted from an invoice, declared by an operator
 * (never inferred from a short payment) with the amount on the customer's statement, and certified once its
 * certificate reference is recorded. One per invoice. Only its certification is ever recorded afterwards.
 */
#[Fillable(['invoice_id', 'amount_minor', 'currency', 'status', 'certificate_reference', 'reason', 'recorded_by', 'certified_by', 'certified_at'])]
class InvoiceTdsClaim extends Model
{
    use BelongsToTenant;

    public const PENDING = 'pending_certificate';

    public const CERTIFIED = 'certified';

    protected static function booted(): void
    {
        static::updating(function (self $claim): void {
            if (array_diff(array_keys($claim->getDirty()), ['status', 'certificate_reference', 'certified_by', 'certified_at', 'updated_at']) !== []
                || $claim->getRawOriginal('status') !== self::PENDING || $claim->status !== self::CERTIFIED) {
                throw new RuntimeException('A TDS claim keeps its amount: only its certificate can be recorded, once.');
            }
        });
        static::deleting(function (): void {
            throw new RuntimeException('TDS claims are never deleted.');
        });
    }

    protected function casts(): array
    {
        return ['currency' => Currency::class, 'amount_minor' => 'integer', 'certified_at' => 'datetime'];
    }

    public function amount(): Money
    {
        return Money::ofMinor($this->amount_minor, $this->currency);
    }
}
