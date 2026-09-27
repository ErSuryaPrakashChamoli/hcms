<?php

namespace App\Domain\Enterprise\Services;

use App\Domain\Enterprise\Models\ExchangeRate;
use App\Support\Tenancy\TenantContext;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;
use RuntimeException;

/** Converts amounts between currencies with the latest rate on or before a date; inverse rates are derived. */
final class CurrencyRates
{
    public function __construct(private readonly TenantContext $tenants) {}

    public function baseCurrency(): string
    {
        return strtoupper($this->tenants->current()?->currency ?: config('peopleos.settings.tenant.base_currency', 'INR'));
    }

    public function rate(string $from, string $to, CarbonInterface|string|null $on = null): float
    {
        $from = strtoupper($from);
        $to = strtoupper($to);
        if ($from === $to) {
            return 1.0;
        }
        $day = Carbon::parse($on ?? now())->toDateString();

        $direct = ExchangeRate::query()->where('from_currency', $from)->where('to_currency', $to)->whereDate('effective_on', '<=', $day)->orderByDesc('effective_on')->value('rate');
        if ($direct !== null) {
            return (float) $direct;
        }
        $inverse = ExchangeRate::query()->where('from_currency', $to)->where('to_currency', $from)->whereDate('effective_on', '<=', $day)->orderByDesc('effective_on')->value('rate');
        if ($inverse !== null && (float) $inverse > 0) {
            return round(1 / (float) $inverse, 8);
        }

        throw new RuntimeException("No exchange rate from {$from} to {$to} on or before {$day}.");
    }

    public function convert(float $amount, string $from, string $to, CarbonInterface|string|null $on = null): float
    {
        return round($amount * $this->rate($from, $to, $on), 2);
    }

    public function toBase(float $amount, string $from, CarbonInterface|string|null $on = null): float
    {
        return $this->convert($amount, $from, $this->baseCurrency(), $on);
    }

    public function set(string $from, string $to, float $rate, CarbonInterface|string|null $on = null, string $source = 'manual'): ExchangeRate
    {
        if ($rate <= 0) {
            throw new RuntimeException('A rate must be positive.');
        }

        return ExchangeRate::query()->updateOrCreate(['from_currency' => strtoupper($from), 'to_currency' => strtoupper($to), 'effective_on' => Carbon::parse($on ?? now())->toDateString()], ['rate' => $rate, 'source' => $source]);
    }
}
