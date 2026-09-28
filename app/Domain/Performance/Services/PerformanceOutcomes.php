<?php

namespace App\Domain\Performance\Services;

use App\Domain\Performance\Contracts\PerformanceOutcomesReader;
use App\Domain\Performance\Models\Appraisal;

/** Phase 7: read-only outcome contract (see PerformanceOutcomesReader). */
final class PerformanceOutcomes implements PerformanceOutcomesReader
{
    public function finalOutcome(int $employeeId, int $cycleId): ?array
    {
        $appraisal = Appraisal::query()->with(['cycle', 'templateVersion'])->where('employee_id', $employeeId)->where('performance_cycle_id', $cycleId)
            ->whereIn('status', ['finalized', 'acknowledged'])->whereNotNull('final_rating')->first();
        if ($appraisal === null) {
            return null;
        }

        return [
            'cycle_code' => $appraisal->cycle->code,
            'period_end' => $appraisal->cycle->period_end->toDateString(),
            'final_rating' => (float) $appraisal->final_rating,
            'final_label' => $appraisal->final_label,
            'promotion_recommended' => (bool) $appraisal->promotion_recommended,
            'finalized_at' => $appraisal->finalized_at?->toIso8601String(),
            'template_version_id' => $appraisal->performance_template_version_id,
            'rating_scale_checksum' => $appraisal->templateVersion?->checksum,
        ];
    }
}
