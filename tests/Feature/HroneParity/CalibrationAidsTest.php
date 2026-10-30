<?php

use App\Domain\Audit\Models\AuditEvent;
use App\Domain\Employment\Models\EmployeePosition;
use App\Domain\Organisation\Models\Department;
use App\Domain\Performance\Models\Appraisal;
use App\Domain\Performance\Models\Initiative;
use App\Domain\Performance\Models\PerformanceCycle;
use App\Domain\Performance\Models\PerformanceDistributionTarget;
use App\Domain\Performance\Models\PerformanceLookup;
use App\Domain\Performance\Models\PerformanceNineBoxBand;
use App\Domain\Performance\Services\Appraisals;
use App\Domain\Performance\Services\NineBox;
use App\Domain\Performance\Services\PerformanceDistribution;
use App\Domain\Platform\Services\SettingsRepository;
use App\Domain\Talent\Services\TalentAssessments;
use App\Filament\Pages\CalibrationBoard;
use App\Filament\Pages\NineBoxGrid;
use Livewire\Livewire;

require_once __DIR__.'/../Workflow/WorkflowTestHelpers.php';
require_once __DIR__.'/../Performance/PerformanceTestHelpers.php';

/* HROne setup parity HP-8g — Calibration aids: bell curve targets per rating level (per cycle or default) against the
 * actual distribution on the calibration board, with an optional check when a cycle closes (off by default; warn; block
 * outside the tolerance), and the 9-box (performance × potential, bands per axis, default thirds) for HR, managers (their
 * team) and analytics viewers (counts only). */

beforeEach(function () {
    $this->travelTo('2026-09-21 09:00:00');
    $this->tenant = provisionTenant();
    actAsTenant($this->tenant);
    $this->hr = tenantUser($this->tenant, ['*']);
    $this->actingAs($this->hr);
    $this->manager = activeEmployee(null, ['performance.team', 'performance.review', 'performance.goals', 'performance.initiatives']);
    $this->a = activeEmployee($this->manager);
    $this->b = activeEmployee($this->manager);
    $this->c = activeEmployee($this->manager);
    $this->d = activeEmployee();
    $this->appraisals = app(Appraisals::class);
    $this->distribution = app(PerformanceDistribution::class);
    $this->settings = app(SettingsRepository::class);
});

function hp8gCycle(string $code = 'FINAL'): PerformanceCycle
{
    return app(Appraisals::class)->launch(draftCycle(['code' => $code, 'name' => "Cycle {$code}", 'stages' => [['key' => 'final', 'name' => 'Final rating', 'starts_on' => '2026-09-01', 'ends_on' => '2026-12-31']]]));
}

/** Finalize every appraisal of the cycle: rating by employee id, else 3 (manager 3, a 3, b 4, c 2, d 3 → 20 / 60 / 20 %). */
function hp8gFinalize(PerformanceCycle $cycle, array $ratings, $actor): void
{
    foreach (Appraisal::query()->where('performance_cycle_id', $cycle->id)->get() as $appraisal) {
        app(Appraisals::class)->finalize($appraisal, $actor, (float) ($ratings[$appraisal->employee_id] ?? 3));
    }
}

function hp8gTargets(): array
{
    return ['1' => 10, '2' => 20, '3' => 40, '4' => 20, '5' => 10];
}

/** Set an appraisal's scores directly (placement tests). */
function hp8gScores(PerformanceCycle $cycle, int $employeeId, ?float $rating, ?float $competency): void
{
    Appraisal::query()->where('performance_cycle_id', $cycle->id)->where('employee_id', $employeeId)->firstOrFail()
        ->update(['computed_rating' => $rating, 'competency_score' => $competency]);
}

