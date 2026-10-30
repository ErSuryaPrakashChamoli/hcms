<?php

use App\Domain\Performance\Models\Appraisal;
use App\Domain\Performance\Models\Goal;
use App\Domain\Performance\Models\KpiRatingFormula;
use App\Domain\Performance\Models\PerformanceTemplate;
use App\Domain\Performance\Models\PerformanceTemplateVersion;
use App\Domain\Performance\Models\PerformanceUnit;
use App\Domain\Performance\Models\RatingScale;
use App\Domain\Performance\Services\Appraisals;
use App\Domain\Performance\Services\Goals;
use App\Domain\Performance\Services\KpiRatings;
use App\Domain\Performance\Services\PerformanceTemplates;
use App\Domain\Platform\Services\SettingsRepository;
use App\Filament\Resources\Appraisals\Pages\ViewAppraisal;
use App\Filament\Resources\KpiRatingFormulas\Pages\ManageKpiRatingFormulas;
use App\Filament\Resources\RatingScales\Pages\ManageRatingScales;
use App\Filament\Support\PerformanceActions;
use Livewire\Livewire;

require_once __DIR__.'/../Workflow/WorkflowTestHelpers.php';
require_once __DIR__.'/../Performance/PerformanceTestHelpers.php';

/* HROne setup parity HP-8c — Review rating, Competency rating and KPI rating formula: level ranges (from / to), a
 * separate competency scale per cycle pinned at launch, KPI rating formulas (achievement bands → rating) with reverse
 * KPIs, and how the formula rating shows on reviews (suggest / prefill / lock). Defaults change nothing. */

beforeEach(function () {
    $this->travelTo('2026-09-21 09:00:00');
    $this->tenant = provisionTenant();
    actAsTenant($this->tenant);
    $this->hr = tenantUser($this->tenant, ['*']);
    $this->actingAs($this->hr);
    $this->manager = activeEmployee(null, ['performance.team', 'performance.review', 'performance.goals']);
    $this->employee = activeEmployee($this->manager);
    $this->appraisals = app(Appraisals::class);
    $this->settings = app(SettingsRepository::class);
    $this->threePoint = RatingScale::query()->create(['name' => 'Three point', 'code' => 'three', 'levels' => [
        ['value' => 1, 'label' => 'Developing'], ['value' => 2, 'label' => 'Proficient'], ['value' => 3, 'label' => 'Expert'],
    ]]);
});

/** A formula: below 80 % → 2, 80–99.99 % → 3, 100–119.99 % → 4, 120 % and above → 5. */
function kpiFormula(array $overrides = []): KpiRatingFormula
{
    return KpiRatingFormula::query()->create(array_merge(['code' => 'std', 'name' => 'Standard', 'based_on' => 'achievement_over_target', 'bands' => [
        ['from_pct' => 0, 'to_pct' => 79.99, 'rating' => 2], ['from_pct' => 80, 'to_pct' => 99.99, 'rating' => 3],
        ['from_pct' => 100, 'to_pct' => 119.99, 'rating' => 4], ['from_pct' => 120, 'to_pct' => null, 'rating' => 5],
    ]], $overrides));
}

it('labels a rating by the range that contains it when the scale has ranges, else by the nearest level as before', function () {
    $scale = RatingScale::query()->create(['name' => 'Banded', 'code' => 'banded', 'levels' => [
        ['value' => 1, 'label' => 'Low', 'from' => 0, 'to' => 2.49], ['value' => 3, 'label' => 'Mid', 'from' => 2.5, 'to' => 3.99], ['value' => 5, 'label' => 'High', 'from' => 4, 'to' => 5],
    ]]);
    expect($scale->hasRanges())->toBeTrue()
        ->and($scale->labelFor(2.4))->toBe('Low')      // nearest would be "Mid" (value 3)
        ->and($scale->labelFor(3.99))->toBe('Mid')
        ->and($scale->labelFor(4.0))->toBe('High')
        ->and($scale->levels[0])->toEqual(['value' => 1, 'label' => 'Low', 'description' => null, 'from' => 0, 'to' => 2.49])
        ->and(RatingScale::default()->hasRanges())->toBeFalse()
        ->and(RatingScale::default()->labelFor(2.4))->toBe('Developing');

    expect(fn () => RatingScale::query()->create(['name' => 'Overlap', 'code' => 'ov', 'levels' => [['value' => 1, 'label' => 'A', 'from' => 0, 'to' => 2], ['value' => 2, 'label' => 'B', 'from' => 2, 'to' => 3]]]))->toThrow(RuntimeException::class, 'overlap')
        ->and(fn () => RatingScale::query()->create(['name' => 'Half', 'code' => 'half', 'levels' => [['value' => 1, 'label' => 'A', 'from' => 0], ['value' => 2, 'label' => 'B']]]))->toThrow(RuntimeException::class, 'both ends')
        ->and(fn () => RatingScale::query()->create(['name' => 'Back', 'code' => 'back', 'levels' => [['value' => 1, 'label' => 'A', 'from' => 3, 'to' => 1], ['value' => 2, 'label' => 'B']]]))->toThrow(RuntimeException::class, 'starts after');

    Livewire::test(ManageRatingScales::class)->callAction('create', ['name' => 'Ranged', 'code' => 'RANGED', 'status' => 'active', 'levels' => [
        ['value' => 1, 'label' => 'Below', 'from' => 0, 'to' => 1.99], ['value' => 2, 'label' => 'Above', 'from' => 2, 'to' => 3],
    ]])->assertHasNoActionErrors();
    expect(RatingScale::query()->where('code', 'RANGED')->firstOrFail()->labelFor(1.9))->toBe('Below');
});

