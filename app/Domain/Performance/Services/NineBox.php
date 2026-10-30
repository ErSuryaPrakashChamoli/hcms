<?php

namespace App\Domain\Performance\Services;

use App\Domain\Audit\Services\AuditRecorder;
use App\Domain\Employment\Models\EmployeePosition;
use App\Domain\Identity\Models\User;
use App\Domain\Performance\Models\Appraisal;
use App\Domain\Performance\Models\PerformanceCycle;
use App\Domain\Performance\Models\PerformanceNineBoxBand;
use App\Domain\Performance\Models\RatingScale;
use App\Domain\Platform\Services\SettingsRepository;
use App\Domain\Talent\Models\TalentAssessment;
use App\Domain\Talent\Models\TalentAssessmentModel;
use App\Domain\Talent\Models\TalentAssessmentModelVersion;
use App\Domain\Talent\Services\TalentAccess;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * HROne setup parity HP-8g (Performance → 9 box): places the employees of a cycle on a 3 × 3 grid of performance against
 * potential. Performance is the appraisal's rating that stands (final, else calibrated, else computed — marked
 * provisional until final) on the cycle's rating scale. Potential (setting performance.nine_box.potential_source) is the
 * appraisal's competency rating on the cycle's competency scale (the default), or the "potential" of the employee's
 * latest final talent assessment on the default talent model — talent data, shown only to people who may see it
 * (TalentAccess: talent.view in scope, never about oneself, never a manager by relationship alone).
 *
 * Each axis has three bands (low / moderate / high) for the cycle, else the organisation's default bands, else the
 * thirds of the axis's scale. Who sees whom: HR (performance.view / performance.calibrate) every appraisal in their
 * access scope by name; performance managers (performance.team) the people they manage by name; analytics viewers
 * (performance.analytics) only counts, suppressed below performance.analytics_min_group. It places, it never ranks or
 * decides anything about anyone.
 */
final class NineBox
{
    public function __construct(
        private readonly AuditRecorder $audit,
        private readonly SettingsRepository $settings,
        private readonly PerformanceRelationships $relationships,
        private readonly TalentAccess $talent,
    ) {}

    public function potentialSource(): string
    {
        $source = (string) $this->settings->get('performance.nine_box.potential_source', 'competencies');

        return array_key_exists($source, (array) config('peopleos.performance.nine_box.potential_sources', [])) ? $source : 'competencies';
    }

    /** How the viewer sees the grid: all (names, in scope), team (names, their reports), counts (no names), or null. */
    public static function viewMode(?User $user): ?string
    {
        if ($user === null) {
            return null;
        }
        if ($user->hasPermission('performance.view') || $user->hasPermission('performance.calibrate')) {
            return 'all';
        }
        if ($user->hasPermission('performance.team')) {
            return 'team';
        }

        return $user->hasPermission('performance.analytics') ? 'counts' : null;
    }

    public static function minGroup(): int
    {
        return max(1, (int) config('peopleos.performance.analytics_min_group', 5));
    }

    // --- Axes and bands ------------------------------------------------------------------------

    /**
     * The two axes of a cycle: their scale range and bands.
     *
     * @return array{performance: array{min: float, max: float, label: string, source: string, bands: array<string, array{from: float, to: float}>}, potential: array{min: float, max: float, label: string, source: string, bands: array<string, array{from: float, to: float}>}}
     */
    public function axes(PerformanceCycle $cycle): array
    {
        $performance = $cycle->ratingScale();
        [$pMin, $pMax, $pLabel] = $this->potentialRange($cycle);

        return [
            'performance' => $this->axis($cycle, 'performance', $performance->min(), $performance->max(), 'Performance ('.$performance->name.')'),
            'potential' => $this->axis($cycle, 'potential', $pMin, $pMax, $pLabel),
        ];
    }

    /** @return array{min: float, max: float, label: string, source: string, bands: array<string, array{from: float, to: float}>} */
    private function axis(PerformanceCycle $cycle, string $axis, float $min, float $max, string $label): array
    {
        foreach ([$cycle->id, null] as $cycleId) {
            $rows = $this->bandRows($cycleId, $axis);
            if ($rows !== null && $this->fits($rows, $min, $max)) {
                return ['min' => $min, 'max' => $max, 'label' => $label, 'source' => $cycleId === null ? 'default' : 'cycle', 'bands' => $rows];
            }
        }

        return ['min' => $min, 'max' => $max, 'label' => $label, 'source' => 'thirds', 'bands' => self::thirds($min, $max)];
    }