it('keeps the earlier behaviour by default: no check, the cycle closes with targets far off, nothing is recorded', function () {
    expect($this->settings->get('performance.distribution.check'))->toBe('off')
        ->and((int) $this->settings->get('performance.distribution.tolerance_pct'))->toBe(5)
        ->and($this->settings->get('performance.nine_box.potential_source'))->toBe('competencies')
        ->and($this->distribution->mode())->toBe('off');

    $cycle = hp8gCycle();
    hp8gFinalize($cycle, [$this->b->id => 4, $this->c->id => 2], $this->hr);
    $this->distribution->saveTargets($cycle, hp8gTargets(), $this->hr);

    expect($this->distribution->warning($cycle))->toBeNull();
    $closed = $this->appraisals->close($cycle, $this->hr);
    $audit = AuditEvent::query()->where('entity_type', PerformanceCycle::class)->where('entity_id', (string) $cycle->id)->latest('id')->first();
    expect($closed->status)->toBe('closed')->and($audit->metadata)->toHaveKey('appraisals_locked')->not->toHaveKey('distribution_warning');
});

it('saves bell curve targets per cycle or as the default: every level, adding up to 100, by performance administrators only', function () {
    $cycle = hp8gCycle();
    $employee = tenantUser($this->tenant, ['performance.calibrate']);

    expect(fn () => $this->distribution->saveTargets($cycle, ['1' => 50, '2' => 20], $this->hr))->toThrow(RuntimeException::class, 'add up to 70%')
        ->and(fn () => $this->distribution->saveTargets($cycle, ['7' => 100], $this->hr))->toThrow(RuntimeException::class, 'not a level')
        ->and(fn () => $this->distribution->saveTargets($cycle, ['1' => 120], $this->hr))->toThrow(RuntimeException::class, 'between 0 and 100')
        ->and(fn () => $this->distribution->saveTargets($cycle, hp8gTargets(), $employee))->toThrow(RuntimeException::class, 'cannot set distribution targets');

    // The default applies to every cycle whose scale has those levels, until the cycle has its own.
    expect($this->distribution->saveTargets(null, hp8gTargets(), $this->hr))->toBe(5)
        ->and($this->distribution->source($cycle))->toBe('default')
        ->and($this->distribution->targets($cycle))->toBe(['1' => 10.0, '2' => 20.0, '3' => 40.0, '4' => 20.0, '5' => 10.0]);
    $this->distribution->saveTargets($cycle, ['1' => 5, '2' => 25, '3' => 40, '4' => 25, '5' => 5], $this->hr);
    expect($this->distribution->source($cycle))->toBe('cycle')->and($this->distribution->targets($cycle)['2'])->toBe(25.0)
        ->and(PerformanceDistributionTarget::query()->count())->toBe(10);

    // Saving again replaces the set; all blank removes it (the cycle falls back to the default).
    $this->distribution->saveTargets($cycle, ['1' => 10, '2' => 20, '3' => 40, '4' => 20, '5' => 10], $this->hr);
    expect(PerformanceDistributionTarget::query()->where('performance_cycle_id', $cycle->id)->count())->toBe(5);
    expect($this->distribution->saveTargets($cycle, ['1' => null, '2' => '', '3' => null, '4' => null, '5' => null], $this->hr))->toBe(0)
        ->and($this->distribution->source($cycle))->toBe('default');
});

it('compares the actual distribution with the targets per level and flags levels outside the tolerance', function () {
    $cycle = hp8gCycle();
    hp8gFinalize($cycle, [$this->b->id => 4, $this->c->id => 2], $this->hr);
    $this->distribution->saveTargets($cycle, hp8gTargets(), $this->hr);

    $comparison = $this->distribution->compare($cycle);
    $rows = collect($comparison['rows'])->keyBy('value');
    expect($comparison['rated'])->toBe(5)->and($comparison['has_targets'])->toBeTrue()->and($comparison['outside'])->toBeTrue()
        ->and($rows[3.0])->toMatchArray(['label' => 'Meets Expectations', 'count' => 3, 'actual' => 60.0, 'target' => 40.0, 'gap' => 20.0, 'outside' => true])
        ->and($rows[2.0])->toMatchArray(['count' => 1, 'actual' => 20.0, 'gap' => 0.0, 'outside' => false])
        ->and($rows[5.0])->toMatchArray(['count' => 0, 'outside' => true]);

    $this->settings->set('performance.distribution.tolerance_pct', 25, 'Wide band');
    expect($this->distribution->compare($cycle)['outside'])->toBeFalse();
});

