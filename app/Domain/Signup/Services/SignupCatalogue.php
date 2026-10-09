<?php

namespace App\Domain\Signup\Services;

use App\Domain\Billing\Enums\ConfigurationKey;
use App\Domain\Billing\Models\BillingMarket;
use App\Domain\Billing\Models\PlanPrice;
use App\Domain\Billing\Models\PlanPriceVersion;
use App\Domain\Billing\Services\BillingCatalog;
use App\Domain\Billing\Services\CommercialConfiguration;
use App\Domain\Configuration\Enums\VersionStatus;
use App\Domain\Entitlements\Enums\Capability;
use App\Domain\Entitlements\Models\PlanEntitlement;
use App\Domain\Entitlements\Models\PlanVersion;
use App\Domain\Signup\Support\SignupRefused;
use App\Support\Money\MoneyFormatter;
use Locale;

/**
 * SaaS.10: what self-service signup offers on a day, read only from approved, effective-dated commercial configuration
 * (never a constant): the offers (`signup.offers`: plan, trial days, countries), the plan version on sale, the billing
 * market of the country and a price on sale there, the plan's employee limit, the terms and the privacy notice. Any
 * gap is a stated refusal code, so signup fails safe with an actionable message instead of assuming a plan, a price,
 * a trial or terms.
 */
final class SignupCatalogue
{
    public const NOT_OFFERED = 'NOT_OFFERED';

    public const PLAN_UNAVAILABLE = 'PLAN_VERSION_NOT_ON_SALE';

    public const MARKET_NOT_CONFIGURED = 'MARKET_NOT_CONFIGURED';

    public const MARKET_AMBIGUOUS = 'MARKET_AMBIGUOUS';

    public const PRICE_NOT_CONFIGURED = 'PRICE_NOT_CONFIGURED';

    public const TERMS_NOT_CONFIGURED = 'TERMS_NOT_CONFIGURED';

    public const ENTITLEMENTS_NOT_CONFIGURED = 'ENTITLEMENTS_NOT_CONFIGURED';

    public const DISABLED = 'SIGNUP_DISABLED';

    /** @var array<string, mixed> per-day memo (the public pages read the same offer several times per request) */
    private array $memo = [];

    public function __construct(private readonly CommercialConfiguration $configuration, private readonly BillingCatalog $billing) {}

    /** D10-1: whether public registration is switched on in this environment. */
    public function enabled(): bool
    {
        return (bool) config('peopleos.signup.enabled', false);
    }

    public function assertEnabled(): void
    {
        if (! $this->enabled()) {
            throw new SignupRefused(self::DISABLED, 'Self-service registration is not open. Contact Markedge to set up PeopleOS for your organisation.');
        }
    }

    /** @return list<array{plan: string, trial_days: int, countries: list<string>}> the approved offers in force (none when not configured) */
    public function offers(?string $day = null): array
    {
        return (array) ($this->configuration->value(ConfigurationKey::SignupOffers, '', $day ?? $this->today()) ?? []);
    }

    /** @return array<string, string> ISO code => English name, of every country some offer covers */
    public function countries(?string $day = null): array
    {
        $codes = collect($this->offers($day))->flatMap(fn (array $o) => $o['countries'])->unique()->sort()->values();

        return $codes->mapWithKeys(fn (string $code) => [$code => Locale::getDisplayRegion('-'.$code, 'en') ?: $code])->all();
    }

    /**
     * Every offer available in $country today, resolved (offers whose plan, market or price is missing are left out).
     *
     * @return list<array<string, mixed>>
     */
    public function available(string $country, ?string $day = null): array
    {
        $out = [];
        foreach ($this->offers($day) as $offer) {
            if (! in_array(strtoupper($country), $offer['countries'], true)) {
                continue;
            }
            try {
                $out[] = $this->resolve($offer['plan'], $country, $day);
            } catch (SignupRefused) {
                // not available in this country today: not offered
            }
        }

        return $out;
    }