    /** @return array{0: float, 1: float, 2: string} */
    private function potentialRange(PerformanceCycle $cycle): array
    {
        if ($this->potentialSource() === 'talent') {
            $levels = $this->talentPotentialLevels();

            return [min($levels), max($levels), 'Potential (talent assessment)'];
        }
        $scale = $cycle->competencyScale();

        return [$scale->min(), $scale->max(), 'Potential (competency rating, '.$scale->name.')'];
    }

    /** @return array<string, array{from: float, to: float}>|null the three bands of an axis as saved, or null */
    private function bandRows(?int $cycleId, string $axis): ?array
    {
        $rows = PerformanceNineBoxBand::query()->where('axis', $axis)
            ->when($cycleId === null, fn ($q) => $q->whereNull('performance_cycle_id'), fn ($q) => $q->where('performance_cycle_id', $cycleId))
            ->get()->keyBy('band');
        if ($rows->count() !== 3) {
            return null;
        }

        return collect(PerformanceNineBoxBand::BANDS)->mapWithKeys(fn (string $band) => [$band => ['from' => (float) $rows[$band]->from_value, 'to' => (float) $rows[$band]->to_value]])->all();
    }

    /** Saved bands apply only while their thresholds lie inside the axis's scale (another scale falls back to thirds). */
    private function fits(array $bands, float $min, float $max): bool
    {
        return $bands['moderate']['from'] > $min && $bands['high']['from'] > $bands['moderate']['from'] && $bands['high']['from'] <= $max;
    }

    /** @return array<string, array{from: float, to: float}> lower, middle and upper third of the range */
    public static function thirds(float $min, float $max): array
    {
        $step = ($max - $min) / 3;
        $moderate = round($min + $step, 2);
        $high = round($min + 2 * $step, 2);

        return ['low' => ['from' => $min, 'to' => $moderate], 'moderate' => ['from' => $moderate, 'to' => $high], 'high' => ['from' => $high, 'to' => $max]];
    }

    /** The band a value falls in: at or above a band's start and below the next band's start. */
    public static function bandOf(array $axis, ?float $value): ?string
    {
        if ($value === null) {
            return null;
        }

        return match (true) {
            $value >= $axis['bands']['high']['from'] - 0.00001 => 'high',
            $value >= $axis['bands']['moderate']['from'] - 0.00001 => 'moderate',
            default => 'low',
        };
    }

    /**
     * Set an axis's bands for a cycle (or as the default, $cycle null) by where the moderate and the high band start.
     * Needs performance.manage. Bands are an analysis aid: they may change after the cycle is launched or closed.
     */
    public function saveBands(?PerformanceCycle $cycle, string $axis, float $moderateFrom, float $highFrom, User $actor): void
    {
        if (! $actor->hasPermission('performance.manage')) {
            throw new RuntimeException('You cannot change the 9-box bands.');
        }
        if (! in_array($axis, PerformanceNineBoxBand::AXES, true)) {
            throw new RuntimeException("Unknown 9-box axis [{$axis}].");
        }
        [$min, $max] = $this->rangeFor($cycle, $axis);
        $moderateFrom = round($moderateFrom, 2);
        $highFrom = round($highFrom, 2);
        if ($moderateFrom <= $min || $highFrom <= $moderateFrom || $highFrom > $max) {
            throw new RuntimeException('Moderate starts above '.self::format($min).', high starts above moderate and at most at '.self::format($max).'.');
        }
        $values = ['low' => [$min, $moderateFrom], 'moderate' => [$moderateFrom, $highFrom], 'high' => [$highFrom, $max]];

        $this->audit->operation('performance', '9-box bands: '.$axis.' ('.($cycle?->name ?? 'default').')', function () use ($cycle, $axis, $values) {
            // Retried on a deadlock: two first saves of the same axis can both find no row to lock.
            DB::transaction(function () use ($cycle, $axis, $values) {
                $existing = $this->bandQuery($cycle)->where('axis', $axis)->lockForUpdate()->get()->keyBy('band');
                foreach ($values as $band => [$from, $to]) {
                    $row = $existing[$band] ?? new PerformanceNineBoxBand(['performance_cycle_id' => $cycle?->id, 'axis' => $axis, 'band' => $band]);
                    $row->fill(['from_value' => $from, 'to_value' => $to])->save();
                }
            }, 3);

            return 3;
        });
    }

