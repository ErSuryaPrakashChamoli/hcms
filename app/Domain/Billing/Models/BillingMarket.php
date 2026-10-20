<?php

namespace App\Domain\Billing\Models;

use App\Support\Money\Currency;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use RuntimeException;

/**
 * SaaS.7: a market Markedge sells in (platform catalogue): one currency, the Markedge entity that sells there, a
 * display locale, and the countries it is meant for (informational; billing jurisdiction is on each billing
 * profile). Its code and currency are permanent: prices and invoices refer to them.
 */
#[Fillable(['code', 'name', 'currency', 'countries', 'supplier_entity', 'locale', 'status', 'reason', 'created_by'])]
class BillingMarket extends Model
{
    protected static function booted(): void
    {
        static::updating(function (self $market): void {
            if ($market->isDirty(['code', 'currency'])) {
                throw new RuntimeException('A market keeps its code and currency: create another market instead.');
            }
        });
        static::deleting(function (): void {
            throw new RuntimeException('Billing markets are never deleted: prices and invoices refer to them.');
        });
    }

    protected function casts(): array
    {
        return ['currency' => Currency::class, 'countries' => 'array'];
    }

    public function label(): string
    {
        return "{$this->code} · {$this->name} ({$this->currency->value})";
    }
}
