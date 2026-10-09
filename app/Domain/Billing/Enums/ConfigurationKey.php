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
    // SaaS.10: self-service signup. No shipped default except the review policy (every application is reviewed): with
    // nothing approved, no plan is offered and signup cannot complete (fail safe, never an assumed trial or terms).
    case SignupOffers = 'signup.offers';
    case SignupReview = 'signup.review';
    case SignupTerms = 'signup.terms';
    case SignupPrivacyNotice = 'signup.privacy_notice';

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
            self::SignupOffers => 'Self-service signup: plans offered, trial days and countries',
            self::SignupReview => 'Self-service signup: which applications an operator reviews',
            self::SignupTerms => 'Self-service signup: Terms of Service version and address',
            self::SignupPrivacyNotice => 'Self-service signup: Privacy Notice version and address',
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
            self::SignupOffers => 'D10-2',
            self::SignupReview => 'D10-3',
            self::SignupTerms, self::SignupPrivacyNotice => 'D10-4',
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
            self::SignupOffers => $this->offers($value),
            self::SignupReview => in_array($value, ['all', 'flagged'], true) ? $value : throw new RuntimeException('all (every application) or flagged (only flagged applications).'),
            self::SignupTerms, self::SignupPrivacyNotice => $this->document($value),
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
            $this === self::SignupOffers => implode('; ', array_map(fn (array $o) => $o['plan'].': '.($o['trial_days'] > 0 ? "{$o['trial_days']}-day trial" : 'no self-service trial (reviewed)').' in '.implode(', ', $o['countries']), (array) $value)) ?: 'no plan offered',
            $this === self::SignupReview => $value === 'all' ? 'every application is reviewed by an operator' : 'only flagged applications are reviewed',
            $this === self::SignupTerms, $this === self::SignupPrivacyNotice => "version {$value['version']} ({$value['url']})",
            default => (string) $value,
        };
    }

    /**
     * SaaS.10: `PLAN:TRIAL_DAYS:CC|CC`, comma-separated (e.g. `core:30:IN|AE, growth:0:IN`). 0 trial days: the plan is
     * offered for review only (sales-led), never a self-service trial.
     *
     * @return list<array{plan: string, trial_days: int, countries: list<string>}>
     */
    private function offers(mixed $value): array
    {
        $format = 'Each offer is PLAN:TRIAL_DAYS:COUNTRIES, e.g. core:30:IN|AE (0 days: reviewed, no self-service trial).';
        if (! is_string($value) && ! is_array($value)) {
            throw new RuntimeException($format);
        }
        $rows = is_string($value) ? array_filter(array_map('trim', explode(',', $value))) : $value;
        $out = [];
        foreach ($rows as $row) {
            [$plan, $days, $countries] = is_array($row) ? [$row['plan'] ?? '', $row['trial_days'] ?? '', $row['countries'] ?? []] : array_pad(explode(':', (string) $row), 3, '');
            $plan = strtolower(trim((string) $plan));
            $countries = array_values(array_unique(array_map(fn ($c) => strtoupper(trim((string) $c)), is_array($countries) ? $countries : explode('|', (string) $countries))));
            if (preg_match('/^[a-z0-9][a-z0-9._-]{0,63}$/', $plan) !== 1 || ! is_numeric($days) || (int) $days != $days || (int) $days < 0 || (int) $days > 365
                || $countries === [] || array_filter($countries, fn ($c) => preg_match('/^[A-Z]{2}$/', $c) !== 1) !== []) {
                throw new RuntimeException($format);
            }
            if (array_key_exists($plan, $out)) {
                throw new RuntimeException("The plan {$plan} is offered twice.");
            }
            $out[$plan] = ['plan' => $plan, 'trial_days' => (int) $days, 'countries' => $countries];
        }

        return array_values($out);
    }

    /** SaaS.10: `VERSION URL` (an https address), e.g. `2026.1 https://example.com/terms`. @return array{version: string, url: string} */
    private function document(mixed $value): array
    {
        [$version, $url] = is_array($value) ? [$value['version'] ?? '', $value['url'] ?? ''] : array_pad(preg_split('/\s+/', trim((string) $value), 2) ?: [], 2, '');
        $version = trim((string) $version);
        $url = trim((string) $url);
        if (preg_match('/^[A-Za-z0-9][A-Za-z0-9._-]{0,31}$/', $version) !== 1 || filter_var($url, FILTER_VALIDATE_URL) === false || ! str_starts_with(strtolower($url), 'https://') || strlen($url) > 500) {
            throw new RuntimeException('A version (letters, digits, . _ -) and its https address, e.g. 2026.1 https://example.com/terms.');
        }

        return ['version' => $version, 'url' => $url];
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
