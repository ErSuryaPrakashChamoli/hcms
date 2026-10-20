<?php

namespace App\Domain\Billing\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use RuntimeException;

/**
 * SaaS.7: a gap-free invoice number series of one Markedge entity for an explicit date window (e.g. a financial
 * year chosen by finance). Only its next sequence and its closing move; the prefix, window and padding never do.
 */
#[Fillable(['supplier_entity', 'document_type', 'prefix', 'starts_on', 'ends_on', 'next_sequence', 'padding', 'max_length', 'status', 'reason',
    'created_by', 'closed_by', 'closed_at'])]
class InvoiceNumberSeries extends Model
{
    protected $table = 'invoice_number_series';

    protected static function booted(): void
    {
        static::updating(function (self $series): void {
            if (array_diff(array_keys($series->getDirty()), ['next_sequence', 'status', 'closed_by', 'closed_at', 'updated_at']) !== []
                || ($series->isDirty('next_sequence') && $series->next_sequence < $series->getRawOriginal('next_sequence'))
                || ($series->isDirty('status') && $series->getRawOriginal('status') === 'closed')) {
                throw new RuntimeException('An invoice number series only moves forward: its prefix, window and padding never change.');
            }
        });
        static::deleting(function (): void {
            throw new RuntimeException('Invoice number series are never deleted.');
        });
    }

    protected function casts(): array
    {
        return ['starts_on' => 'date', 'ends_on' => 'date', 'next_sequence' => 'integer', 'padding' => 'integer', 'max_length' => 'integer'];
    }

    public function format(int $sequence): string
    {
        return $this->prefix.str_pad((string) $sequence, $this->padding, '0', STR_PAD_LEFT);
    }

    public function label(): string
    {
        return "{$this->prefix}… · {$this->starts_on->toDateString()} to {$this->ends_on->toDateString()} · next {$this->format($this->next_sequence)}";
    }
}
