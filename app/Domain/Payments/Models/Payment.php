<?php

namespace App\Domain\Payments\Models;

use App\Domain\Billing\Models\Invoice;
use App\Domain\Payments\Enums\PaymentMethod;
use App\Domain\Payments\Enums\PaymentStatus;
use App\Domain\Payments\Enums\ReconciliationStatus;
use App\Support\Money\Currency;
use App\Support\Money\Money;
use App\Support\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use RuntimeException;

/**
 * SaaS.7: one attempt to pay one invoice through one provider (tenant-owned). Its amount and currency are the
 * invoice's and never change; its provider reference is written once; its status only moves forward; its
 * reconciliation moves from unreconciled to matched or exception, and an exception to resolved (an approved
 * acceptance or write-off) or matched (a declared TDS makes it exact). The settlement snapshot (B-14: what the
 * provider or bank credited Markedge, usually INR, and the implied rate) is written once and never changes the
 * payment's or the invoice's own amount and currency. Provider payloads are not stored here (they stay with the
 * provider event).
 */
#[Fillable(['reference', 'invoice_id', 'provider', 'provider_reference', 'provider_transaction_reference', 'idempotency_key', 'method', 'amount_minor', 'currency',
    'settlement_amount_minor', 'settlement_currency', 'settlement_fx_rate', 'settlement_fx_source', 'settlement_recorded_at', 'status', 'initiated_at', 'completed_at', 'failure_code', 'failure_message', 'reconciliation_status', 'reconciliation_code',
    'reconciliation_note', 'resolved_by', 'resolved_at', 'reason', 'created_by'])]
class Payment extends Model
{
    use BelongsToTenant;
    use HasUlids;

    private const FIXED = ['tenant_id', 'reference', 'invoice_id', 'provider', 'idempotency_key', 'amount_minor', 'currency', 'initiated_at', 'reason', 'created_by'];

    private const RECONCILIATION = ['unreconciled' => ['matched', 'exception'], 'exception' => ['resolved', 'matched']];

    private const SETTLEMENT = ['settlement_amount_minor', 'settlement_currency', 'settlement_fx_rate', 'settlement_fx_source', 'settlement_recorded_at'];

    protected static function booted(): void
    {
        static::updating(function (self $payment): void {
            $status = $payment->isDirty('status') && ! PaymentStatus::from($payment->getRawOriginal('status'))->canMoveTo($payment->status);
            $reference = ($payment->isDirty('provider_reference') && $payment->getRawOriginal('provider_reference') !== null)
                || ($payment->isDirty('provider_transaction_reference') && $payment->getRawOriginal('provider_transaction_reference') !== null)
                || ($payment->isDirty(self::SETTLEMENT) && $payment->getRawOriginal('settlement_recorded_at') !== null);
            $reconciliation = $payment->isDirty('reconciliation_status')
                && ! in_array($payment->reconciliation_status->value, self::RECONCILIATION[$payment->getRawOriginal('reconciliation_status')] ?? [], true);
            if ($payment->isDirty(self::FIXED) || $status || $reference || $reconciliation) {
                throw new RuntimeException('A payment keeps its invoice, amount, currency and provider reference; its status and reconciliation only move forward.');
            }
        });
        static::deleting(function (): void {
            throw new RuntimeException('Payments are never deleted.');
        });
    }

    public function uniqueIds(): array
    {
        return ['reference'];
    }

    protected function casts(): array
    {
        return ['status' => PaymentStatus::class, 'reconciliation_status' => ReconciliationStatus::class, 'method' => PaymentMethod::class, 'currency' => Currency::class,
            'amount_minor' => 'integer', 'settlement_amount_minor' => 'integer', 'settlement_recorded_at' => 'datetime', 'initiated_at' => 'datetime', 'completed_at' => 'datetime', 'resolved_at' => 'datetime'];
    }

    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class);
    }

    public function amount(): Money
    {
        return Money::ofMinor($this->amount_minor, $this->currency);
    }
}
