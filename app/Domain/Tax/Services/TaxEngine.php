<?php

namespace App\Domain\Tax\Services;

use App\Domain\Tax\Enums\JurisdictionStatus;
use App\Domain\Tax\Enums\TaxIdType;
use App\Domain\Tax\Enums\TaxRegime;
use App\Domain\Tax\Enums\TaxRegistration;
use App\Domain\Tax\Enums\TaxRuleStatus;
use App\Domain\Tax\Enums\TaxTreatment;
use App\Domain\Tax\Exceptions\TaxUnavailableException;
use App\Domain\Tax\Models\TaxRule;
use App\Domain\Tax\Support\TaxCalculation;
use App\Domain\Tax\Support\TaxComponent;
use App\Domain\Tax\Support\TaxContext;
use App\Domain\Tax\Support\TaxDetermination;
use App\Domain\Tax\Support\TaxLeg;
use App\Domain\Tax\Support\TaxQuote;
use App\Support\Money\Currency;
use App\Support\Money\Money;
use Brick\Math\BigDecimal;
use InvalidArgumentException;

/**
 * SaaS.7: the jurisdiction-neutral tax boundary billing calls. The calculation is code; every statutory value is
 * rule data. Before an invoice is issued it resolves, in order: the supplier's jurisdiction and regime, the customer's
 * jurisdiction, type and registration (the determiner), the place of supply, the verified rule version in force on
 * the tax point, the rate or treatment for the outcome, the rule's conditions (evidence, registrations, export
 * conditions), the classification the regime requires on an invoice, and any reporting currency. A supply abroad
 * adds the destination jurisdiction's leg (reverse charge, registration, local taxability). Every gap throws
 * TaxUnavailableException with a reason code: nothing is ever taxed by default, by guess or at 0 % by omission.
 */
final class TaxEngine
{
    /** Conditions a rule may state; anything else cannot be evaluated and refuses the invoice. */
    public const CONDITIONS = ['customer_types', 'customer_registration', 'customer_tax_id_types', 'customer_outside_supplier_country', 'invoice_currency_not',
        'supplier_registration', 'reporting_currency', 'local_rates_required', 'customer_special_status_not'];

    public function __construct(private readonly TaxRegistry $registry, private readonly TaxRules $rules) {}

    public function quote(TaxContext $context): TaxQuote
    {
        $country = $context->supplier->jurisdiction->country;
        $regime = JurisdictionCatalogue::regimeFor($country)
            ?? throw new TaxUnavailableException(TaxUnavailableException::TAX_CONFIGURATION_MISSING, "No tax regime is known for a supplier in {$country}.");
        $determiner = $this->registry->determiner($regime)
            ?? throw new TaxUnavailableException(TaxUnavailableException::TAX_CONFIGURATION_MISSING, "{$regime->label()} is not supported for a supplier (architecture-ready; no supplier-side determination).");
        $determination = $determiner->determine($context);
        $legs = [$supplier = $this->leg('supplier', $regime, $country, $determination->placeOfSupply->subdivision, $determination, $context)];

        if ($determination->requiresDestination) {
            $place = $determination->placeOfSupply;
            $destinationRegime = JurisdictionCatalogue::regimeFor($place->country)
                ?? throw new TaxUnavailableException(TaxUnavailableException::TAX_CONFIGURATION_MISSING, "No tax regime is configured for the customer's country ({$place->country}): its treatment of the supply is unknown.");
            $destination = $this->registry->destination($destinationRegime)
                ?? throw new TaxUnavailableException(TaxUnavailableException::TAX_CONFIGURATION_MISSING, "{$destinationRegime->label()} has no destination-side determination: the customer's treatment of the supply is unknown.");
            $destinationDetermination = $destination->determine($context);
            $legs[] = $state = $this->leg('destination', $destinationRegime, $destinationDetermination->placeOfSupply->country, $destinationDetermination->placeOfSupply->subdivision,
                $destinationDetermination, $context);
            // Local rates (US county, city, district): the rule of the customer's local tax jurisdiction, as data. None is ever assumed.
            if (($state->conditionsMet['local_rates_required'] ?? false) === true) {
                $place = $destinationDetermination->placeOfSupply;
                if ($place->locality === null) {
                    throw new TaxUnavailableException(TaxUnavailableException::PLACE_OF_SUPPLY_UNRESOLVED,
                        "Local rates apply in {$place->subdivision}: the customer's local tax jurisdiction (county, city, district) is not recorded on its billing profile.");
                }
                $legs[] = $this->leg('destination_local', $destinationRegime, $place->country, $place->subdivision, $destinationDetermination, $context, $place->locality);
            }
        }
        // Last: the classification an invoice of the regime must carry (e.g. India's SAC), once everything else resolved.
        foreach ($legs as $leg) {
            foreach ($leg->determination->regime->requiredClassification() as $key) {
                if (blank($leg->rule->classification[$key] ?? null)) {
                    throw new TaxUnavailableException(TaxUnavailableException::TAX_CLASSIFICATION_PENDING,
                        "The rule {$leg->rule->label()} has no \"{$key}\" classification for \"{$context->taxCategory}\": a {$leg->determination->regime->value} invoice cannot be issued without it (record it in a new rule version once confirmed).");
                }
            }
        }
        $reporting = null;
        foreach ($legs as $leg) {
            $wanted = $leg->conditionsMet['reporting_currency'] ?? null;
            if (is_string($wanted) && $wanted !== $context->currency->value) {
                $reporting = $wanted;
            }
        }

        return new TaxQuote($determination, $supplier->rule, $supplier->components, $legs, $reporting);
    }

