<?php

namespace App\Domain\Tax\Support;

use App\Domain\Tax\Models\TaxRule;

/** SaaS.7: a determination priced by the verified rule in force: what will be frozen on the invoice at issue. */
final readonly class TaxQuote
{
    /** @param  list<TaxComponent>  $components */
    public function __construct(public TaxDetermination $determination, public TaxRule $rule, public array $components) {}

    /** @return array<string, mixed> the tax snapshot stored on the invoice */
    public function snapshot(): array
    {
        return ['determination' => $this->determination->toArray(),
            'rule' => ['id' => $this->rule->id, 'label' => $this->rule->label(), 'version' => $this->rule->version, 'effective_from' => $this->rule->effective_from->toDateString(),
                'verification_reference' => $this->rule->verification_reference, 'rounding_mode' => $this->rule->rounding_mode->value, 'rounding_stage' => $this->rule->rounding_stage],
            'classification' => $this->rule->classification ?? [],
            'components' => array_map(fn (TaxComponent $c) => ['type' => $c->type, 'rate' => $c->rate], $this->components)];
    }
}
