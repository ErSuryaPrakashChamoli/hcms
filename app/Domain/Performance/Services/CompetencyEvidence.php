<?php

namespace App\Domain\Performance\Services;

use App\Domain\Performance\Contracts\CompetencyEvidenceReader;
use App\Domain\Performance\Models\Appraisal;

/** Phase 9: read-only implementation of CompetencyEvidenceReader. */
final class CompetencyEvidence implements CompetencyEvidenceReader
{
    public function latestFinalized(int $employeeId): array
    {
        $appraisal = Appraisal::query()->with(['cycle.templateVersion', 'cycle.scale', 'reviews.ratings'])
            ->where('employee_id', $employeeId)->whereIn('status', ['finalized', 'acknowledged'])
            ->orderByDesc('finalized_at')->first();
        $review = $appraisal?->reviews->firstWhere('type', 'manager');
        if ($review === null || ! $review->isSubmitted()) {
            return [];
        }
        $scale = $appraisal->cycle->ratingScale();

        return $review->ratings->where('subject_type', 'competency')->mapWithKeys(fn ($r) => [(int) $r->subject_id => [
            'rating' => (float) $r->rating, 'label' => $scale->labelFor((float) $r->rating), 'scale_max' => (float) $scale->max(),
            'cycle' => $appraisal->cycle->code, 'finalized_at' => $appraisal->finalized_at?->toIso8601String(),
        ]])->all();
    }
}