    /** Remove a cycle's own bands (or the default ones): the cycle falls back to the default bands, else thirds. */
    public function resetBands(?PerformanceCycle $cycle, User $actor): int
    {
        if (! $actor->hasPermission('performance.manage')) {
            throw new RuntimeException('You cannot change the 9-box bands.');
        }
        $removed = 0;
        $this->audit->operation('performance', '9-box bands reset ('.($cycle?->name ?? 'default').')', function () use ($cycle, &$removed) {
            $this->bandQuery($cycle)->get()->each(function (PerformanceNineBoxBand $band) use (&$removed) {
                $band->delete();
                $removed++;
            });

            return $removed;
        });

        return $removed;
    }

    private function bandQuery(?PerformanceCycle $cycle): Builder
    {
        return PerformanceNineBoxBand::query()->when($cycle === null, fn ($q) => $q->whereNull('performance_cycle_id'), fn ($q) => $q->where('performance_cycle_id', $cycle->id));
    }

    /** @return array{0: float, 1: float} the scale range of an axis (a cycle's, else the organisation's default scale) */
    public function rangeFor(?PerformanceCycle $cycle, string $axis): array
    {
        if ($cycle !== null) {
            $range = $this->axes($cycle)[$axis];

            return [$range['min'], $range['max']];
        }
        if ($axis === 'potential' && $this->potentialSource() === 'talent') {
            $levels = $this->talentPotentialLevels();

            return [min($levels), max($levels)];
        }
        $scale = RatingScale::default() ?? throw new RuntimeException('There is no rating scale yet.');

        return [$scale->min(), $scale->max()];
    }

    // --- Placement ----------------------------------------------------------------------------

    /**
     * The grid of a cycle for a viewer. Filters: department_id, grade_id (the employee's current position).
     *
     * @param  array{department_id?: int|string|null, grade_id?: int|string|null}  $filters
     * @return array{mode: ?string, axes: array, source: string, cells: array<string, list<array<string, mixed>>>, counts: array<string, ?int>, unplaced: list<array<string, mixed>>, placed: int, suppressed: bool, min_group: int}
     */
    public function grid(PerformanceCycle $cycle, User $viewer, array $filters = []): array
    {
        $mode = self::viewMode($viewer);
        $axes = $this->axes($cycle);
        $empty = ['mode' => $mode, 'axes' => $axes, 'source' => $this->potentialSource(), 'cells' => [], 'counts' => [], 'unplaced' => [], 'placed' => 0, 'suppressed' => false, 'min_group' => self::minGroup()];
        if ($mode === null) {
            return $empty;
        }

        $appraisals = $this->appraisals($cycle, $viewer, $mode, $filters);
        $potentials = $this->potentials($appraisals, $viewer);

        $cells = [];
        $unplaced = [];
        foreach ($appraisals as $appraisal) {
            $performance = $appraisal->effectiveRating();
            $potential = $potentials[$appraisal->id] ?? null;
            $row = [
                'appraisal_id' => $appraisal->id, 'employee_id' => $appraisal->employee_id,
                'name' => $appraisal->employee?->person?->full_name, 'code' => $appraisal->employee?->employee_code,
                'performance' => $performance, 'potential' => $potential['value'] ?? null, 'provisional' => ! $appraisal->isFinal(),
            ];
            if ($performance === null || ($potential['value'] ?? null) === null) {
                $unplaced[] = $row + ['reason' => $performance === null ? 'No rating yet' : ($potential['reason'] ?? 'No potential score')];

                continue;
            }
            $cells[self::bandOf($axes['potential'], $potential['value']).'_'.self::bandOf($axes['performance'], $performance)][] = $row;
        }

        $placed = array_sum(array_map('count', $cells));
        $counts = [];
        foreach (array_keys((array) config('peopleos.performance.nine_box.boxes', [])) as $box) {
            $counts[$box] = count($cells[$box] ?? []);
        }

        if ($mode === 'counts') {
            // Counts only: no names, and no count small enough to point at a person.
            $suppressed = $placed < self::minGroup();
            $counts = array_map(fn (int $n) => $suppressed || ($n > 0 && $n < self::minGroup()) ? null : $n, $counts);

            return ['cells' => [], 'counts' => $counts, 'unplaced' => [], 'placed' => $suppressed ? 0 : $placed, 'suppressed' => $suppressed] + $empty;
        }

        foreach ($cells as $box => $rows) {
            $cells[$box] = collect($rows)->sortBy('name')->values()->all();
        }

        return ['cells' => $cells, 'counts' => $counts, 'unplaced' => $unplaced, 'placed' => $placed] + $empty;
    }

