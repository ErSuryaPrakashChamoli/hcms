<?php

namespace App\Domain\Billing\Models;

use App\Support\Money\Currency;
use App\Support\Money\Money;
use App\Support\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use RuntimeException;

/**
 * SaaS.7 completion (B-12): an issued credit note against one issued invoice (its own gap-free number series), with
 * the credited lines and the tax at the invoice's original rates frozen in its snapshot. Created only by an
 * approved maker-checker request; never changes; never deleted. A full credit note is the invoice's cancellation.
 */
#[Fillable(['reference', 'invoice_id', 'kind', 'supplier_entity', 'series_id', 'sequence', 'number', 'issue_date', 'currency', 'subtotal_minor', 'tax_minor',
    'total_minor', 'snapshot', 'reason', 'approval_id', 'created_by', 'approved_by'])]
class CreditNote extends Model
{
    use BelongsToTenant;
    use HasUlids;

    public const FULL = 'full';

    public const PARTIAL = 'partial';

    protected static function booted(): void
    {
        static::updating(function (): void {
            throw new RuntimeException('An issued credit note never changes.');
        });
        static::deleting(function (): void {
            throw new RuntimeException('Credit notes are never deleted.');
        });
    }

    public function uniqueIds(): array
    {
        return ['reference'];
    }

    protected function casts(): array
    {
        return ['currency' => Currency::class, 'issue_date' => 'date', 'subtotal_minor' => 'integer', 'tax_minor' => 'integer', 'total_minor' => 'integer',
            'sequence' => 'integer', 'snapshot' => 'array'];
    }

    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class);
    }

    public function total(): Money
    {
        return Money::ofMinor($this->total_minor, $this->currency);
    }

    public function subtotal(): Money
    {
        return Money::ofMinor($this->subtotal_minor, $this->currency);
    }

    public function tax(): Money
    {
        return Money::ofMinor($this->tax_minor, $this->currency);
    }
}
