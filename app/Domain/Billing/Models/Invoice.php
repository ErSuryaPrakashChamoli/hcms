<?php

namespace App\Domain\Billing\Models;

use App\Domain\Billing\Enums\InvoiceStatus;
use App\Support\Money\Currency;
use App\Support\Money\Money;
use App\Support\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use RuntimeException;

/**
 * SaaS.7: an invoice of a tenant (draft → issued → paid, or draft → discarded). At issue its number, tax and the
 * supplier, customer and tax snapshots are fixed; from then on only "paid" (with when and by which payment) may
 * be recorded. A correction would be a separate document (credit or debit note, decision B-12), never an edit.
 */
#[Fillable(['reference', 'document_type', 'status', 'market_id', 'supplier_entity', 'currency', 'subscription_id', 'period_start', 'period_end',
    'series_id', 'sequence', 'number', 'issue_date', 'due_date', 'subtotal_minor', 'tax_minor', 'total_minor', 'tax_regime', 'tax_treatment',
    'billing_profile_id', 'supplier_profile_id', 'tax_rule_id', 'snapshot', 'idempotency_key', 'reason', 'created_by', 'issued_by', 'issued_at',
    'discarded_by', 'discarded_at', 'discard_reason', 'paid_at', 'paid_by_payment_id'])]
class Invoice extends Model
{
    use BelongsToTenant;
    use HasUlids;

    /** What issuing may set on a draft. */
    private const ISSUE_FIELDS = ['status', 'series_id', 'sequence', 'number', 'issue_date', 'due_date', 'tax_minor', 'total_minor', 'tax_regime', 'tax_treatment',
        'billing_profile_id', 'supplier_profile_id', 'tax_rule_id', 'snapshot', 'issued_by', 'issued_at', 'discarded_by', 'discarded_at', 'discard_reason', 'updated_at'];

    protected static function booted(): void
    {
        static::updating(function (self $invoice): void {
            $original = $invoice->getRawOriginal('status');
            $dirty = array_keys($invoice->getDirty());
            $allowed = match ($original) {
                InvoiceStatus::Draft->value => array_diff($dirty, self::ISSUE_FIELDS) === []
                    && in_array($invoice->status, [InvoiceStatus::Draft, InvoiceStatus::Issued, InvoiceStatus::Discarded], true),
                InvoiceStatus::Issued->value => array_diff($dirty, ['status', 'paid_at', 'paid_by_payment_id', 'updated_at']) === []
                    && in_array($invoice->status, [InvoiceStatus::Issued, InvoiceStatus::Paid], true),
                default => false,
            };
            if (! $allowed) {
                throw new RuntimeException('An issued invoice never changes: its number, amounts, tax and snapshots are final.');
            }
        });
        static::deleting(function (): void {
            throw new RuntimeException('Invoices are never deleted.');
        });
    }

    public function uniqueIds(): array
    {
        return ['reference'];
    }

    protected function casts(): array
    {
        return ['status' => InvoiceStatus::class, 'currency' => Currency::class, 'period_start' => 'date', 'period_end' => 'date', 'issue_date' => 'date',
            'due_date' => 'date', 'subtotal_minor' => 'integer', 'tax_minor' => 'integer', 'total_minor' => 'integer', 'sequence' => 'integer',
            'snapshot' => 'array', 'issued_at' => 'datetime', 'discarded_at' => 'datetime', 'paid_at' => 'datetime'];
    }

    public function lines(): HasMany
    {
        return $this->hasMany(InvoiceLine::class)->orderBy('line_no');
    }

    public function taxLines(): HasMany
    {
        return $this->hasMany(InvoiceTaxLine::class)->orderBy('line_no')->orderBy('id');
    }

    public function market(): BelongsTo
    {
        return $this->belongsTo(BillingMarket::class, 'market_id');
    }

    public function subtotal(): Money
    {
        return Money::ofMinor($this->subtotal_minor, $this->currency);
    }

    public function tax(): Money
    {
        return Money::ofMinor($this->tax_minor, $this->currency);
    }

    public function total(): Money
    {
        return Money::ofMinor($this->total_minor, $this->currency);
    }

    public function label(): string
    {
        return $this->number ?? "Draft {$this->reference}";
    }

    public function isOverdue(?string $today = null): bool
    {
        return $this->status === InvoiceStatus::Issued && $this->due_date !== null && $this->due_date->toDateString() < ($today ?? now()->toDateString());
    }
}
