<?php

namespace App\Domain\Performance\Services;

use App\Domain\Identity\Models\User;
use App\Domain\Identity\Scopes\AccessScope;
use App\Domain\Performance\Models\Appraisal;
use App\Domain\Performance\Models\Competency;
use App\Domain\Performance\Models\DevelopmentNeed;
use Throwable;

/**
 * HROne setup parity HP-8i (PF-9): reviewers' recommendations on an appraisal. Offered read-only to Compensation
 * (PerformanceOutcomesReader::finalOutcome()['recommendations']: a compensation cycle that names the performance cycle
 * shows the suggested increment on each line) and to Learning (a finalized appraisal's training needs become open development needs, source
 * "appraisal", which Learning reads through DevelopmentNeedsReader). Nothing here pays, promotes or enrols anyone.
 */
final class PerformanceRecommendations
{
    /** @return array{training: ?array, promotion: ?bool, increment: ?array, variable: ?array}|null the finalized appraisal's recommendations */
    public static function forOutcome(Appraisal $appraisal): ?array
    {
        $recommendations = (array) ($appraisal->recommendations ?? []);
        if ($recommendations === []) {
            return null;
        }

        return [
            'training' => $recommendations['training'] ?? null,
            'promotion' => array_key_exists('promotion', $recommendations) ? (bool) $recommendations['promotion'] : null,
            'increment' => $recommendations['increment'] ?? null,
            'variable' => $recommendations['variable'] ?? null,
        ];
    }

    /**
     * At finalization: the training needs recommended become development needs of the employee (once per appraisal and
     * competency; a note alone becomes one need). A failure never blocks the finalization.
     */
    public function recordTrainingNeeds(Appraisal $appraisal, ?User $actor): int
    {
        $training = ((array) ($appraisal->recommendations ?? []))['training'] ?? null;
        if (! is_array($training)) {
            return 0;
        }
        $created = 0;
        try {
            $exists = fn (?int $competencyId) => DevelopmentNeed::query()->withoutGlobalScope(AccessScope::class)->where('employee_id', $appraisal->employee_id)
                ->where('source_type', 'appraisal')->where('source_id', $appraisal->id)->where('competency_id', $competencyId)->exists();
            $note = trim((string) ($training['note'] ?? ''));
            $competencies = Competency::query()->whereIn('id', (array) ($training['competency_ids'] ?? []) ?: [0])->get();
            foreach ($competencies as $competency) {
                if (! $exists($competency->id)) {
                    DevelopmentNeed::query()->create(['employee_id' => $appraisal->employee_id, 'title' => 'Training: '.$competency->name, 'description' => $note !== '' ? $note : null,
                        'source_type' => 'appraisal', 'source_id' => $appraisal->id, 'competency_id' => $competency->id, 'priority' => 'medium', 'reason' => 'Recommended in the appraisal (TNI)', 'created_by' => $actor?->id]);
                    $created++;
                }
            }
            if ($competencies->isEmpty() && $note !== '' && ! $exists(null)) {
                DevelopmentNeed::query()->create(['employee_id' => $appraisal->employee_id, 'title' => mb_strimwidth('Training: '.$note, 0, 200, '…'), 'description' => $note,
                    'source_type' => 'appraisal', 'source_id' => $appraisal->id, 'priority' => 'medium', 'reason' => 'Recommended in the appraisal (TNI)', 'created_by' => $actor?->id]);
                $created++;
            }
        } catch (Throwable $e) {
            report($e);
        }

        return $created;
    }
}