it('rates competencies on the cycle\'s own competency scale, pinned at launch', function () {
    $cycle = draftCycle(['competency_rating_scale_id' => $this->threePoint->id]);
    $plain = $this->appraisals->launch(draftCycle(['code' => 'PLAIN']));
    $version = $plain->templateVersion;
    expect($version->competency_scale_snapshot)->toBeNull()->and($version->competencyScale())->toBeNull()
        ->and($plain->competencyScale()->max())->toBe(5.0)
        // A version without a competency scale keeps the earlier checksum formula.
        ->and($version->checksum)->toBe(hash('sha256', (string) json_encode([$version->sections, $version->rating_scale_snapshot, $version->competency_snapshot, $version->workflow, $version->weights, $version->goal_rules])));

    $cycle = $this->appraisals->launch($cycle);
    $pinned = $cycle->templateVersion;
    expect($pinned->competency_rating_scale_id)->toBe($this->threePoint->id)
        ->and($pinned->competency_scale_snapshot['levels'])->toHaveCount(3)
        ->and($cycle->competencyScale()->max())->toBe(3.0)->and($cycle->ratingScale()->max())->toBe(5.0)
        ->and(fn () => $cycle->update(['competency_rating_scale_id' => null]))->toThrow(RuntimeException::class, 'launched cycle')
        ->and(fn () => $this->threePoint->update(['levels' => [['value' => 1, 'label' => 'x'], ['value' => 9, 'label' => 'y']]]))->toThrow(RuntimeException::class, 'used by a launched cycle');
    $cycle->refresh();

    $cycle = $this->appraisals->advance($cycle); // self review
    $cycle = $this->appraisals->advance($cycle); // manager review
    $appraisal = Appraisal::query()->where('performance_cycle_id', $cycle->id)->where('employee_id', $this->employee->id)->firstOrFail();
    $competencies = $cycle->competency_ids;
    expect(fn () => $this->appraisals->submitReview($appraisal->review('manager'), [], array_fill_keys($competencies, 4), 4))->toThrow(RuntimeException::class, 'between 1 and 3');
    $this->appraisals->submitReview($appraisal->review('manager'), [], array_fill_keys($competencies, 2), 4);
    // 2 on a 1–3 scale is 50 %.
    expect((float) $appraisal->refresh()->competency_score)->toBe(50.0);

    // The review form offers the competency scale's levels for competencies.
    expect(array_values($appraisal->load('cycle')->cycle->competencyScale()->options()))->toBe(['1 · Developing', '2 · Proficient', '3 · Expert']);

    // A template can carry the competency scale too; applying it to a draft cycle copies it.
    $template = PerformanceTemplate::query()->create(['code' => 'comp', 'name' => 'Competency template']);
    $v = app(PerformanceTemplates::class)->publish($template, ['rating_scale_id' => RatingScale::default()->id, 'competency_rating_scale_id' => $this->threePoint->id, 'workflow' => [['key' => 'final']]]);
    $applied = app(PerformanceTemplates::class)->applyTo(draftCycle(['code' => 'APPLY']), $v);
    expect($applied->competency_rating_scale_id)->toBe($this->threePoint->id)
        ->and(PerformanceTemplateVersion::query()->whereKey($v->id)->value('checksum'))->not->toBe($version->checksum);
});

