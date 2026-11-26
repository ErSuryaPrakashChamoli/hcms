<?php

namespace App\Domain\Configuration\Applicability;

/**
 * What an applicability change would do, worked out before anything is written (the review step): the companies before
 * and after, who joins and who leaves, the dependency and usage problems that block it, warnings, whether the change
 * touches a configuration shared by several companies, what it uses that could be brought along to the new companies
 * (and, when asked, the plans that bring them along), and the company a company-owned record moves to when it leaves its
 * own.
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
     * @param  list<array{type: string, id: int, label: string, typeLabel: string, missing: list<int>}>  $extendable  records it uses that do not reach every company it will
     * @param  list<ApplicabilityPlan>  $extensions  the plans that bring them along (selection->withDependencies)
     * @param  int|null  $ownerAfter  the company a company-owned record belongs to afterwards, when it moves
     * @param  string|null  $earliest  for a back-dated change, the earliest day it could start (EntityApplicability::earliestStart())
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
        public readonly array $extendable = [],
        public readonly array $extensions = [],
        public readonly ?int $ownerAfter = null,
        public readonly string $typeLabel = '',
        public readonly ?string $earliest = null,
    ) {}

    /** Whether it starts before today (back-dated, within the payroll months still open: EntityApplicability::earliestStart()). */
    public function backdated(): bool
    {
        return $this->effectiveFrom < today()->toDateString();
    }

    /** Whether what it uses could be brought along to the companies it is missing (the review offers the choice). */
    public function canBringAlong(): bool
    {
        return $this->extendable !== [];
    }

    /**
     * One line per record brought along, nested ones included: 'Department "Marketing (MKT)" will also apply to FynnEdge'.
     *
     * @return list<string>
     */
    public function broughtAlong(): array
    {
        $lines = [];
        foreach ($this->extensions as $extension) {
            $lines[] = ($extension->typeLabel !== '' ? $extension->typeLabel.' ' : '')."\"{$extension->label}\" will also apply to ".$this->names($extension->added).', because '
                .($this->typeLabel !== '' ? 'this '.strtolower($this->typeLabel) : 'it').' uses it.';
            array_push($lines, ...$extension->broughtAlong());
        }

        return array_values(array_unique($lines));
    }

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
        ] + ($this->extensions === [] ? [] : ['brought_along' => array_map(fn (ApplicabilityPlan $e) => ['type' => $e->type, 'id' => $e->recordId, 'added' => $e->added], $this->extensions)])
          + ($this->ownerAfter === null ? [] : ['owner_after' => $this->ownerAfter])
          + ($this->backdated() ? ['backdated' => true, 'earliest' => $this->earliest] : []);
    }
}
