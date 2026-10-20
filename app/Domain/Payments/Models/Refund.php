<?php

namespace App\Domain\Payments\Models;

use App\Domain\Billing\Models\CreditNote;
use App\Support\Money\Currency;
use App\Support\Money\Money;
use App\Support\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use RuntimeException;

/**
 * SaaS.7 completion (B-12): money returned to a customer against an issued credit note, from one succeeded payment
 * (by the provider's refund API or a bank transfer recorded by an operator). Created only by an approved
 * maker-checker request. Its amount never changes; its provider reference is written once; its status only moves
 * from processing to succeeded or failed.
 */
#[Fillable(['reference', 'credit_note_id', 'payment_id', 'provider', 'provider_refund_reference', 'amount_minor', 'currency', 'status', 'failure_code',
    'failure_message', 'approval_id', 'requested_by', 'approved_by', 'completed_at'])]
class Refund extends Model
{
    use BelongsToTenant;
    use HasUlids;

    public const PROCESSING = 'processing';

    public const SUCCEEDED = 'succeeded';

    public const FAILED = 'failed';

    protected static function booted(): void
    {
        static::updating(function (self $refund): void {
            $reference = $refund->isDirty('provider_refund_reference') && $refund->getRawOriginal('provider_refund_reference') !== null;
            $status = $refund->isDirty('status') && ! ($refund->getRawOriginal('status') === self::PROCESSING && in_array($refund->status, [self::SUCCEEDED, self::FAILED], true));
            if (array_diff(array_keys($refund->getDirty()), ['status', 'provider_refund_reference', 'failure_code', 'failure_message', 'completed_at', 'updated_at']) !== []
                || $reference || $status || $refund->getRawOriginal('status') !== self::PROCESSING) {
                throw new RuntimeException('A refund keeps its amount and credit note; its status moves once from processing to succeeded or failed.');
            }
        });
        static::deleting(function (): void {
            throw new RuntimeException('Refunds are never deleted.');
        });
    }

    public function uniqueIds(): array
    {
        return ['reference'];
    }

    protected function casts(): array
    {
        return ['currency' => Currency::class, 'amount_minor' => 'integer', 'completed_at' => 'datetime'];
    }

    public function amount(): Money
    {
        return Money::ofMinor($this->amount_minor, $this->currency);
    }

    public function creditNote(): BelongsTo
    {
        return $this->belongsTo(CreditNote::class);
    }

    public function payment(): BelongsTo
    {
        return $this->belongsTo(Payment::class);
    }
}