it('validates KPI rating formulas and turns achievement into a rating, reverse KPIs by target ÷ achievement', function () {
    expect(fn () => kpiFormula(['bands' => [['from_pct' => 0, 'to_pct' => 50, 'rating' => 1], ['from_pct' => 50, 'to_pct' => 100, 'rating' => 2]]]))->toThrow(RuntimeException::class, 'overlap')
        ->and(fn () => kpiFormula(['bands' => [['from_pct' => 80, 'to_pct' => 10, 'rating' => 1]]]))->toThrow(RuntimeException::class, 'before it starts')
        ->and(fn () => kpiFormula(['bands' => [['from_pct' => 0, 'to_pct' => 10]]]))->toThrow(RuntimeException::class, 'a rating')
        ->and(fn () => kpiFormula(['bands' => []]))->toThrow(RuntimeException::class, 'at least one band')
        ->and(fn () => kpiFormula(['based_on' => 'magic']))->toThrow(RuntimeException::class, 'compared');

    $formula = kpiFormula();
    expect($formula->code)->toBe('STD')->and($formula->ratingFor(112.5))->toBe(4.0)->and($formula->ratingFor(250))->toBe(5.0)->and($formula->ratingFor(-5))->toBeNull();

    $goals = app(Goals::class);
    $kpi = app(KpiRatings::class);
    $scale = RatingScale::default();
    $sales = $goals->create(['employee_id' => $this->employee->id, 'title' => 'Sales', 'measure_type' => 'numeric', 'target_value' => 100, 'weight' => 30, 'kpi_rating_formula_id' => $formula->id]);
    $goals->checkIn($sales, 112);
    // Achievement is not capped at 100 % for the formula (progress is).
    expect((float) $sales->refresh()->progress)->toBe(100.0)
        ->and($kpi->suggest($sales->refresh(), $scale))->toBe(['pct' => 112.0, 'rating' => 4.0, 'formula' => 'STD', 'reverse' => false]);

    // Reverse KPI: fewer defects is better — 5 against a target of 4 is 80 % (target ÷ achievement).
    $defects = $goals->create(['employee_id' => $this->employee->id, 'title' => 'Defects', 'measure_type' => 'numeric', 'start_value' => 10, 'target_value' => 4, 'weight' => 20, 'is_reverse' => true, 'kpi_rating_formula_id' => $formula->id]);
    $goals->checkIn($defects, 5);
    expect($kpi->suggest($defects->refresh(), $scale)['pct'])->toBe(80.0)->and($kpi->suggest($defects, $scale)['rating'])->toBe(3.0)->and($kpi->suggest($defects, $scale)['reverse'])->toBeTrue();
    $goals->checkIn($defects, 0);
    expect($kpi->suggest($defects->refresh(), $scale)['rating'])->toBe(5.0);

    // The unit's formula is the default; key results weigh in; an inactive formula or none suggests nothing.
    $unit = PerformanceUnit::query()->where('code', 'UNITS')->firstOrFail();
    $unit->update(['kpi_rating_formula_id' => $formula->id]);
    $okr = $goals->create(['employee_id' => $this->employee->id, 'title' => 'Launch', 'type' => 'okr', 'unit_id' => $unit->id, 'weight' => 10], [
        ['title' => 'Stores', 'measure_type' => 'numeric', 'target_value' => 10, 'current_value' => 15, 'weight' => 50],
        ['title' => 'Returns', 'measure_type' => 'numeric', 'target_value' => 10, 'current_value' => 20, 'weight' => 50, 'is_reverse' => true],
    ]);
    expect($kpi->suggest($okr->refresh(), $scale)['pct'])->toBe(100.0) // (150 % + 50 %) / 2
        ->and($kpi->suggest($goals->create(['employee_id' => $this->employee->id, 'title' => 'No formula', 'weight' => 5]), $scale))->toBeNull();
    $formula->update(['status' => 'inactive']);
    expect($kpi->suggest($sales->refresh(), $scale))->toBeNull();

    // A band rating outside the scale is no suggestion; inside, it snaps to the nearest level.
    $wide = kpiFormula(['code' => 'wide', 'bands' => [['from_pct' => 0, 'to_pct' => null, 'rating' => 9]]]);
    $odd = kpiFormula(['code' => 'odd', 'bands' => [['from_pct' => 0, 'to_pct' => null, 'rating' => 3.4]]]);
    expect($kpi->suggest(tap($sales)->update(['kpi_rating_formula_id' => $wide->id])->refresh(), $scale))->toBeNull()
        ->and($kpi->suggest(tap($sales)->update(['kpi_rating_formula_id' => $odd->id])->refresh(), $scale)['rating'])->toBe(3.0);
});