    /**
     * The offer of $plan in $country on $day, fully resolved, or a SignupRefused with its code.
     *
     * @return array{plan: string, name: string, description: ?string, trial_days: int, version: PlanVersion, market: BillingMarket, prices: list<array{interval: string, basis: string, amount: string}>, employee_limit: ?int, country: string}
     */
    public function resolve(string $plan, string $country, ?string $day = null): array
    {
        $day ??= $this->today();
        $country = strtoupper($country);
        $key = "{$plan}|{$country}|{$day}";
        if (array_key_exists($key, $this->memo)) {
            return $this->memo[$key] instanceof SignupRefused ? throw $this->memo[$key] : $this->memo[$key];
        }
        try {
            return $this->memo[$key] = $this->resolveFresh(strtolower($plan), $country, $day);
        } catch (SignupRefused $e) {
            $this->memo[$key] = $e;

            throw $e;
        }
    }

    /** @return array{version: string, url: string}|null */
    public function terms(?string $day = null): ?array
    {
        return $this->configuration->value(ConfigurationKey::SignupTerms, '', $day ?? $this->today());
    }

    /** @return array{version: string, url: string}|null */
    public function privacyNotice(?string $day = null): ?array
    {
        return $this->configuration->value(ConfigurationKey::SignupPrivacyNotice, '', $day ?? $this->today());
    }

    /** `all` (every application is reviewed: the shipped default) or `flagged`. */
    public function reviewPolicy(?string $day = null): string
    {
        return (string) ($this->configuration->value(ConfigurationKey::SignupReview, '', $day ?? $this->today()) ?? 'all');
    }

    /** @return array{terms: array{version: string, url: string}, privacy: array{version: string, url: string}} */
    public function documents(?string $day = null): array
    {
        $terms = $this->terms($day);
        $privacy = $this->privacyNotice($day);
        if ($terms === null || $privacy === null) {
            throw new SignupRefused(self::TERMS_NOT_CONFIGURED, 'Registration cannot be completed at the moment. Contact Markedge to set up PeopleOS for your organisation.');
        }

        return ['terms' => $terms, 'privacy' => $privacy];
    }

    /**
     * For the operator page: what self-service signup would need today, and what is missing.
     *
     * @return list<array{item: string, state: string, detail: string}>
     */
    public function readiness(?string $day = null): array
    {
        $day ??= $this->today();
        $offers = $this->offers($day);
        $rows = [['item' => 'Public registration switch', 'state' => $this->enabled() ? 'on' : 'off', 'detail' => $this->enabled() ? 'PEOPLEOS_PUBLIC_SIGNUP_ENABLED is on in this environment' : 'Off (D10-1): the signup pages show a notice and refuse every action']];
        $rows[] = ['item' => 'Offers (signup.offers)', 'state' => $offers === [] ? 'missing' : 'configured', 'detail' => ConfigurationKey::SignupOffers->describe($offers)];
        foreach ($offers as $offer) {
            foreach ($offer['countries'] as $country) {
                try {
                    $resolved = $this->resolve($offer['plan'], $country, $day);
                    $rows[] = ['item' => "{$offer['plan']} in {$country}", 'state' => 'available', 'detail' => $resolved['version']->label().' · '.$resolved['market']->label()];
                } catch (SignupRefused $e) {
                    $rows[] = ['item' => "{$offer['plan']} in {$country}", 'state' => 'unavailable', 'detail' => "{$e->reason}: {$e->getMessage()}"];
                }
            }
        }
        $rows[] = ['item' => 'Terms of Service (signup.terms)', 'state' => $this->terms($day) === null ? 'missing' : 'configured', 'detail' => ($t = $this->terms($day)) === null ? 'Not approved: signup cannot complete' : ConfigurationKey::SignupTerms->describe($t)];
        $rows[] = ['item' => 'Privacy Notice (signup.privacy_notice)', 'state' => $this->privacyNotice($day) === null ? 'missing' : 'configured', 'detail' => ($p = $this->privacyNotice($day)) === null ? 'Not approved: signup cannot complete' : ConfigurationKey::SignupPrivacyNotice->describe($p)];
        $rows[] = ['item' => 'Review policy (signup.review)', 'state' => 'configured', 'detail' => ConfigurationKey::SignupReview->describe($this->reviewPolicy($day))];

        return $rows;
    }

