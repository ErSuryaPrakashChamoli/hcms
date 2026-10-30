<?php

namespace App\Domain\Performance\Services;

use App\Domain\Audit\Services\AuditRecorder;
use App\Domain\Identity\Models\User;
use App\Domain\Identity\Scopes\AccessScope;
use App\Domain\Performance\Models\Appraisal;
use App\Domain\Performance\Models\PerformanceCycle;
use App\Domain\Performance\Models\PerformanceDistributionTarget;
use App\Domain\Performance\Models\RatingScale;
use App\Domain\Performance\Support\RatingRules;
use App\Domain\Platform\Services\SettingsRepository;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * HROne setup parity HP-8g (Performance → Bell curve): the target share of employees per rating level and how a cycle's
 * actual distribution compares with it. Targets belong to a cycle or (no cycle) are the organisation's default for every
 * cycle whose rating scale has those levels; a cycle's own targets win. They are an aid to calibration, never a rule that
 * moves anyone's rating: nothing here changes an appraisal.
 *
 * The check (setting performance.distribution.check): off (the default — no check, as before), warn (the gap is shown
 * when an appraisal is finalized and when the cycle closes; the cycle still closes) or block (a cycle whose distribution
 * differs from a target by more than performance.distribution.tolerance_pct points does not close).
 */
final class PerformanceDistribution
{
    public function __construct(private readonly AuditRecorder $audit, private readonly SettingsRepository $settings) {}

    public function mode(): string
    {
        $mode = (string) $this->settings->get('performance.distribution.check', 'off');

        return array_key_exists($mode, (array) config('peopleos.performance.distribution_checks', [])) ? $mode : 'off';
    }

    /** Percentage points an actual share may differ from its target. */
    public function tolerance(): float
    {
        return max(0.0, min(100.0, (float) $this->settings->get('performance.distribution.tolerance_pct', 5)));
    }

    /**
     * The targets that apply to a cycle: its own, else the default ones naming levels of its scale (when they cover it,
     * i.e. still add up to 100). Keys are level values as strings ("3", "3.5").
     *
     * @return array<string, float>
     */
    public function targets(PerformanceCycle $cycle): array
    {
        return $this->resolved($cycle)['targets'];
    }

    /** Where a cycle's targets come from: cycle, default, or null (none). */
    public function source(PerformanceCycle $cycle): ?string
    {
        return $this->resolved($cycle)['source'];
    }

    /** @return array{targets: array<string, float>, source: ?string} */
    private function resolved(PerformanceCycle $cycle): array
    {
        $own = $this->rows($cycle->id);
        if ($own !== []) {
            return ['targets' => $own, 'source' => 'cycle'];
        }
        $levels = $this->levelKeys($cycle->ratingScale());
        $default = array_intersect_key($this->rows(null), array_flip($levels));
        if ($default === [] || abs(array_sum($default) - 100) > 0.01) {
            return ['targets' => [], 'source' => null];
        }

        return ['targets' => $default, 'source' => 'default'];
    }

    /** @return array<string, float> */
    private function rows(?int $cycleId): array
    {
        return PerformanceDistributionTarget::query()
            ->when($cycleId === null, fn ($q) => $q->whereNull('performance_cycle_id'), fn ($q) => $q->where('performance_cycle_id', $cycleId))
            ->orderBy('level_value')->get()
            ->mapWithKeys(fn (PerformanceDistributionTarget $t) => [self::key($t->level_value) => (float) $t->target_pct])->all();
    }

    /** The default targets as saved (level value => %), for the form. @return array<string, float> */
    public function defaultTargets(): array
    {
        return $this->rows(null);
    }

    /** The cycle's own targets as saved (level value => %), for the form. @return array<string, float> */
    public function cycleTargets(PerformanceCycle $cycle): array
    {
        return $this->rows($cycle->id);
    }

    /**
     * Replace a cycle's targets (or the default ones, $cycle null). Every level of the scale gets a share, blank = 0; the
     * shares add up to 100. All blank (or an empty list) removes the targets. Needs performance.manage. Returns the number
     * of levels saved.
     *
     * @param  array<string|int, float|int|string|null>  $targets  level value => target %
     */
    public function saveTargets(?PerformanceCycle $cycle, array $targets, User $actor): int
    {
        if (! $actor->hasPermission('performance.manage')) {
            throw new RuntimeException('You cannot set distribution targets.');
        }
        $scale = $cycle?->ratingScale() ?? RatingScale::default();
        if ($scale === null) {
            throw new RuntimeException('There is no rating scale to set targets for.');
        }
        $levels = $this->levelKeys($scale);
        $clean = [];
        foreach ($targets as $level => $pct) {
            $key = self::key((float) $level);
            if (! in_array($key, $levels, true)) {
                throw new RuntimeException("{$level} is not a level of the rating scale {$scale->name}.");
            }
            if ($pct === null || $pct === '') {
                continue;
            }
            if (! is_numeric($pct) || (float) $pct < 0 || (float) $pct > 100) {
                throw new RuntimeException('A target is a percentage between 0 and 100.');
            }
            $clean[$key] = round((float) $pct, 2);
        }
        $clear = array_sum($clean) == 0.0;
        if (! $clear && abs(array_sum($clean) - 100) > 0.01) {
            throw new RuntimeException('The targets add up to '.self::format(array_sum($clean)).'%; they must add up to 100%.');
        }

        $saved = 0;
        $this->audit->operation('performance', $cycle ? "Distribution targets: {$cycle->name}" : 'Distribution targets: default', function () use ($cycle, $levels, $clean, $clear, &$saved) {
            // Retried on a deadlock: two first saves of the same set can both find no row to lock.
            $saved = DB::transaction(function () use ($cycle, $levels, $clean, $clear) {
                $existing = PerformanceDistributionTarget::query()
                    ->when($cycle === null, fn ($q) => $q->whereNull('performance_cycle_id'), fn ($q) => $q->where('performance_cycle_id', $cycle->id))
                    ->lockForUpdate()->get();
                $existing->each(fn (PerformanceDistributionTarget $t) => $t->delete());
                if ($clear) {
                    return 0;
                }
                foreach ($levels as $level) {
                    PerformanceDistributionTarget::query()->create(['performance_cycle_id' => $cycle?->id, 'level_value' => (float) $level, 'target_pct' => $clean[$level] ?? 0]);
                }

                return count($levels);
            }, 3);

            return $saved;
        });

        return $saved;
    }