    /** @return Collection<int, Appraisal> the cycle's appraisals the viewer may see on the grid (access scope applies) */
    private function appraisals(PerformanceCycle $cycle, User $viewer, string $mode, array $filters): Collection
    {
        $query = Appraisal::query()->with(['employee.person'])->where('performance_cycle_id', $cycle->id);
        if ($mode === 'team') {
            $query->whereIn('employee_id', $this->relationships->teamOf($viewer)->all());
        }
        $positions = array_filter(['department_id' => $filters['department_id'] ?? null, 'grade_id' => $filters['grade_id'] ?? null], fn ($v) => filled($v));
        if ($positions !== []) {
            $ids = EmployeePosition::query()->effectiveOn()->where(fn ($q) => collect($positions)->each(fn ($value, $column) => $q->where($column, (int) $value)))->pluck('employee_id');
            $query->whereIn('employee_id', $ids->all());
        }

        return $query->orderBy('id')->get();
    }

    /** @return array<int, array{value: ?float, reason?: string}> appraisal id => potential */
    private function potentials(Collection $appraisals, User $viewer): array
    {
        if ($this->potentialSource() !== 'talent') {
            return $appraisals->mapWithKeys(fn (Appraisal $a) => [$a->id => $a->competency_score === null ? ['value' => null, 'reason' => 'No competency rating yet'] : ['value' => (float) $a->competency_score]])->all();
        }

        $versionIds = $this->talentVersionIds();
        $visible = $appraisals->filter(fn (Appraisal $a) => $this->talent->mayViewTalent($viewer, $a->employee_id));
        $latest = $versionIds === [] || $visible->isEmpty() ? collect() : TalentAssessment::query()->where('status', 'final')
            ->whereIn('talent_assessment_model_version_id', $versionIds)->whereIn('employee_id', $visible->pluck('employee_id')->unique()->all())
            ->orderBy('assessed_at')->orderBy('id')->get(['id', 'employee_id', 'ratings', 'assessed_at'])->keyBy('employee_id');

        return $appraisals->mapWithKeys(function (Appraisal $a) use ($visible, $latest) {
            if (! $visible->contains('id', $a->id)) {
                return [$a->id => ['value' => null, 'reason' => 'Potential not shown (talent data)']];
            }
            $value = $latest[$a->employee_id]->ratings['potential'] ?? null;

            return [$a->id => $value === null ? ['value' => null, 'reason' => 'No final talent assessment'] : ['value' => (float) $value]];
        })->all();
    }

    /** @return list<int> versions of the default talent model ("Performance and potential") */
    private function talentVersionIds(): array
    {
        $model = TalentAssessmentModel::query()->where('code', strtoupper((string) config('peopleos.talent.default_model.code')))->first();

        return $model === null ? [] : TalentAssessmentModelVersion::query()->where('talent_assessment_model_id', $model->id)->pluck('id')->map(fn ($id) => (int) $id)->all();
    }

    /** @return list<float> the potential levels of the default talent model (its current version, else the configured model) */
    private function talentPotentialLevels(): array
    {
        $model = TalentAssessmentModel::query()->where('code', strtoupper((string) config('peopleos.talent.default_model.code')))->first();
        $dimensions = $model?->current_version_id ? (TalentAssessmentModelVersion::query()->find($model->current_version_id)?->dimensions ?? []) : (array) config('peopleos.talent.default_model.dimensions', []);
        $levels = collect($dimensions)->firstWhere('key', 'potential')['levels'] ?? [];
        $values = array_map(fn ($l) => (float) $l['value'], $levels);

        return count($values) >= 2 ? $values : [1.0, 3.0];
    }

    public static function format(float $value): string
    {
        return rtrim(rtrim(number_format($value, 2, '.', ''), '0'), '.');
    }
}
