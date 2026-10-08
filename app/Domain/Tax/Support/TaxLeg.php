<?php

namespace App\Domain\Tax\Support;

use App\Domain\Tax\Enums\TaxTreatment;
use App\Domain\Tax\Models\TaxRule;

/**
 * SaaS.7 configuration: one jurisdiction's part of a supply's tax: the supplier's (domestic or export) or, for a
 * supply abroad, the customer jurisdiction's (reverse charge, registration, local taxability). Each leg is priced by
 * its own verified rule version and freezes its own treatment, components and invoice wording.
 */
final readonly class TaxLeg
{
    /** @param  list<TaxComponent>  $components  @param  array<string, mixed>  $conditionsMet */
    public function __construct(
        public string $role,
        public TaxDetermination $determination,
        public TaxRule $rule,
        public TaxTreatment $treatment,
        public array $components,
        public ?string $wording = null,
        public array $conditionsMet = [],
    ) {}

    /** @return array<string, mixed> */
    public function snapshot(): array
    {
        return ['role' => $this->role, 'determination' => $this->determination->toArray(), 'treatment' => $this->treatment->value, 'wording' => $this->wording,
            'rule' => ['id' => $this->rule->id, 'label' => $this->rule->label(), 'version' => $this->rule->version, 'rule_code' => $this->rule->rule_code,
                'effective_from' => $this->rule->effective_from->toDateString(), 'effective_to' => $this->rule->effective_to?->toDateString(),
                'verification_reference' => $this->rule->verification_reference, 'source' => $this->rule->source, 'source_reference' => $this->rule->source_reference,
                'rounding_mode' => $this->rule->rounding_mode->value, 'rounding_stage' => $this->rule->rounding_stage],
            'conditions_met' => $this->conditionsMet,
            'components' => array_map(fn (TaxComponent $c) => ['type' => $c->type, 'rate' => $c->rate], $this->components)];
    }
}