    /**
     * Actual against target per level of the cycle's scale. Ratings are the ones that stand (final, else calibrated, else
     * computed), placed at the level whose label they take. $appraisals defaults to every appraisal of the cycle the
     * caller may see.
     *
     * @param  Collection<int, Appraisal>|null  $appraisals
     * @return array{rows: list<array{value: float, label: string, target: ?float, count: int, actual: float, gap: ?float, outside: bool}>, rated: int, total: int, has_targets: bool, outside: bool, tolerance: float, mode: string, source: ?string}
     */
    public function compare(PerformanceCycle $cycle, ?Collection $appraisals = null): array
    {
        $scale = $cycle->ratingScale();
        $appraisals ??= Appraisal::query()->where('performance_cycle_id', $cycle->id)->get();
        $resolved = $this->resolved($cycle);
        $targets = $resolved['targets'];
        $tolerance = $this->tolerance();

        $counts = [];
        $rated = 0;
        foreach ($appraisals as $appraisal) {
            $level = $this->levelOf($scale, $appraisal->effectiveRating());
            if ($level !== null) {
                $counts[$level] = ($counts[$level] ?? 0) + 1;
                $rated++;
            }
        }

        $rows = [];
        foreach (collect($scale->levels)->sortBy('value') as $level) {
            $key = self::key((float) $level['value']);
            $count = $counts[$key] ?? 0;
            $actual = $rated === 0 ? 0.0 : round($count / $rated * 100, 1);
            $target = $targets === [] ? null : (float) ($targets[$key] ?? 0);
            $gap = $target === null ? null : round($actual - $target, 1);
            $rows[] = ['value' => (float) $level['value'], 'label' => (string) $level['label'], 'target' => $target, 'count' => $count, 'actual' => $actual, 'gap' => $gap,
                'outside' => $gap !== null && $rated > 0 && abs($gap) > $tolerance + 0.0001];
        }

        return ['rows' => $rows, 'rated' => $rated, 'total' => $appraisals->count(), 'has_targets' => $targets !== [], 'outside' => collect($rows)->contains('outside', true),
            'tolerance' => $tolerance, 'mode' => $this->mode(), 'source' => $resolved['source']];
    }

    /**
     * The gap message for a cycle, when the check is on (warn or block), the cycle has targets and a level is outside the
     * tolerance; null otherwise. Read over every appraisal of the cycle (not the caller's scope).
     */
    public function warning(PerformanceCycle $cycle): ?string
    {
        if ($this->mode() === 'off') {
            return null;
        }
        $comparison = $this->compare($cycle, Appraisal::query()->withoutGlobalScope(AccessScope::class)->where('performance_cycle_id', $cycle->id)->get());
        if (! $comparison['has_targets'] || ! $comparison['outside']) {
            return null;
        }
        $gaps = collect($comparison['rows'])->where('outside', true)
            ->map(fn (array $r) => "{$r['label']} ".self::format($r['actual']).'% (target '.self::format($r['target']).'%)')->implode(', ');

        return "The rating distribution of {$cycle->name} differs from its targets by more than ".self::format($comparison['tolerance'])." points: {$gaps}.";
    }

    /** HP-8g: the cycle may close unless the check blocks it and the distribution is outside the tolerance. */
    public function assertClosable(PerformanceCycle $cycle): void
    {
        if ($this->mode() === 'block' && ($warning = $this->warning($cycle)) !== null) {
            throw new RuntimeException($warning.' Calibrate the ratings, or change the targets, before closing the cycle.');
        }
    }

    /** The level (value key) whose label a rating takes on the scale, by the organisation's rounding rule. */
    public function levelOf(RatingScale $scale, ?float $rating): ?string
    {
        $label = RatingRules::label($scale, $rating);
        if ($label === null) {
            return null;
        }
        $level = collect($scale->levels)->firstWhere('label', $label);

        return $level === null ? null : self::key((float) $level['value']);
    }

    /** @return list<string> */
    private function levelKeys(RatingScale $scale): array
    {
        return collect($scale->levels)->map(fn ($l) => self::key((float) $l['value']))->values()->all();
    }

    public static function key(float $value): string
    {
        return self::format($value);
    }

    public static function format(?float $value): string
    {
        return $value === null ? '—' : rtrim(rtrim(number_format($value, 2, '.', ''), '0'), '.');
    }
}
