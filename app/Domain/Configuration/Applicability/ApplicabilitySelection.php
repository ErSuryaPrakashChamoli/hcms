<?php

namespace App\Domain\Configuration\Applicability;

/** What the applicability selector asks for: a mode, the companies, whether later companies join, and from when. */
final class ApplicabilitySelection
{
    /** @param  list<int>  $companyIds */
    public function __construct(
        public readonly ApplicabilityMode $mode,
        public readonly array $companyIds = [],
        public readonly bool $includeFuture = false,
        public readonly ?string $effectiveFrom = null,
    ) {}

    public static function all(bool $includeFuture = false, ?string $from = null): self
    {
        return new self(ApplicabilityMode::All, [], $includeFuture, $from);
    }

    /** @param  list<int>  $companyIds */
    public static function selected(array $companyIds, ?string $from = null): self
    {
        return new self(ApplicabilityMode::Selected, array_values(array_map('intval', $companyIds)), false, $from);
    }

    public static function single(int $companyId, ?string $from = null): self
    {
        return new self(ApplicabilityMode::Single, [$companyId], false, $from);
    }

    /** From the selector's state: ['mode' => 'selected', 'companies' => [..], 'include_future' => bool, 'effective_from' => 'Y-m-d']. */
    public static function fromState(array $state): self
    {
        $mode = ApplicabilityMode::tryFrom((string) ($state['mode'] ?? '')) ?? ApplicabilityMode::Selected;
        $companies = array_values(array_unique(array_map('intval', array_filter((array) ($state['companies'] ?? []), fn ($id) => filled($id)))));
        if ($mode === ApplicabilityMode::Single && filled($state['company'] ?? null)) {
            $companies = [(int) $state['company']];
        }

        return new self($mode, $companies, (bool) ($state['include_future'] ?? false), filled($state['effective_from'] ?? null) ? (string) $state['effective_from'] : null);
    }

    /** @return array{mode: string, companies: list<int>, include_future: bool, effective_from: ?string} */
    public function toArray(): array
    {
        return ['mode' => $this->mode->value, 'companies' => $this->companyIds, 'include_future' => $this->includeFuture, 'effective_from' => $this->effectiveFrom];
    }
}
