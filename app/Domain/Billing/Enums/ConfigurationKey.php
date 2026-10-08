<?php

namespace App\Domain\Billing\Enums;

use RuntimeException;

/**
 * SaaS.7 configuration: the business values PeopleOS reads instead of constants. Two kinds, kept apart:
 * - company_policy: Markedge's own commercial policy (approved decisions B-3, B-5, B-11, B-15), shipped with a
 *   default in config/peopleos.php (commercial.policy_defaults) and changed by an approved, effective-dated version;
 * - statutory: a value set by law (shipped in the statutory dataset, scoped to a country), never defaulted.
 * The calculation logic stays in code; these are its parameters. A customer's own terms are not here: they are its
 * negotiated price and billing terms.
 */
enum ConfigurationKey: string
{
    case PaymentTermsDays = 'billing.payment_terms_days';
    case B2bOnly = 'billing.b2b_only';
    case PriceIncreaseNoticeDays = 'billing.price_increase_notice_days';
    case PricesIncludeTax = 'billing.prices_include_tax';
    case ProrationRounding = 'billing.proration_rounding';
    case TdsJurisdictions = 'settlement.tds_jurisdictions';
    case InvoiceNumberMaxLength = 'invoice.number_max_length';

    public const POLICY = 'company_policy';

    public const STATUTORY = 'statutory';

    public function domain(): string
    {
        return $this === self::InvoiceNumberMaxLength ? self::STATUTORY : self::POLICY;
    }

    /** Whether a version applies to one country (statutory values) or to Markedge as a whole. */
    public function scoped(): bool
    {
        return $this->domain() === self::STATUTORY;
    }

    public function label(): string
    {
        return match ($this) {
            self::PaymentTermsDays => 'Payment terms (days after issue)',
            self::B2bOnly => 'Sell to businesses only (B2B)',
            self::PriceIncreaseNoticeDays => 'Written notice before a price increase (days)',
            self::PricesIncludeTax => 'Prices include tax',
            self::ProrationRounding => 'Rounding of a prorated line',
            self::TdsJurisdictions => 'Where customers may deduct income-tax withholding (TDS) from payment',
            self::InvoiceNumberMaxLength => 'Maximum invoice number length (characters)',
        };
    }

    public function decision(): string
    {
        return match ($this) {
            self::PaymentTermsDays => 'B-11',
            self::B2bOnly, self::PricesIncludeTax => 'B-5',
            self::PriceIncreaseNoticeDays => 'B-15',
            self::ProrationRounding => 'B-3',
            self::TdsJurisdictions => 'B-11',
            self::InvoiceNumberMaxLength => 'statute',
        };
    }

    /** The validated, normalised value. */
    public function validate(mixed $value): mixed
    {
        return match ($this) {
            self::PaymentTermsDays, self::PriceIncreaseNoticeDays => is_numeric($value) && (int) $value == $value && (int) $value >= 0 && (int) $value <= 365
                ? (int) $value : throw new RuntimeException('A number of days from 0 to 365.'),
            self::InvoiceNumberMaxLength => is_numeric($value) && (int) $value == $value && (int) $value >= 4 && (int) $value <= 64
                ? (int) $value : throw new RuntimeException('A length from 4 to 64 characters.'),
            self::B2bOnly, self::PricesIncludeTax => is_bool($value) || in_array($value, ['0', '1', 0, 1, 'true', 'false'], true)
                ? filter_var($value, FILTER_VALIDATE_BOOLEAN) : throw new RuntimeException('Yes or no.'),
            self::ProrationRounding => in_array($value, ['half_up', 'half_even'], true) ? $value : throw new RuntimeException('half_up or half_even.'),
            self::TdsJurisdictions => $this->jurisdictions($value),
        };
    }

    public function describe(mixed $value): string
    {
        return match (true) {
            $value === null => 'not configured',
            is_bool($value) => $value ? 'yes' : 'no',
            $this === self::TdsJurisdictions => implode(', ', array_map(fn (array $j) => "{$j['country']} ({$j['currency']})", (array) $value)) ?: 'none',
            $this === self::PaymentTermsDays, $this === self::PriceIncreaseNoticeDays => "{$value} days",
            $this === self::InvoiceNumberMaxLength => "{$value} characters",
            default => (string) $value,
        };
    }

    /** @return list<array{country: string, currency: string}> */
    private function jurisdictions(mixed $value): array
    {
        if (! is_string($value) && ! is_array($value)) {
            throw new RuntimeException('Each entry is COUNTRY:CURRENCY, e.g. IN:INR (an empty list allows withholding nowhere).');
        }
        $rows = is_string($value) ? array_filter(array_map('trim', explode(',', $value))) : $value;
        $out = [];
        foreach ($rows as $row) {
            [$country, $currency] = is_array($row) ? [$row['country'] ?? '', $row['currency'] ?? ''] : array_pad(explode(':', (string) $row), 2, '');
            [$country, $currency] = [strtoupper(trim((string) $country)), strtoupper(trim((string) $currency))];
            if (preg_match('/^[A-Z]{2}$/', $country) !== 1 || preg_match('/^[A-Z]{3}$/', $currency) !== 1) {
                throw new RuntimeException('Each entry is COUNTRY:CURRENCY, e.g. IN:INR.');
            }
            $out[] = ['country' => $country, 'currency' => $currency];
        }

        return $out;
    }
}
