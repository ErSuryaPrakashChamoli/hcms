<?php

namespace App\Domain\Analytics\Datasets;

use App\Domain\Performance\Models\AppraisalRating;
use App\Domain\Performance\Models\Competency;
use App\Domain\Performance\Models\Goal;
use App\Domain\Performance\Models\KeyResult;
use App\Domain\Performance\Models\PerformanceCycle;
use App\Domain\Performance\Services\Appraisals;
use App\Domain\Performance\Support\AppraisalVisibility;
use App\Support\Tenancy\TenantContext;
use Illuminate\Database\Eloquent\Builder;

/**
 * HROne parity AN-27 (HROne reports "Performance review details", "Performance review details by approver"): one row per
 * rating in a review — goal, KPI (key result) or competency — with its weightage, the rating, the achievement % and the
 * level and reviewer who gave it; `is_final_level` marks the ratings of the appraisal's last level (the approver when
 * there is one, else the manager), i.e. the final rating per KPI and competency. For performance HR (performance.view or
 * performance.calibrate) in their access scope; ratings follow AppraisalVisibility::reviewVisible for the reader exactly
 * as appraisal_reviews does; the rating comment needs performance.view; an anonymous reviewer is never named.
 */
class AppraisalRatingsDataset extends Dataset
{
    /** @var array<string, array<int, array{name: ?string, weight: ?float}>> per tenant and subject type: id => item (read once per run) */
    private array $items = [];

    /** @var array<string, array<int, float>> per tenant and cycle: competency id => weightage */
    private array $competencyWeights = [];

    public const ITEM_TYPES = ['goal' => 'Goal', 'key_result' => 'KPI', 'competency' => 'Competency'];

    public function key(): string
    {
        return 'appraisal_ratings';
    }

    public function label(): string
    {
        return 'Appraisal ratings per goal, KPI and competency';
    }

    public function category(): string
    {
        return 'Performance & learning';
    }

    public function permissions(): array
    {
        return ['performance.view', 'performance.calibrate'];
    }

    public function fields(): array
    {
        $review = fn (AppraisalRating $r) => $r->review;
        $visible = fn (AppraisalRating $r) => $r->review !== null && AppraisalReviewsDataset::visible($r->review);

        return [
            'cycle' => ['label' => 'Cycle', 'type' => 'string', 'value' => fn (AppraisalRating $r) => $review($r)?->appraisal?->cycle?->name],
            ...$this->employeeFields(fn (AppraisalRating $r) => $review($r)?->appraisal?->employee),
            'level' => ['label' => 'Review level', 'type' => 'string', 'value' => fn (AppraisalRating $r) => $review($r) ? config("peopleos.performance.review_types.{$review($r)->type}", $review($r)->type) : null],
            'level_code' => ['label' => 'Review level (code)', 'type' => 'string', 'value' => fn (AppraisalRating $r) => $review($r)?->type],
            'reviewer' => ['label' => 'Reviewer', 'type' => 'string', 'value' => fn (AppraisalRating $r) => $review($r) ? AppraisalReviewsDataset::reviewerName($review($r)) : null],
            'is_final_level' => ['label' => 'Final level (1 = yes)', 'type' => 'number', 'value' => fn (AppraisalRating $r) => self::isFinalLevel($r) ? 1 : 0],
            'review_status' => ['label' => 'Review status', 'type' => 'string', 'value' => fn (AppraisalRating $r) => $review($r)?->status],
            'item_type' => ['label' => 'Item type', 'type' => 'string', 'value' => fn (AppraisalRating $r) => self::ITEM_TYPES[$r->subject_type] ?? $r->subject_type],
            'item' => ['label' => 'Goal / KPI / competency', 'type' => 'string', 'value' => fn (AppraisalRating $r) => $this->item($r)['name']],
            'weight' => ['label' => 'Weightage (%)', 'type' => 'number', 'value' => fn (AppraisalRating $r) => $this->item($r)['weight']],
            'rating' => ['label' => 'Rating', 'type' => 'number', 'value' => fn (AppraisalRating $r) => $visible($r) && $r->rating !== null ? (float) $r->rating : null],
            'achievement_pct' => ['label' => 'Achievement (%)', 'type' => 'number', 'value' => fn (AppraisalRating $r) => $visible($r) && $r->achievement_pct !== null ? (float) $r->achievement_pct : null],
            'comment' => ['label' => 'Comment', 'type' => 'string', 'permission' => 'performance.view', 'value' => fn (AppraisalRating $r) => $visible($r) ? $r->comment : null],
            'count' => ['label' => 'Ratings (1 per row)', 'type' => 'number', 'value' => fn (AppraisalRating $r) => 1],
        ];
    }

    public function query(): Builder
    {
        $this->items = $this->competencyWeights = []; // read afresh for each run

        // Ratings are not access-scoped themselves; requiring a review of a visible appraisal applies the reader's scope.
        return AppraisalRating::query()->whereHas('review', fn (Builder $q) => $q->whereHas('appraisal'))
            ->with(['review.appraisal.cycle.templateVersion', 'review.appraisal.reviews', ...self::employeeWith('review.appraisal.employee'), 'review.reviewer.person'])
            ->orderByDesc('appraisal_review_id')->orderBy('subject_type')->orderBy('subject_id');
    }

    /** The last level of the appraisal's review chain: the approver when it has one, else the manager. */
    private static function isFinalLevel(AppraisalRating $rating): bool
    {
        $appraisal = $rating->review?->appraisal;

        return $appraisal !== null && $rating->review->type === AppraisalVisibility::lastLevel($appraisal);
    }

    /** @return array{name: ?string, weight: ?float} the rated goal, KPI or competency (names and weights read once per run) */
    private function item(AppraisalRating $rating): array
    {
        $tenant = (int) app(TenantContext::class)->id();
        $type = (string) $rating->subject_type;
        $key = $tenant.':'.$type;
        if (! isset($this->items[$key])) {
            // Only the items that were rated; goals are the appraisal employee's own and read in the reader's scope.
            $rated = AppraisalRating::query()->select('subject_id')->where('subject_type', $type);
            $this->items[$key] = match ($type) {
                'goal' => Goal::query()->whereIn('id', $rated)->get(['id', 'title', 'weight'])->mapWithKeys(fn (Goal $g) => [$g->id => ['name' => $g->title, 'weight' => $g->weight === null ? null : (float) $g->weight]])->all(),
                'key_result' => KeyResult::query()->whereIn('id', $rated)->get(['id', 'title', 'weight'])->mapWithKeys(fn (KeyResult $k) => [$k->id => ['name' => $k->title, 'weight' => $k->weight === null ? null : (float) $k->weight]])->all(),
                'competency' => Competency::query()->whereIn('id', $rated)->get(['id', 'name'])->mapWithKeys(fn (Competency $c) => [$c->id => ['name' => $c->name, 'weight' => null]])->all(),
                default => [],
            };
        }
        $item = $this->items[$key][(int) $rating->subject_id] ?? ['name' => null, 'weight' => null];
        if ($type === 'competency' && ($cycle = $rating->review?->appraisal?->cycle) instanceof PerformanceCycle) {
            // The competency's weightage as scoring uses it: the cycle's pinned template version, else the competency's own.
            $weights = $this->competencyWeights[$tenant.':'.$cycle->id] ??= app(Appraisals::class)->competencyWeightsFor($cycle, array_keys($this->items[$key]));
            $item['weight'] = $weights[(int) $rating->subject_id] ?? null;
        }

        return $item;
    }
}