    /**
     * Tax per leg: each leg's components on the taxable amounts, rounded with that leg's rule.
     *
     * @param  array<int, Money>  $taxable  line number => taxable amount
     * @return list<array{leg: TaxLeg, calculation: TaxCalculation}>
     */
    public function calculateLegs(TaxQuote $quote, Currency $currency, array $taxable): array
    {
        $out = [];
        foreach ($quote->legs() as $leg) {
            if ($leg->rule->rounding_stage !== 'line') {
                throw new TaxUnavailableException(TaxUnavailableException::TAX_CONFIGURATION_MISSING, "The rounding stage {$leg->rule->rounding_stage} is not implemented.");
            }
            $out[] = ['leg' => $leg, 'calculation' => TaxCalculator::calculate($currency, $taxable, $leg->components, $leg->rule->rounding_mode)];
        }

        return $out;
    }

    /** All legs' tax together (the invoice tax). @param  array<int, Money>  $taxable */
    public function calculate(TaxQuote $quote, Currency $currency, array $taxable): TaxCalculation
    {
        $lines = [];
        $total = Money::zero($currency);
        foreach ($this->calculateLegs($quote, $currency, $taxable) as ['calculation' => $calculation]) {
            $lines = array_merge($lines, $calculation->lines);
            $total = $total->plus($calculation->total);
        }

        return new TaxCalculation($lines, $total);
    }

