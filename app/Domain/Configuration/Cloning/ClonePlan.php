<?php

namespace App\Domain\Configuration\Cloning;

/**
 * Everything a clone would do, worked out without writing anything (ConfigurationCloner::plan()): the source snapshot
 * and its checksum, the mode, the companies the source reaches today, every dependency and its strategy, the rewritten
 * applicability per destination (with what was mapped, dropped or not copied, and why), its impact today, and the
 * conflicts to fix or acknowledge. execute() rebuilds it inside the transaction and refuses when it changed.
 */
final class ClonePlan
{
    /**
     * @param  list<int>|null  $sourceCompanies  null = the source applies to every company
     * @param  list<Reference>  $references
     * @param  array<int, array{before: array<string, mixed>, after: array<string, mixed>, notes: list<string>, mapped: list<array{field: string, source_id: int, target_id: int}>, unresolved: list<array{field: string, source_id: int, name: string}>}>  $applicability  by destination company id
     * @param  array<int, array{employees: int, matched: int, winning: int, capped: bool}>  $impact  by destination company id
     * @param  list<Conflict>  $conflicts
     */
    public function __construct(
        public readonly CloneRequest $request,
        public readonly Snapshot $snapshot,
        public readonly CloneMode $mode,
        public readonly ?array $sourceCompanies,
        public readonly array $references,
        public readonly array $applicability,
        public readonly array $impact = [],
        public readonly array $conflicts = [],
    ) {}

    public function checksum(): string
    {
        return $this->snapshot->checksum();
    }

    /** @param  list<Conflict>  $conflicts */
    public function withConflicts(array $conflicts): self
    {
        return new self($this->request, $this->snapshot, $this->mode, $this->sourceCompanies, $this->references, $this->applicability, $this->impact, $conflicts);
    }

    /** @param  array<int, array{employees: int, matched: int, winning: int, capped: bool}>  $impact */
    public function withImpact(array $impact): self
    {
        return new self($this->request, $this->snapshot, $this->mode, $this->sourceCompanies, $this->references, $this->applicability, $impact, $this->conflicts);
    }

    /** @return list<Conflict> */
    public function blockers(): array
    {
        return array_values(array_filter($this->conflicts, fn (Conflict $c) => $c->isBlocker()));
    }

    /** @return list<Conflict> */
    public function warnings(): array
    {
        return array_values(array_filter($this->conflicts, fn (Conflict $c) => ! $c->isBlocker()));
    }

    /** @return array<string, mixed> the destination's applicability after the clone (rule kinds: ['rules' => list]) */
    public function applicabilityFor(int $companyId): array
    {
        return (array) ($this->applicability[$companyId]['after'] ?? []);
    }

    /** @return list<array<string, mixed>> the rules a rule-based copy gets in this company */
    public function rulesFor(int $companyId): array
    {
        return array_values((array) ($this->applicabilityFor($companyId)['rules'] ?? []));
    }

    public function destination(int $companyId): ?Destination
    {
        foreach ($this->request->destinations as $destination) {
            if ($destination->companyId === $companyId) {
                return $destination;
            }
        }

        return null;
    }

    /** The reason written on every row the clone creates. */
    public function auditReason(): string
    {
        $reason = trim($this->request->reason);

        return 'Cloned from '.$this->snapshot->label.($reason === '' ? '' : ": {$reason}");
    }

    /** @return array<string, mixed> the manifest kept on the clone record and in the audit trail */
    public function toArray(): array
    {
        return [
            'mode' => $this->mode->value,
            'source_companies' => $this->sourceCompanies,
            'destinations' => array_map(fn (Destination $d) => $d->toArray(), $this->request->destinations),
            'options' => $this->request->options,
            'references' => array_map(fn (Reference $r) => $r->toArray(), $this->references),
            'applicability' => $this->applicability,
            'impact' => $this->impact,
            'conflicts' => array_map(fn (Conflict $c) => $c->toArray(), $this->conflicts),
            'acknowledged' => $this->request->acknowledged,
        ];
    }
}