it('shows the formula rating on reviews only when turned on, and prefills or fixes it as chosen', function () {
    $formula = kpiFormula();
    $cycle = draftCycle(['stages' => [['key' => 'manager_review', 'name' => 'Manager review', 'starts_on' => '2026-09-01', 'ends_on' => '2026-12-31']]]);
    $goal = app(Goals::class)->create(['employee_id' => $this->employee->id, 'performance_cycle_id' => $cycle->id, 'title' => 'Sales', 'measure_type' => 'numeric', 'target_value' => 100, 'weight' => 100, 'kpi_rating_formula_id' => $formula->id, 'is_reverse' => false]);
    app(Goals::class)->checkIn($goal, 125);
    $cycle = $this->appraisals->launch($cycle);
    $appraisal = Appraisal::query()->where('performance_cycle_id', $cycle->id)->where('employee_id', $this->employee->id)->firstOrFail();
    $scale = $cycle->ratingScale();
    // The manager review form as HR opens it: the goal's rating as filled in.
    $formRating = fn () => Livewire::test(ViewAppraisal::class, ['record' => $appraisal->id])->mountAction('managerReview')->get("mountedActions.0.data.goals.{$goal->id}");

    expect($this->settings->get('performance.review.show_formula_rating'))->toBeFalse()
        ->and(app(KpiRatings::class)->enabled())->toBeFalse()
        ->and($formRating())->toBeNull();

    $this->settings->set('performance.review.show_formula_rating', true, 'Show formula');
    expect(PerformanceActions::formulaHint(app(KpiRatings::class)->suggest($goal->refresh(), $scale)))->toBe('KPI formula rating: 5 (achievement 125%)')
        ->and($formRating())->toBeNull();

    // Prefill fills it in (the reviewer may change it); lock also refuses another rating.
    $this->settings->set('performance.review.formula_rating_mode', 'prefill', 'Prefill');
    expect((string) $formRating())->toBe('5');
    $this->settings->set('performance.review.formula_rating_mode', 'lock', 'Lock');
    expect((string) $formRating())->toBe('5')
        ->and(fn () => $this->appraisals->submitReview($appraisal->review('manager'), [$goal->id => 3], array_fill_keys($cycle->competency_ids, 3), 3))->toThrow(RuntimeException::class, 'set by its KPI rating formula (5)');
    $this->appraisals->submitReview($appraisal->review('manager'), [$goal->id => 5], array_fill_keys($cycle->competency_ids, 3), 4);
    expect($appraisal->refresh()->review('manager')->status)->toBe('submitted');

    // With the formula rating hidden, lock has no effect.
    $this->settings->set('performance.review.show_formula_rating', false, 'Hide');
    expect(app(KpiRatings::class)->locks())->toBeFalse();
});

it('offers the KPI rating formula screen and marks reverse KPIs only when reverse rating is shown', function () {
    Livewire::test(ManageKpiRatingFormulas::class)->callAction('create', ['name' => 'Sales bands', 'code' => 'SALES', 'based_on' => 'achievement_over_target', 'status' => 'active', 'bands' => [
        ['from_pct' => 0, 'to_pct' => 99.99, 'rating' => 2], ['from_pct' => 100, 'to_pct' => null, 'rating' => 4],
    ]])->assertHasNoActionErrors();
    expect(KpiRatingFormula::query()->where('code', 'SALES')->firstOrFail()->bands)->toHaveCount(2);

    $goal = app(Goals::class)->create(['employee_id' => $this->employee->id, 'title' => 'Defects', 'is_reverse' => true, 'weight' => 10]);
    $cycle = $this->appraisals->launch(draftCycle(['stages' => [['key' => 'manager_review', 'name' => 'Manager review']]]));
    $goal->update(['performance_cycle_id' => $cycle->id]);
    $scale = $cycle->ratingScale();
    $label = fn () => PerformanceActions::goalRatingField($goal->refresh(), $scale->options(), $scale)->getLabel();
    expect($label())->not->toContain('lower is better');
    $this->settings->set('performance.review.show_reverse_rating', true, 'Reverse');
    expect($label())->toContain('lower is better')
        ->and(Goal::query()->where('is_reverse', true)->count())->toBe(1);
});
