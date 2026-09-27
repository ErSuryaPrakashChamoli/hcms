<?php

namespace App\Domain\Enterprise\Services;

use App\Domain\Organisation\Models\Company;

/** Country pack registry (§96): metadata per country; India is complete, others carry framework metadata. */
final class CountryPacks
{
    /** @return array<string, array<string, mixed>> */
    public function all(): array
    {
        return require database_path('data/countries/packs.php');
    }

    public function get(?string $code): ?array
    {
        $packs = $this->all();

        return $packs[strtoupper((string) $code)] ?? null;
    }

    /** @return array<string, string> code => name */
    public function options(): array
    {
        return array_map(fn ($p) => $p['name'], $this->all());
    }

    public function forCompany(Company $company): array
    {
        return $this->get($company->country_code) ?? $this->get('IN');
    }

    public function statutoryEngine(?string $code): string
    {
        return $this->get($code)['statutory_engine'] ?? 'generic';
    }

    public function formatDate(\DateTimeInterface $date, ?string $code): string
    {
        return $date->format($this->get($code)['date_format'] ?? 'Y-m-d');
    }

    /** Indian grouping (12,34,567.00) or standard (1,234,567.00). */
    public function formatNumber(float $amount, ?string $code, ?int $decimals = null): string
    {
        $pack = $this->get($code) ?? [];
        $decimals ??= (int) ($pack['number_format']['decimals'] ?? 2);
        if (($pack['number_format']['grouping'] ?? 'standard') !== 'indian') {
            return number_format($amount, $decimals);
        }
        $negative = $amount < 0;
        $fixed = number_format(abs($amount), $decimals, '.', '');
        [$int, $frac] = array_pad(explode('.', $fixed), 2, null);
        if (strlen($int) > 3) {
            $last = substr($int, -3);
            $rest = substr($int, 0, -3);
            $rest = preg_replace('/\B(?=(\d{2})+(?!\d))/', ',', $rest);
            $int = $rest.','.$last;
        }

        return ($negative ? '-' : '').$int.($decimals > 0 ? '.'.$frac : '');
    }
}
