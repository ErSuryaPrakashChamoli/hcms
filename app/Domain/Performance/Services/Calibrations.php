<?php

namespace App\Domain\Performance\Services;

use App\Domain\Audit\Enums\AuditAction;
use App\Domain\Audit\Services\AuditRecorder;
use App\Domain\Identity\Models\User;
use App\Domain\Identity\Scopes\AccessScope;
use App\Domain\Performance\Events\PerformanceEvent;
use App\Domain\Performance\Models\Appraisal;
use App\Domain\Performance\Models\CalibrationAdjustment;
use App\Domain\Performance\Models\CalibrationSession;
use App\Domain\Performance\Models\PerformanceCycle;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Phase 7 calibration: sessions over a population of appraisals and an immutable history of every
 * rating change (original, previous, adjusted, reason, actor, time). Optimistic concurrency: the
 * caller states the rating it saw; if someone changed it meanwhile the adjustment is refused.
 */
final class Calibrations
{
    public function __construct(private readonly AuditRecorder $audit) {}

    /** @param  list<int>|null  $appraisalIds  null = every appraisal in the cycle */
    public function openSession(PerformanceCycle $cycle, string $name, ?array $appraisalIds = null, array $participantUserIds = [], ?User $actor = null): CalibrationSession
    {
        if ($cycle->status !== 'active') {
            throw new RuntimeException('Calibration runs on an active cycle.');
        }
        $inCycle = Appraisal::query()->withoutGlobalScope(AccessScope::class)->where('performance_cycle_id', $cycle->id)->pluck('id')->map(fn ($id) => (int) $id);
        $population = $appraisalIds === null ? $inCycle : collect($appraisalIds)->map(fn ($id) => (int) $id)->unique();
        if ($population->isEmpty() || $population->diff($inCycle)->isNotEmpty()) {
            throw new RuntimeException('The population must be appraisals of this cycle.');
        }

        $session = CalibrationSession::query()->create([
            'performance_cycle_id' => $cycle->id, 'name' => $name, 'facilitator_id' => $actor?->id ?? auth()->id(),
            'participants' => array_values(array_unique(array_map('intval', $participantUserIds))), 'population' => $population->values()->all(),
        ]);
        $this->audit->record(AuditAction::Create, 'performance', $session, [], null, actor: $actor, metadata: ['population' => $population->count()]);

        return $session;
    }

    public function closeSession(CalibrationSession $session, ?User $actor = null): CalibrationSession
    {
        $session->update(['status' => 'closed', 'closed_by' => $actor?->id ?? auth()->id(), 'closed_at' => now()]);

        return $session;
    }

    /**
     * Record a calibrated rating. $expected is the rating the decision-maker saw (calibrated, else
     * computed); a mismatch means someone else changed it first.
     */
    public function adjust(Appraisal $appraisal, float $rating, string $reason, ?float $expected = null, ?CalibrationSession $session = null, ?User $actor = null): CalibrationAdjustment
    {
        if (trim($reason) === '') {
            throw new RuntimeException('A calibration note is required.');
        }
        $cycle = PerformanceCycle::query()->with('templateVersion')->findOrFail($appraisal->performance_cycle_id);
        if ($cycle->hasStage('calibration') && ! $cycle->isAtOrPast('calibration')) {
            throw new RuntimeException('Calibration has not opened yet.');
        }
        $scale = $cycle->ratingScale();
        if ($rating < $scale->min() || $rating > $scale->max()) {
            throw new RuntimeException("Ratings must be between {$scale->min()} and {$scale->max()}.");
        }
        if ($session !== null) {
            if ($session->status !== 'open') {
                throw new RuntimeException('The calibration session is closed.');
            }
            if ((int) $session->performance_cycle_id !== (int) $cycle->id || ! $session->includes($appraisal->id)) {
                throw new RuntimeException('This appraisal is not in the session population.');
            }
        }

        return DB::transaction(function () use ($appraisal, $rating, $reason, $expected, $session, $actor, $cycle) {
            $current = Appraisal::query()->withoutGlobalScope(AccessScope::class)->whereKey($appraisal->id)->lockForUpdate()->firstOrFail();
            if ($current->isFinal() || $current->isLocked()) {
                throw new RuntimeException('The appraisal is finalized.');
            }
            $previous = $current->calibrated_rating ?? $current->computed_rating;
            if ($expected !== null && ($previous === null || abs((float) $previous - $expected) > 0.001)) {
                throw new RuntimeException('The rating was changed by someone else. Reload and try again.');
            }

            $adjustment = CalibrationAdjustment::query()->create([
                'calibration_session_id' => $session?->id, 'appraisal_id' => $appraisal->id,
                'original_rating' => $current->computed_rating, 'previous_rating' => $previous, 'adjusted_rating' => $rating,
                'reason' => $reason, 'actor_id' => $actor?->id ?? auth()->id(),
            ]);
            $appraisal->update(['calibrated_rating' => $rating, 'calibration_note' => $reason, 'status' => 'calibration']);
            $this->audit->record(AuditAction::Update, 'performance', $appraisal, [['field' => 'calibrated_rating', 'before' => $previous, 'after' => $rating]], $reason, actor: $actor, metadata: ['calibration_session_id' => $session?->id, 'adjustment_id' => $adjustment->id]);
            PerformanceEvent::dispatch('performance.calibration.decision_recorded', null, $adjustment, ['cycle' => $cycle->name], []);

            return $adjustment;
        });
    }
}
