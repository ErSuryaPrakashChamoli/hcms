<?php

namespace App\Domain\Performance\Services;

use App\Domain\Performance\Models\Appraisal;
use App\Domain\Performance\Models\Goal;
use App\Domain\Performance\Models\KeyResult;
use App\Domain\Performance\Models\KpiRatingFormula;
use App\Domain\Performance\Models\RatingScale;
use App\Domain\Platform\Services\SettingsRepository;
use RuntimeException;

/**
 * HROne setup parity HP-8c (Performance → Review → KPI rating formula): the "custom" rating a goal earns from its
 * achievement. The formula is the goal's own, else its unit's. Achievement % = achievement ÷ target × 100 (or
 * target ÷ achievement for a "target ÷ achievement" formula and for every reverse KPI, where lower is better); it is
 * not capped at 100. A goal with key results uses their weighted achievement. The band containing the percentage
 * gives the rating; on a scale it is the nearest level, and a rating outside the scale is no suggestion.
 */
final class KpiRatings
{
    /** Achievement percentage above which a ratio is not meaningful (achievement of zero against a reverse target). */
    private const MAX_PCT = 1000.0;

    public function enabled(): bool
    {
        return (bool) app(SettingsRepository::class)->get('performance.review.show_formula_rating', false);
    }

    /** suggest | prefill | lock (only while the formula rating is shown). */
    public function mode(): string
    {
        $mode = (string) app(SettingsRepository::class)->get('performance.review.formula_rating_mode', 'suggest');

        return in_array($mode, ['suggest', 'prefill', 'lock'], true) ? $mode : 'suggest';
    }

    public function formulaFor(Goal $goal): ?KpiRatingFormula
    {
        $goal->loadMissing(['kpiRatingFormula', 'measurementUnit.kpiRatingFormula']);
        $formula = $goal->kpiRatingFormula ?? $goal->measurementUnit?->kpiRatingFormula;

        return $formula !== null && $formula->status === 'active' ? $formula : null;
    }

    /** Achievement % of a goal (or one key result) for a basis; null when it cannot be worked out. */
    public function achievementPct(Goal|KeyResult $subject, string $basis): ?float
    {
        if ($subject instanceof Goal) {
            $subject->loadMissing('keyResults');
            if ($subject->keyResults->isNotEmpty()) {
                $parts = $subject->keyResults->map(fn (KeyResult $kr) => [$this->achievementPct($kr, $kr->is_reverse ? 'target_over_achievement' : $basis), max(0, (int) $kr->weight)])
                    ->filter(fn ($p) => $p[0] !== null);
                if ($parts->isEmpty()) {
                    return null;
                }
                $weight = $parts->sum(fn ($p) => $p[1]);

                return round($weight > 0 ? $parts->sum(fn ($p) => $p[0] * $p[1]) / $weight : $parts->avg(fn ($p) => $p[0]), 2);
            }
        }

        $current = (float) $subject->current_value;
        $target = (float) $subject->target_value;
        if ($subject->measure_type === 'boolean') {
            return $current >= 1 ? 100.0 : 0.0;
        }
        if ($basis === 'target_over_achievement') {
            if ($current <= 0) {
                return $target > 0 ? self::MAX_PCT : null;
            }

            return round(min(self::MAX_PCT, $target / $current * 100), 2);
        }
        if ($target == 0.0) {
            return null;
        }

        return round(min(self::MAX_PCT, $current / $target * 100), 2);
    }

    /**
     * The formula rating of a goal on a scale: ['pct' => achievement %, 'rating' => rating, 'formula' => code], or null
     * when the goal has no active formula, the achievement cannot be worked out, no band contains it or the band's
     * rating is outside the scale.
     *
     * @return array{pct: float, rating: float, formula: string, reverse: bool}|null
     */
    public function suggest(Goal $goal, ?RatingScale $scale = null): ?array
    {
        $formula = $this->formulaFor($goal);
        if ($formula === null) {
            return null;
        }
        $basis = $goal->is_reverse ? 'target_over_achievement' : $formula->based_on;
        $pct = $this->achievementPct($goal, $basis);
        $rating = $pct === null ? null : $formula->ratingFor($pct);
        if ($rating === null || ($scale !== null && ($rating < $scale->min() || $rating > $scale->max()))) {
            return null;
        }
        if ($scale !== null && $scale->levels !== []) {
            // Reviews rate on the scale's levels: the formula rating is the nearest level.
            $rating = (float) collect($scale->levels)->sortBy(fn ($l) => abs((float) $l['value'] - $rating))->first()['value'];
        }

        return ['pct' => $pct, 'rating' => $rating, 'formula' => $formula->code, 'reverse' => (bool) $goal->is_reverse];
    }

    /** Whether reviewers must keep the formula rating (formula rating shown and mode lock). */
    public function locks(): bool
    {
        return $this->enabled() && $this->mode() === 'lock';
    }

    /**
     * Server-side check of formula_rating_mode = lock: a goal with a formula rating must be rated exactly that.
     *
     * @param  array<int|string, float|null>  $goalRatings  goal id => rating
     */
    public function assertLockedRatings(Appraisal $appraisal, array $goalRatings, RatingScale $scale): void
    {
        if (! $this->locks() || $goalRatings === []) {
            return;
        }
        Goal::query()->whereIn('id', array_keys($goalRatings))->where('employee_id', $appraisal->employee_id)->get()
            ->each(function (Goal $goal) use ($goalRatings, $scale) {
                $given = $goalRatings[$goal->id] ?? null;
                $suggestion = $this->suggest($goal, $scale);
                if ($given !== null && $suggestion !== null && abs((float) $given - $suggestion['rating']) > 0.001) {
                    throw new RuntimeException("The rating of \"{$goal->title}\" is set by its KPI rating formula ({$suggestion['rating']}).");
                }
            });
    }
}
