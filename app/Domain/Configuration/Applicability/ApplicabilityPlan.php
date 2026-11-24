<?php

namespace App\Domain\Configuration\Applicability;

/**
 * What an applicability change would do, worked out before anything is written (the review step): the companies before
 * and after, who joins and who leaves, the dependency and usage problems that block it, warnings, and whether the change
 * touches a configuration shared by several companies.
 */
final class ApplicabilityPlan
{
    /**
     * @param  list<int>|null  $before  null = every company (the earlier, tenant-wide meaning)
     * @param  list<int>  $after
     * @param  list<int>  $added
     * @param  list<int>  $removed
     * @param  list<string>  $blockers
     * @param  list<string>  $warnings
     * @param  array<int, string>  $companyNames
     */
    public function __construct(
        public readonly string $type,
        public readonly int $recordId,
        public readonly string $label,
        public readonly bool $explicitBefore,
        public readonly ?ApplicabilityMode $modeBefore,
        public readonly ?array $before,
        public readonly ApplicabilitySelection $selection,
        public readonly array $after,
        public readonly array $added,
        public readonly array $removed,
        public readonly string $effectiveFrom,
        public readonly array $blockers,
        public readonly array $warnings,
        public readonly array $companyNames,
    ) {}

    public function changesCompanies(): bool
    {
        return $this->added !== [] || $this->removed !== [];
    }

    public function sharedBefore(): bool
    {
        return $this->before === null || count($this->before) > 1;
    }

    public function sharedAfter(): bool
    {
        return count($this->after) > 1 || $this->selection->includeFuture;
    }

    /** Operations reaching several companies ask for an explicit confirmation. */
    public function needsConfirmation(): bool
    {
        return $this->sharedBefore() || $this->sharedAfter();
    }

    public function blocked(): bool
    {
        return $this->blockers !== [];
    }

    /** @param  list<int>|null  $ids */
    public function names(?array $ids): string
    {
        if ($ids === null) {
            return 'Every company';
        }
        if ($ids === []) {
            return 'No company';
        }

        return implode(', ', array_map(fn (int $id) => $this->companyNames[$id] ?? "Company #{$id}", $ids));
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'type' => $this->type, 'record_id' => $this->recordId, 'label' => $this->label, 'explicit_before' => $this->explicitBefore,
            'mode_before' => $this->modeBefore?->value, 'before' => $this->before, 'selection' => $this->selection->toArray(), 'after' => $this->after,
            'added' => $this->added, 'removed' => $this->removed, 'effective_from' => $this->effectiveFrom, 'blockers' => $this->blockers, 'warnings' => $this->warnings,
        ];
    }
}
