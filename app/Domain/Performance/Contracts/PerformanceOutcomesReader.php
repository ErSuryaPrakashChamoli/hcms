<?php

namespace App\Domain\Performance\Contracts;

/**
 * Phase 7 read contract for a future Compensation module: the finalized outcome of a cycle for an
 * employee. Read-only. Performance never writes salary, payroll, statutory or compensation data,
 * and nothing is returned until a person has finalized the appraisal.
 */
interface PerformanceOutcomesReader
{
    /**
     * HROne setup parity HP-8i: `recommendations` carries what the reviewers recommended under the performance policy
     * (training needs, promotion, increment, variable pay) — read-only suggestions; null when there are none.
     *
     * @return array{cycle_code: string, period_end: string, final_rating: float, final_label: ?string, promotion_recommended: bool, finalized_at: string, template_version_id: ?int, rating_scale_checksum: ?string, recommendations: ?array{training: ?array, promotion: ?bool, increment: ?array{type: string, value: float}, variable: ?array{type: string, value: float}}}|null
     */
    public function finalOutcome(int $employeeId, int $cycleId): ?array;
}
