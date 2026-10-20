<?php

namespace App\Domain\Performance\Contracts;

/**
 * Phase 9 read contract (additive): the latest finalized competency ratings of an employee, from
 * the manager review of their most recent finalized appraisal, on that cycle's pinned scale.
 * Read-only; nothing is returned before a person finalizes the appraisal.
 */
interface CompetencyEvidenceReader
{
    /** @return array<int, array{rating: float, label: ?string, scale_max: float, cycle: string, finalized_at: ?string}> competency id => evidence */
    public function latestFinalized(int $employeeId): array;
}