    /** @return array<string, mixed> */
    private function resolveFresh(string $plan, string $country, string $day): array
    {
        $offer = collect($this->offers($day))->firstWhere('plan', $plan);
        if ($offer === null || ! in_array($country, $offer['countries'], true)) {
            throw new SignupRefused(self::NOT_OFFERED, 'This plan is not offered for self-service registration in your country.');
        }
        $version = PlanVersion::query()->with('plan')->whereHas('plan', fn ($q) => $q->where('code', $plan))
            ->where('status', VersionStatus::Published->value)->whereDate('effective_from', '<=', $day)
            ->where(fn ($q) => $q->whereNull('effective_to')->orWhereDate('effective_to', '>=', $day))
            ->orderByDesc('effective_from')->orderByDesc('version')->first();
        if ($version === null) {
            throw new SignupRefused(self::PLAN_UNAVAILABLE, 'This plan is not available at the moment. Choose another plan or contact Markedge.');
        }
        if (! PlanEntitlement::query()->where('plan_version_id', $version->id)->exists()) {
            throw new SignupRefused(self::ENTITLEMENTS_NOT_CONFIGURED, 'This plan is not available at the moment. Choose another plan or contact Markedge.');
        }
        $markets = BillingMarket::query()->where('status', 'active')->orderBy('id')->get()
            ->filter(fn (BillingMarket $m) => in_array($country, array_map('strtoupper', (array) $m->countries), true))->values();
        if ($markets->isEmpty()) {
            throw new SignupRefused(self::MARKET_NOT_CONFIGURED, 'PeopleOS is not yet sold in your country. Contact Markedge.');
        }
        $priced = $markets->map(fn (BillingMarket $m) => ['market' => $m, 'prices' => $this->prices($version, $m, $day)])->filter(fn (array $row) => $row['prices'] !== [])->values();
        if ($priced->isEmpty()) {
            throw new SignupRefused(self::PRICE_NOT_CONFIGURED, 'This plan has no price in your country yet. Contact Markedge.');
        }
        if ($priced->count() > 1) {
            throw new SignupRefused(self::MARKET_AMBIGUOUS, 'This plan cannot be offered in your country at the moment. Contact Markedge.');
        }
        $limit = PlanEntitlement::query()->where('plan_version_id', $version->id)->where('capability', Capability::ActiveEmployeesMax->value)->value('value_int');

        return ['plan' => $plan, 'name' => $version->plan->name, 'description' => $version->plan->description, 'trial_days' => (int) $offer['trial_days'],
            'version' => $version, 'market' => $priced[0]['market'], 'prices' => $priced[0]['prices'], 'employee_limit' => $limit === null ? null : (int) $limit, 'country' => $country];
    }

    /** @return list<array{interval: string, basis: string, amount: string}> */
    private function prices(PlanVersion $version, BillingMarket $market, string $day): array
    {
        return PlanPrice::query()->where('plan_version_id', $version->id)->where('market_id', $market->id)->orderBy('interval')->get()
            ->map(fn (PlanPrice $price) => ($v = $this->billing->versionOnSale($price, $day)) instanceof PlanPriceVersion
                ? ['interval' => $price->interval->label(), 'basis' => $price->basis->label(), 'amount' => MoneyFormatter::format($v->amount(), $market->locale ?: 'en')]
                : null)
            ->filter()->values()->all();
    }

    private function today(): string
    {
        return now()->toDateString();
    }
}
