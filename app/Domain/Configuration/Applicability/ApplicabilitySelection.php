<?php

namespace App\Domain\Configuration\Applicability;

/**
 * What the applicability selector asks for: a mode, the companies, whether later companies join, from when, and whether
 * what the record uses (a parent department, a cost centre, …) is brought along to the companies it does not reach yet.
 */
final class ApplicabilitySelection
{
    /** @param  list<int>  $companyIds */
    public function __construct(
        public readonly ApplicabilityMode $mode,
        public readonly array $companyIds = [],
        public readonly bool $includeFuture = false,
        public readonly ?string $effectiveFrom = null,
        public readonly bool $withDependencies = false,
    ) {}

    /** The same choice, bringing along (or not) what the record uses. */
    public function bringingAlong(bool $withDependencies = true): self
    {
        return new self($this->mode, $this->companyIds, $this->includeFuture, $this->effectiveFrom, $withDependencies);
    }

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

    /** From the selector's state: ['mode' => 'selected', 'companies' => [..], 'include_future' => bool, 'effective_from' => 'Y-m-d', 'with_dependencies' => bool]. */
    public static function fromState(array $state): self
    {
        $mode = ApplicabilityMode::tryFrom((string) ($state['mode'] ?? '')) ?? ApplicabilityMode::Selected;
        $companies = array_values(array_unique(array_map('intval', array_filter((array) ($state['companies'] ?? []), fn ($id) => filled($id)))));
        if ($mode === ApplicabilityMode::Single && filled($state['company'] ?? null)) {
            $companies = [(int) $state['company']];
        }

        return new self($mode, $companies, (bool) ($state['include_future'] ?? false), filled($state['effective_from'] ?? null) ? (string) $state['effective_from'] : null,
            (bool) ($state['with_dependencies'] ?? false));
    }

    /** @return array{mode: string, companies: list<int>, include_future: bool, effective_from: ?string, with_dependencies: bool} */
    public function toArray(): array
    {
        return ['mode' => $this->mode->value, 'companies' => $this->companyIds, 'include_future' => $this->includeFuture, 'effective_from' => $this->effectiveFrom,
            'with_dependencies' => $this->withDependencies];
    }
}
