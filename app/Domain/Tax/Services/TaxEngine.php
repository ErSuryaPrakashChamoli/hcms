<?php

namespace App\Domain\Tax\Services;

use App\Domain\Tax\Enums\JurisdictionStatus;
use App\Domain\Tax\Enums\TaxIdType;
use App\Domain\Tax\Enums\TaxRegime;
use App\Domain\Tax\Enums\TaxRuleStatus;
use App\Domain\Tax\Enums\TaxTreatment;
use App\Domain\Tax\Exceptions\TaxUnavailableException;
use App\Domain\Tax\Models\TaxRule;
use App\Domain\Tax\Support\TaxCalculation;
use App\Domain\Tax\Support\TaxContext;
use App\Domain\Tax\Support\TaxQuote;
use App\Support\Money\Money;
use InvalidArgumentException;

/**
 * SaaS.7: the jurisdiction-neutral tax boundary billing calls. Regime of the supplier's country → that regime's
 * determiner (treatment, outcome, place of supply) → the verified rule in force on the tax point → its components
 * for the outcome → the pure calculator. Every gap (no regime, no determiner, no verified rule, an outcome the rule
 * does not price) throws TaxUnavailableException: nothing is ever taxed by default or by guess.
 */
final class TaxEngine
{
    public function __construct(private readonly TaxRegistry $registry, private readonly TaxRules $rules) {}

    public function quote(TaxContext $context): TaxQuote
    {
        $regime = JurisdictionCatalogue::regimeFor($context->supplier->jurisdiction->country)
            ?? throw new TaxUnavailableException('no_regime', "No tax regime is known for a supplier in {$context->supplier->jurisdiction->country}.");
        $determiner = $this->registry->determiner($regime)
            ?? throw new TaxUnavailableException('regime_not_supported', "{$regime->label()} is not supported yet (architecture-ready; no determination or rules configured).");
        $determination = $determiner->determine($context);
        $rule = $this->rules->inForce($regime, $context->supplier->jurisdiction->country, $determination->placeOfSupply->subdivision, $context->taxCategory, $context->taxPoint)
            ?? throw new TaxUnavailableException('no_verified_rule', "No verified {$regime->value} rule for \"{$context->taxCategory}\" is in force on {$context->taxPoint}: the invoice cannot be issued.");
        $components = $rule->components($determination->outcome)
            ?? throw new TaxUnavailableException('rule_outcome_missing', "The rule {$rule->label()} does not price the outcome \"{$determination->outcome}\".");
        if ($determination->treatment === TaxTreatment::Standard && $components === []) {
            throw new TaxUnavailableException('rule_outcome_missing', "The rule {$rule->label()} prices no tax for the standard-rated outcome \"{$determination->outcome}\".");
        }

        return new TaxQuote($determination, $rule, $components);
    }

    /** @param  array<int, Money>  $taxable  line number => taxable amount */
    public function calculate(TaxQuote $quote, \App\Support\Money\Currency $currency, array $taxable): TaxCalculation
    {
        if ($quote->rule->rounding_stage !== 'line') {
            throw new TaxUnavailableException('rounding_stage', "The rounding stage {$quote->rule->rounding_stage} is not implemented.");
        }

        return TaxCalculator::calculate($currency, $taxable, $quote->components, $quote->rule->rounding_mode);
    }

    /** What PeopleOS can honestly say about a country today; never "supported" (that needs approval outside software). */
    public function status(string $country, ?string $day = null): JurisdictionStatus
    {
        $regime = JurisdictionCatalogue::regimeFor($country);
        if ($regime === null || $this->registry->determiner($regime) === null) {
            return JurisdictionStatus::NotSupported;
        }
        $country = $regime === TaxRegime::InGst ? 'IN' : $country;
        $configured = TaxRule::query()->where(['regime' => $regime, 'country' => $country, 'status' => TaxRuleStatus::Verified])
            ->whereDate('effective_from', '<=', $day ?? now()->toDateString())->exists();

        return $configured ? JurisdictionStatus::Configured : JurisdictionStatus::PendingTaxReview;
    }

    /** @return list<array{label: string, value: string}> */
    public function presentationRows(array $taxSnapshot): array
    {
        $regime = TaxRegime::tryFrom((string) ($taxSnapshot['determination']['regime'] ?? ''));

        return $regime === null ? [] : ($this->registry->presentation($regime)?->rows($taxSnapshot) ?? []);
    }

    /**
     * Normalises a tax identifier and says whether its format could be checked.
     *
     * @return array{value: string, status: string}
     *
     * @throws InvalidArgumentException when a validator exists and the format is wrong
     */
    public function normaliseTaxId(TaxIdType $type, string $value, ?string $subdivision): array
    {
        $validator = $this->registry->validator($type);
        if ($validator === null) {
            $clean = strtoupper(preg_replace('/\s+/', '', $value) ?? '');
            if (preg_match('/^[A-Z0-9.\-\/]{2,32}$/', $clean) !== 1) {
                throw new InvalidArgumentException("{$value} is not a tax identifier (letters, digits, dot, dash, slash; at most 32).");
            }

            return ['value' => $clean, 'status' => 'not_validated'];
        }

        return ['value' => $validator->validate($value, $subdivision), 'status' => 'format_valid'];
    }
}
