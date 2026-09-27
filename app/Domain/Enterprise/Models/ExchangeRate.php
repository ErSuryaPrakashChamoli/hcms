<?php

namespace App\Domain\Enterprise\Models;

use App\Domain\Audit\Concerns\Auditable;
use App\Support\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

/** Dated exchange rates for consolidated multi-currency reporting (§96). */
#[Fillable(['tenant_id', 'from_currency', 'to_currency', 'rate', 'effective_on', 'source'])]
class ExchangeRate extends Model
{
    use Auditable, BelongsToTenant;

    protected static function booted(): void
    {
        static::saving(function (self $r): void {
            $r->from_currency = strtoupper($r->from_currency);
            $r->to_currency = strtoupper($r->to_currency);
        });
    }

    protected function casts(): array
    {
        return ['rate' => 'decimal:8', 'effective_on' => 'date'];
    }

    public function auditModule(): string
    {
        return 'enterprise';
    }

    public function auditLabel(): string
    {
        return "{$this->from_currency}/{$this->to_currency} {$this->effective_on?->toDateString()}";
    }
}