it('warns about the gap when the check is "warn" and still closes the cycle, recording the warning', function () {
    $this->settings->set('performance.distribution.check', 'warn', 'Show the gap');
    $cycle = hp8gCycle();
    hp8gFinalize($cycle, [$this->b->id => 4, $this->c->id => 2], $this->hr);
    $this->distribution->saveTargets($cycle, hp8gTargets(), $this->hr);

    expect($this->distribution->warning($cycle))->toContain('Meets Expectations 60% (target 40%)')->toContain('more than 5 points');
    $closed = $this->appraisals->close($cycle, $this->hr);
    $audit = AuditEvent::query()->where('entity_type', PerformanceCycle::class)->where('entity_id', (string) $cycle->id)->latest('id')->first();
    expect($closed->status)->toBe('closed')->and($audit->metadata['distribution_warning'])->toContain('Meets Expectations');
});

it('blocks closing a cycle outside the tolerance when the check is "block", and closes once it is inside', function () {
    $this->settings->set('performance.distribution.check', 'block', 'Hold the curve');
    $cycle = hp8gCycle();
    hp8gFinalize($cycle, [$this->b->id => 4, $this->c->id => 2], $this->hr);

    // Without targets there is nothing to check.
    expect($this->distribution->warning($cycle))->toBeNull();
    $this->distribution->saveTargets($cycle, hp8gTargets(), $this->hr);
    expect(fn () => $this->appraisals->close($cycle, $this->hr))->toThrow(RuntimeException::class, 'before closing the cycle')
        ->and($cycle->refresh()->status)->toBe('active');

    $this->distribution->saveTargets($cycle, ['1' => 0, '2' => 20, '3' => 60, '4' => 20, '5' => 0], $this->hr);
    expect($this->appraisals->close($cycle, $this->hr)->status)->toBe('closed');
});

it('shows target against actual on the calibration board, narrows it by department and warns after finalizing', function () {
    $this->settings->set('performance.distribution.check', 'warn', 'Show the gap');
    $cycle = hp8gCycle();
    $this->distribution->saveTargets($cycle, hp8gTargets(), $this->hr);
    $sales = Department::factory()->create(['name' => 'Sales']);
    EmployeePosition::query()->where('employee_id', $this->b->id)->update(['department_id' => $sales->id]);

    $board = Livewire::test(CalibrationBoard::class, ['cycle' => $cycle->id]);
    expect($board->instance()->getComparison()['total'])->toBe(5);
    $board->callAction('filter', data: ['department' => $sales->id]);
    expect($board->instance()->getComparison()['total'])->toBe(1);

    $appraisal = Appraisal::query()->where('performance_cycle_id', $cycle->id)->where('employee_id', $this->b->id)->firstOrFail();
    $board->callTableAction('finalize', $appraisal, ['rating' => '5'])
        ->assertNotified('Rating distribution outside the targets');
    expect($appraisal->refresh()->isFinal())->toBeTrue();

    Livewire::test(CalibrationBoard::class, ['cycle' => $cycle->id])
        ->callAction('targets', data: ['scope' => 'default', 'default_levels' => ['level_0' => 20, 'level_1' => 20, 'level_2' => 20, 'level_3' => 20, 'level_4' => 20]])
        ->assertNotified('Targets saved');
    expect($this->distribution->defaultTargets())->toBe(['1' => 20.0, '2' => 20.0, '3' => 20.0, '4' => 20.0, '5' => 20.0]);

    actAsTenant(null);
    $this->get(CalibrationBoard::getUrl(['cycle' => $cycle->id]))->assertOk()->assertSee('Target 40%')->assertSee('Target %');
});

