<?php

namespace App\Domain\Performance\Contracts;

/**
 * Phase 7 read contract for a future Compensation module: the finalized outcome of a cycle for an
 * employee. Read-only. Performance never writes salary, payroll, statutory or compensation data,
 * and nothing is returned until a person has finalized the appraisal.
 */
interface PerformanceOutcomesReader
{
    /** @return array{cycle_code: string, period_end: string, final_rating: float, final_label: ?string, promotion_recommended: bool, finalized_at: string, template_version_id: ?int, rating_scale_checksum: ?string}|null */
    public function finalOutcome(int $employeeId, int $cycleId): ?array;
}