    /**
     * What PeopleOS can honestly say about a country today; never "supported" (that needs approval outside software):
     * configured when a verified rule of its regime is in force for it, pending tax review when the determination
     * exists but no rule is verified, not supported otherwise.
     */
    public function status(string $country, ?string $day = null): JurisdictionStatus
    {
        $regime = JurisdictionCatalogue::regimeFor($country);
        if ($regime === null || ($this->registry->determiner($regime) === null && $this->registry->destination($regime) === null)) {
            return JurisdictionStatus::NotSupported;
        }
        $day ??= now()->toDateString();
        $configured = TaxRule::query()->where(['regime' => $regime, 'country' => $regime === TaxRegime::InGst ? 'IN' : $country, 'status' => TaxRuleStatus::Verified])
            ->whereDate('effective_from', '<=', $day)->where(fn ($q) => $q->whereNull('effective_to')->orWhereDate('effective_to', '>=', $day))->exists();

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

    private function leg(string $role, TaxRegime $regime, string $country, ?string $subdivision, TaxDetermination $determination, TaxContext $context, ?string $locality = null): TaxLeg
    {
        $scope = ($subdivision ?? $country).($locality !== null ? " / {$locality}" : '');
        $rule = $this->rules->inForce($regime, $country, $subdivision, $context->taxCategory, $context->taxPoint, $locality);
        if ($rule === null) {
            $why = $this->rules->whyNotInForce($regime, $country, $subdivision, $context->taxCategory, $context->taxPoint, $locality);
            throw new TaxUnavailableException($why === 'pending' ? TaxUnavailableException::TAX_RULE_UNVERIFIED : TaxUnavailableException::TAX_CONFIGURATION_MISSING,
                match ($why) {
                    'pending' => "The {$regime->value} rule for {$scope} (\"{$context->taxCategory}\") is pending verification: it cannot be used until another operator verifies it.",
                    'expired' => "The {$regime->value} rule for {$scope} (\"{$context->taxCategory}\") expired before {$context->taxPoint}, and no later version is verified.",
                    default => "No verified {$regime->value} rule for {$scope} (\"{$context->taxCategory}\") is in force on {$context->taxPoint}: the invoice cannot be issued.",
                });
        }
        $described = $this->registry->outcomes($regime)[$determination->outcome] ?? $determination->outcome;
        $outcome = $rule->outcome($determination->outcome)
            ?? throw new TaxUnavailableException(TaxUnavailableException::TAX_CONFIGURATION_MISSING, "The rule {$rule->label()} does not price the outcome \"{$determination->outcome}\" ({$described}).");
        $treatment = $outcome['treatment'] ?? $determination->treatment;
        if ($treatment === TaxTreatment::Standard && $outcome['components'] === []) {
            throw new TaxUnavailableException(TaxUnavailableException::TAX_CONFIGURATION_MISSING, "The rule {$rule->label()} prices no tax for the standard-rated outcome \"{$determination->outcome}\".");
        }
        if ($rule->amount_basis !== 'exclusive') {
            throw new TaxUnavailableException(TaxUnavailableException::TAX_CONFIGURATION_MISSING, "The rule {$rule->label()} is tax-inclusive: inclusive calculation is not enabled (prices are tax-exclusive).");
        }
        $met = [];
        if (($type = $outcome['requires_supplier_registration']) !== null) {
            $registration = $context->supplier->registration((string) $type, $context->taxPoint)
                ?? throw new TaxUnavailableException(TaxUnavailableException::REGISTRATION_REQUIRED,
                    "{$described}: the supplier needs a registration of type {$type} valid on {$context->taxPoint} ({$rule->label()}).");
            $met['supplier_registration'] = $registration['reference'] ?? $type;
        }
        $met += $this->conditions($rule, $outcome, $determination, $context);
        $components = $outcome['components'];
        if ($outcome['taxable_percent'] !== null && $outcome['taxable_percent'] !== '100') {
            // Only a share of the price is taxable (e.g. Texas: 20 % of a data-processing charge is exempt): each rate
            // applies to that share, computed exactly once here and recorded on the leg.
            $components = array_map(fn ($c) => new TaxComponent($c->type,
                (string) BigDecimal::of($c->rate)->multipliedBy($outcome['taxable_percent'])->dividedByExact(100)->strippedOfTrailingZeros()), $components);
            $met['taxable_percent'] = $outcome['taxable_percent'];
        }

        return new TaxLeg($role, $determination, $rule, $treatment, $components, $outcome['wording'], $met);
    }

    /**
     * Evaluates the rule-wide and outcome conditions against the supply. Unknown conditions refuse (fail closed).
     *
     * @param  array{conditions: array<string, mixed>, failure_code: ?string}  $outcome
     * @return array<string, mixed> what was checked
     */
    private function conditions(TaxRule $rule, array $outcome, TaxDetermination $determination, TaxContext $context): array
    {
        $conditions = ($rule->conditions ?? []) + $outcome['conditions'];
        $code = $outcome['failure_code'];
        $customer = $context->customer;
        $met = [];
        foreach ($conditions as $key => $expected) {
            $fail = fn (string $default, string $message) => throw new TaxUnavailableException($code ?? $default, "{$rule->label()}: {$message}");
            switch ($key) {
                case 'customer_types':
                    if (! in_array($customer->customerType?->value, (array) $expected, true)) {
                        $fail(TaxUnavailableException::CUSTOMER_TAX_STATUS_UNRESOLVED, 'the customer type ('.($customer->customerType?->value ?? 'not recorded').') is not '.implode(' or ', (array) $expected).'.');
                    }
                    break;
                case 'customer_registration':
                    if ($expected === 'registered' && ($customer->registration !== TaxRegistration::Registered || blank($customer->taxIdValue))) {
                        $fail(TaxUnavailableException::CUSTOMER_TAX_STATUS_UNRESOLVED, 'the customer\'s tax registration number is required as evidence of its business status.');
                    }
                    break;
                case 'customer_tax_id_types':
                    if (! in_array($customer->taxIdType?->value, (array) $expected, true)) {
                        $fail(TaxUnavailableException::CUSTOMER_TAX_STATUS_UNRESOLVED, 'the customer needs a tax identifier of type '.implode(' or ', (array) $expected).'.');
                    }
                    break;
                case 'customer_outside_supplier_country':
                    if ($expected && $customer->jurisdiction->country === $context->supplier->jurisdiction->country) {
                        $fail(TaxUnavailableException::EXPORT_CONDITIONS_NOT_SATISFIED, 'the recipient is not located outside the supplier\'s country.');
                    }
                    break;
                case 'invoice_currency_not':
                    if (in_array($context->currency->value, (array) $expected, true)) {
                        $fail(TaxUnavailableException::EXPORT_CONDITIONS_NOT_SATISFIED, "the invoice is in {$context->currency->value}: the condition requires payment in convertible foreign exchange.");
                    }
                    break;
                case 'supplier_registration':
                    $registration = $context->supplier->registration((string) $expected, $context->taxPoint);
                    if ($registration === null) {
                        $fail(TaxUnavailableException::REGISTRATION_REQUIRED, "the supplier holds no {$expected} valid on {$context->taxPoint}.");
                    }
                    $met['supplier_registration'] = $registration['reference'] ?? $expected;
                    break;
                case 'customer_special_status_not':
                    if (in_array($customer->specialStatus, (array) $expected, true)) {
                        $fail(TaxUnavailableException::EXPORT_CONDITIONS_NOT_SATISFIED, "the customer is recorded as \"{$customer->specialStatus}\".");
                    }
                    break;
                case 'reporting_currency':
                    break; // reported on the quote: billing must record the value in this currency at issue
                case 'local_rates_required':
                    // The state's rule says local rates apply: quote() adds the verified rule of the customer's local tax
                    // jurisdiction as its own leg, or refuses (no locality recorded, no rule, or not verified).
                    $expected = (bool) $expected;
                    break;
                default:
                    $fail(TaxUnavailableException::TAX_CONFIGURATION_MISSING, "the condition \"{$key}\" cannot be evaluated by this version of PeopleOS.");
            }
            $met[$key] ??= $expected;
        }

        return $met;
    }
}