it('places a cycle on the 9-box by the rating that stands and the competency rating, in thirds by default', function () {
    $cycle = hp8gCycle();
    hp8gScores($cycle, $this->a->id, 5, 5);   // high performance, high potential
    hp8gScores($cycle, $this->b->id, 1, 4);   // low performance, high potential
    hp8gScores($cycle, $this->c->id, 3, 3);   // moderate, moderate
    hp8gScores($cycle, $this->d->id, 4, null); // no competency rating: not placed

    $axes = app(NineBox::class)->axes($cycle);
    expect($axes['performance']['source'])->toBe('thirds')
        ->and($axes['performance']['bands']['moderate']['from'])->toBe(2.33)->and($axes['performance']['bands']['high']['from'])->toBe(3.67);

    $grid = app(NineBox::class)->grid($cycle, $this->hr);
    expect($grid['mode'])->toBe('all')
        ->and(collect($grid['cells']['high_high'])->pluck('employee_id')->all())->toBe([$this->a->id])
        ->and(collect($grid['cells']['high_low'])->pluck('employee_id')->all())->toBe([$this->b->id])
        ->and($grid['counts']['moderate_moderate'])->toBe(1)
        ->and(collect($grid['unplaced'])->firstWhere('employee_id', $this->d->id)['reason'])->toBe('No competency rating yet')
        ->and(collect($grid['unplaced'])->firstWhere('employee_id', $this->manager->id)['reason'])->toBe('No rating yet')
        ->and($grid['cells']['high_high'][0]['provisional'])->toBeTrue();

    // Bands per cycle (and validated), else the default ones.
    expect(fn () => app(NineBox::class)->saveBands($cycle, 'performance', 4, 3, $this->hr))->toThrow(RuntimeException::class, 'high starts above moderate')
        ->and(fn () => app(NineBox::class)->saveBands($cycle, 'performance', 2, 3, tenantUser($this->tenant, ['performance.calibrate'])))->toThrow(RuntimeException::class, 'cannot change the 9-box bands');
    app(NineBox::class)->saveBands(null, 'performance', 2, 5, $this->hr);
    expect(app(NineBox::class)->axes($cycle)['performance']['source'])->toBe('default');
    app(NineBox::class)->saveBands($cycle, 'performance', 1.5, 2, $this->hr);
    $grid = app(NineBox::class)->grid($cycle, $this->hr);
    expect(collect($grid['cells']['moderate_high'])->pluck('employee_id')->all())->toBe([$this->c->id])
        ->and(PerformanceNineBoxBand::query()->where('performance_cycle_id', $cycle->id)->count())->toBe(3);
    expect(app(NineBox::class)->resetBands($cycle, $this->hr))->toBe(3)
        ->and(app(NineBox::class)->axes($cycle)['performance']['source'])->toBe('default');
});

it('shows managers only their team, analytics viewers only counts (suppressed below the minimum group), and nobody else', function () {
    $cycle = hp8gCycle();
    foreach ([$this->manager, $this->a, $this->b, $this->c, $this->d] as $e) {
        hp8gScores($cycle, $e->id, 3, 3);
    }

    $team = app(NineBox::class)->grid($cycle, $this->manager->user);
    expect($team['mode'])->toBe('team')
        ->and(collect($team['cells']['moderate_moderate'])->pluck('employee_id')->sort()->values()->all())->toBe(collect([$this->a->id, $this->b->id, $this->c->id])->sort()->values()->all());

    $analyst = tenantUser($this->tenant, ['performance.analytics']);
    $counts = app(NineBox::class)->grid($cycle, $analyst);
    expect($counts['mode'])->toBe('counts')->and($counts['cells'])->toBe([])->and($counts['counts']['moderate_moderate'])->toBe(5);
    hp8gScores($cycle, $this->d->id, 5, 5);
    $counts = app(NineBox::class)->grid($cycle, $analyst);
    expect($counts['counts']['moderate_moderate'])->toBeNull()->and($counts['counts']['high_high'])->toBeNull()->and($counts['counts']['low_low'])->toBe(0);
    config(['peopleos.performance.analytics_min_group' => 10]);
    expect(app(NineBox::class)->grid($cycle, $analyst)['suppressed'])->toBeTrue();

    $employee = $this->a->user;
    expect(NineBox::viewMode($employee))->toBeNull()->and(app(NineBox::class)->grid($cycle, $employee)['cells'])->toBe([]);
    $this->actingAs($employee);
    expect(NineBoxGrid::canAccess())->toBeFalse();
    actAsTenant(null);
    $this->get(NineBoxGrid::getUrl())->assertForbidden();
});

