<?php

namespace App\Domain\Tax\Support;

use App\Domain\Tax\Models\TaxRule;

/**
 * SaaS.7: a determination priced by the verified rule in force: what will be frozen on the invoice at issue. The
 * primary leg is the supplier's; a supply abroad adds the customer jurisdiction's leg. $reportingCurrency names a
 * currency the law requires the invoice value to be reported in (India: INR for a foreign-currency invoice).
 */
final readonly class TaxQuote
{
    /** @param  list<TaxComponent>  $components  @param  list<TaxLeg>  $legs */
    public function __construct(public TaxDetermination $determination, public TaxRule $rule, public array $components, public array $legs = [],
        public ?string $reportingCurrency = null) {}

    /** @return list<TaxLeg> */
    public function legs(): array
    {
        return $this->legs !== [] ? $this->legs
            : [new TaxLeg('supplier', $this->determination, $this->rule, $this->determination->treatment, $this->components)];
    }

    /** @return list<string> invoice wording the law prescribes (e.g. "Reverse charge", the export declaration) */
    public function wording(): array
    {
        return array_values(array_filter(array_map(fn (TaxLeg $l) => $l->wording, $this->legs())));
    }

    /** @return array<string, mixed> the tax snapshot stored on the invoice */
    public function snapshot(): array
    {
        return ['determination' => $this->determination->toArray(),
            'rule' => ['id' => $this->rule->id, 'label' => $this->rule->label(), 'version' => $this->rule->version, 'effective_from' => $this->rule->effective_from->toDateString(),
                'verification_reference' => $this->rule->verification_reference, 'rounding_mode' => $this->rule->rounding_mode->value, 'rounding_stage' => $this->rule->rounding_stage,
                'source' => $this->rule->source, 'source_reference' => $this->rule->source_reference],
            'classification' => $this->rule->classification ?? [],
            'components' => array_map(fn (TaxComponent $c) => ['type' => $c->type, 'rate' => $c->rate], $this->components),
            'legs' => array_map(fn (TaxLeg $l) => $l->snapshot(), $this->legs()),
            'wording' => $this->wording(),
            'reporting_currency' => $this->reportingCurrency];
    }
}