it('takes potential from the latest final talent assessment when chosen, shown only to people who may see talent data', function () {
    $this->settings->set('performance.nine_box.potential_source', 'talent', 'Use talent reviews');
    $cycle = hp8gCycle();
    hp8gScores($cycle, $this->a->id, 5, 1);
    hp8gScores($cycle, $this->b->id, 1, 5);
    hp8gScores($cycle, $this->c->id, 3, 3);
    $talentHr = tenantUser($this->tenant, ['talent.view', 'talent.assess', 'talent.manage', 'performance.view']);
    $assessments = app(TalentAssessments::class);
    $version = $assessments->defaultModelVersion();
    $assessments->finalize($assessments->draft($this->a, $version, ['performance' => 3, 'potential' => 3], 'Ready', null, $talentHr), $talentHr);
    $assessments->finalize($assessments->draft($this->b, $version, ['performance' => 1, 'potential' => 1], 'Early', null, $talentHr), $talentHr);

    $axes = app(NineBox::class)->axes($cycle);
    expect($axes['potential']['min'])->toBe(1.0)->and($axes['potential']['max'])->toBe(3.0);
    $this->actingAs($talentHr);
    $grid = app(NineBox::class)->grid($cycle, $talentHr);
    expect(collect($grid['cells']['high_high'])->pluck('employee_id')->all())->toBe([$this->a->id])
        ->and(collect($grid['cells']['low_low'])->pluck('employee_id')->all())->toBe([$this->b->id])
        ->and(collect($grid['unplaced'])->firstWhere('employee_id', $this->c->id)['reason'])->toBe('No final talent assessment');

    // A manager by the reporting line alone never sees talent data.
    $this->actingAs($this->manager->user);
    $team = app(NineBox::class)->grid($cycle, $this->manager->user);
    expect($team['cells'])->toBe([])->and(collect($team['unplaced'])->pluck('reason')->unique()->all())->toContain('Potential not shown (talent data)');
});

it('renders the 9-box page and keeps cycles, targets and bands inside their tenant', function () {
    $cycle = hp8gCycle();
    hp8gScores($cycle, $this->a->id, 5, 5);
    $this->distribution->saveTargets($cycle, hp8gTargets(), $this->hr);
    app(NineBox::class)->saveBands(null, 'performance', 2, 4, $this->hr);

    $other = provisionTenant('Other');
    actAsTenant($other);
    expect(PerformanceDistributionTarget::query()->count())->toBe(0)->and(PerformanceNineBoxBand::query()->count())->toBe(0)
        ->and(PerformanceCycle::query()->whereKey($cycle->id)->exists())->toBeFalse();
    actAsTenant(null);
    expect(PerformanceDistributionTarget::query()->count())->toBe(0);

    actAsTenant($this->tenant);
    Livewire::test(NineBoxGrid::class, ['cycle' => $cycle->id])->assertOk()->assertSee('Future leader')
        ->callAction('bands', data: ['scope' => 'cycle', 'performance_moderate' => 2, 'performance_high' => 4, 'potential_moderate' => 2, 'potential_high' => 4])
        ->assertNotified('Bands saved');
    expect(PerformanceNineBoxBand::query()->where('performance_cycle_id', $cycle->id)->count())->toBe(6);

    // HP-8j from a box: assign an initiative to the person.
    $type = PerformanceLookup::query()->ofKind('initiative_type')->where('code', 'TNI')->value('id');
    Livewire::test(NineBoxGrid::class, ['cycle' => $cycle->id])
        ->callAction('assignInitiative', data: ['initiative_type_id' => $type, 'title' => 'Leadership programme'], arguments: ['employee' => $this->a->id])
        ->assertNotified('Initiative assigned');
    expect(Initiative::query()->where('employee_id', $this->a->id)->value('title'))->toBe('Leadership programme');

    actAsTenant(null);
    $this->get(NineBoxGrid::getUrl(['cycle' => $cycle->id]))->assertOk()->assertSee('Future leader')->assertSee($this->a->person->full_name);
});
