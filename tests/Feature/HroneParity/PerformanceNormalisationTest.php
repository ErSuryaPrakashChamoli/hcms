<?php

use App\Domain\Audit\Enums\AuditAction;
use App\Domain\Audit\Models\AuditEvent;
use App\Domain\Configuration\Models\Policy;
use App\Domain\Configuration\Models\PolicyAssignmentRule;
use App\Domain\Configuration\Services\Policies;
use App\Domain\Employment\Models\Employee;
use App\Domain\Employment\Models\EmployeePosition;
use App\Domain\Experience\Models\BulkRun;
use App\Domain\Experience\Models\BulkRunItem;
use App\Domain\Identity\Services\AccessScopes;
use App\Domain\Organisation\Models\Company;
use App\Domain\Organisation\Models\Department;
use App\Domain\Performance\Models\Appraisal;
use App\Domain\Performance\Models\CalibrationAdjustment;
use App\Domain\Performance\Models\PerformanceCycle;
use App\Domain\Performance\Models\PerformancePeriodReview;
use App\Domain\Performance\Services\Appraisals;
use App\Domain\Performance\Services\PerformanceDistribution;
use App\Domain\Performance\Services\PerformancePolicyRules;
use App\Domain\Performance\Services\PeriodicReviews;
use App\Domain\Performance\Services\RatingNormalisation;
use App\Domain\Platform\Services\SettingsRepository;
use App\Filament\Pages\CalibrationBoard;
use App\Filament\Pages\TeamRatingDistribution;
use Livewire\Livewire;

require_once __DIR__.'/../Workflow/WorkflowTestHelpers.php';
require_once __DIR__.'/../Performance/PerformanceTestHelpers.php';

/* HROne parity GO-3 — bulk normalisation and team distribution: the normalise grid on the calibration board (system against
 * normalised rating per person, one reason per batch, each row through Appraisals::calibrate, people chosen by name,
 * organisation unit or pasted codes, large batches as WO-1 bulk runs), the periodic review ratings of one review period
 * (HP-8i), a manager's read-only team distribution (small groups suppressed, names only where the policy shows ratings),
 * and managers normalising their own team only while the owner switches PER-2 / D-GO2 on (off by default). */

beforeEach(function () {
    $this->travelTo('2026-09-21 09:00:00');
    $this->tenant = provisionTenant();
    actAsTenant($this->tenant);
    $this->hr = tenantUser($this->tenant, ['*']);
    $this->actingAs($this->hr);
    $this->manager = activeEmployee(null, ['performance.team', 'performance.review', 'performance.goals']);
    $this->a = activeEmployee($this->manager);
    $this->b = activeEmployee($this->manager);
    $this->c = activeEmployee($this->manager);
    $this->d = activeEmployee();
    // A performance administrator who is an employee too (their own appraisal is in the cycle).
    $this->hrEmployee = activeEmployee(null, ['performance.view', 'performance.calibrate', 'performance.manage', 'bulk.run']);
    $this->appraisals = app(Appraisals::class);
    $this->normalisation = app(RatingNormalisation::class);
    $this->settings = app(SettingsRepository::class);
});

/** A launched cycle with a final stage only (calibration open), every employed employee in it. */
function go3Cycle(string $code = 'GO3'): PerformanceCycle
{
    return app(Appraisals::class)->launch(draftCycle(['code' => $code, 'name' => "Cycle {$code}", 'stages' => [['key' => 'final', 'name' => 'Final rating', 'starts_on' => '2026-09-01', 'ends_on' => '2026-12-31']]]));
}

function go3Appraisal(PerformanceCycle $cycle, Employee $employee): Appraisal
{
    return Appraisal::query()->where('performance_cycle_id', $cycle->id)->where('employee_id', $employee->id)->sole();
}

/** Set the system-calculated rating directly. */
function go3System(PerformanceCycle $cycle, Employee $employee, ?float $rating): Appraisal
{
    Appraisal::query()->where('performance_cycle_id', $cycle->id)->where('employee_id', $employee->id)->update(['computed_rating' => $rating]);

    return go3Appraisal($cycle, $employee);
}

/** The one performance policy, for everyone. */
function go3Policy(array $settings): Policy
{
    PolicyAssignmentRule::query()->where('policy_type', 'performance')->delete();
    $policy = Policy::create(['type' => 'performance', 'name' => 'Performance', 'code' => 'PERF_'.uniqid()]);
    app(Policies::class)->draft($policy, $settings);
    app(Policies::class)->publish($policy, '2020-01-01');
    PolicyAssignmentRule::create(['policy_type' => 'performance', 'policy_id' => $policy->id, 'name' => 'All', 'conditions' => []]);
    app(PerformancePolicyRules::class)->forget();

    return $policy;
}

/** @return list<array<string, mixed>> grid rows as the wizard sends them */
function go3Rows(array $ratings, string $key = 'appraisal_id'): array
{
    return array_map(fn ($id, $r) => [$key => $id, 'expected' => $r[1] ?? null, 'rating' => $r[0] === null ? null : (string) $r[0]], array_keys($ratings), $ratings);
}

it('keeps today\'s behaviour by default: managers do not normalise, the team page only reads, calibrate is unchanged', function () {
    expect($this->settings->get('performance.normalise.by_managers'))->toBeFalse()
        ->and(json_encode(config('peopleos_setup')))->toContain('performance.normalise.by_managers')
        ->and($this->normalisation->byManagers())->toBeFalse()
        ->and($this->normalisation->mayNormalise($this->manager->user))->toBeFalse()
        ->and($this->normalisation->mayNormalise($this->hr))->toBeTrue();

    $cycle = go3Cycle();
    $a = go3System($cycle, $this->a, 3);
    expect($this->normalisation->refusal($a, $this->manager->user))->toBe('You cannot normalise this rating.')
        ->and(fn () => $this->normalisation->normalise($a, 4, 'Team view', $this->manager->user))->toThrow(RuntimeException::class, 'You cannot normalise this rating.');

    // The board's own per-row "Calibrate" goes through Appraisals::calibrate as before (no new refusal there).
    $this->appraisals->calibrate($a, 4, 'Panel', $this->hr, 3.0);
    expect((float) $a->refresh()->calibrated_rating)->toBe(4.0)->and($a->status)->toBe('calibration');

    // The distribution still reads the rating that stands unless asked for another one.
    $system = app(PerformanceDistribution::class)->compare($cycle, null, fn (Appraisal $x) => $x->computed_rating === null ? null : (float) $x->computed_rating);
    expect(collect(app(PerformanceDistribution::class)->compare($cycle)['rows'])->firstWhere('value', 4.0)['count'])->toBe(1)
        ->and(collect($system['rows'])->firstWhere('value', 3.0)['count'])->toBe(1);

    $this->actingAs($this->manager->user);
    Livewire::test(TeamRatingDistribution::class, ['cycle' => $cycle->id])->assertOk()->assertActionHidden('normaliseTeam');
});

it('applies the grid through calibrate: one reason for the batch, a history row and an audit event per appraisal', function () {
    $cycle = go3Cycle();
    $a = go3System($cycle, $this->a, 3);
    $b = go3System($cycle, $this->b, 4);

    $result = $this->normalisation->apply($cycle, [$a->id => ['rating' => 4, 'expected' => 3], $b->id => ['rating' => '2']], 'Moderation panel 21 Sep', $this->hr);

    expect($result['done'])->toBe([$a->id, $b->id])->and($result['refused'])->toBe([])->and($result['warning'])->toBeNull()
        ->and((float) $a->refresh()->calibrated_rating)->toBe(4.0)->and($a->calibration_note)->toBe('Moderation panel 21 Sep')->and($a->status)->toBe('calibration')
        ->and((float) $b->refresh()->calibrated_rating)->toBe(2.0)
        ->and(CalibrationAdjustment::query()->where('appraisal_id', $a->id)->sole())->toMatchArray(['reason' => 'Moderation panel 21 Sep', 'actor_id' => $this->hr->id]);

    $summary = AuditEvent::query()->where('action', AuditAction::BulkOperation)->latest('id')->firstOrFail();
    expect($summary->metadata['label'])->toBe('Normalise ratings: Cycle GO3')->and($summary->metadata['success_count'])->toBe(2)
        ->and($summary->reason)->toBe('Moderation panel 21 Sep')
        ->and(AuditEvent::query()->where('entity_type', Appraisal::class)->where('entity_id', (string) $a->id)->where('operation_id', $summary->operation_id)->exists())->toBeTrue();

    // The grid as the board shows it: alphabetical, the system rating and the normalised one so far, the rating seen.
    $grid = $this->normalisation->grid($cycle, [$this->a->id, $this->b->id], $this->hr);
    expect(collect($grid['rows'])->pluck('employee')->all())->toBe(collect([$this->a, $this->b])->map(fn ($e) => $e->person->full_name)->sort(fn ($x, $y) => strcasecmp($x, $y))->values()->all())
        ->and(collect($grid['rows'])->firstWhere('appraisal_id', $a->id))->toMatchArray(['system' => 3.0, 'normalised' => 4.0, 'expected' => 4.0, 'code' => $this->a->employee_code]);
});

it('refuses finalized, withdrawn, one\'s own, out-of-scope and changed-meanwhile ratings per row and goes on with the rest', function () {
    $cycle = go3Cycle();
    $a = go3System($cycle, $this->a, 3);
    $b = go3System($cycle, $this->b, 3);
    $c = go3System($cycle, $this->c, 3);
    $d = go3System($cycle, $this->d, 3);
    $own = go3System($cycle, $this->hrEmployee, 3);
    $this->appraisals->finalize($a, $this->hr, 3.0);
    $this->appraisals->withdraw($b, 'Moved abroad', $this->hr);
    $actor = $this->hrEmployee->user->fresh();

    $result = $this->normalisation->apply($cycle, [
        $a->id => ['rating' => 4], $b->id => ['rating' => 4], $own->id => ['rating' => 5], $c->id => ['rating' => 4, 'expected' => 2], $d->id => ['rating' => 2, 'expected' => 3], 999999 => ['rating' => 3],
    ], 'Panel', $actor);

    expect($result['done'])->toBe([$d->id])
        ->and($result['refused'])->toBe([
            $a->id => 'The appraisal is finalized.',
            $b->id => Appraisals::WITHDRAWN,
            $own->id => 'You cannot normalise your own rating.',
            $c->id => 'The rating was changed by someone else. Reload and try again.',
            999999 => 'Not an appraisal of this cycle.',
        ])
        ->and((float) $d->refresh()->calibrated_rating)->toBe(2.0)
        ->and($a->refresh()->calibrated_rating)->toBeNull()->and($c->refresh()->calibrated_rating)->toBeNull()->and($own->refresh()->calibrated_rating)->toBeNull();

    // HR scoped to another company reaches nobody here.
    $scoped = tenantUser($this->tenant, ['performance.view', 'performance.calibrate']);
    app(AccessScopes::class)->assign($scoped, ['company' => [Company::factory()->create()->id]], 'Other company only');
    expect($this->normalisation->refusal($c->refresh(), $scoped->fresh()))->toBe('Outside your access scope.');

    // The grid leaves them out and says why; withdrawn appraisals are not part of it at all.
    $grid = $this->normalisation->grid($cycle, Appraisal::query()->where('performance_cycle_id', $cycle->id)->pluck('employee_id')->all(), $actor);
    expect(collect($grid['rows'])->pluck('appraisal_id')->sort()->values()->all())->toBe(collect([$c->id, $d->id, go3Appraisal($cycle, $this->manager)->id])->sort()->values()->all())
        ->and($grid['skipped'])->toEqual(['The appraisal is finalized.' => 1, 'You cannot normalise your own rating.' => 1]);

    // A locked appraisal (its cycle closed) is refused too.
    Appraisal::query()->whereKey($c->id)->update(['locked_at' => now()]);
    expect($this->normalisation->refusal($c->refresh(), $this->hr))->toBe('The appraisal is locked: its cycle is closed.');
});

it('asks one reason for the batch and at least one rating', function () {
    $cycle = go3Cycle();
    $a = go3System($cycle, $this->a, 3);

    expect(fn () => $this->normalisation->apply($cycle, [$a->id => ['rating' => 4]], '  ', $this->hr))->toThrow(RuntimeException::class, 'Say why the ratings are normalised.')
        ->and(fn () => $this->normalisation->apply($cycle, [$a->id => ['rating' => 4]], str_repeat('x', 501), $this->hr))->toThrow(RuntimeException::class, 'at most 500 characters')
        ->and(fn () => $this->normalisation->apply($cycle, [], 'Panel', $this->hr))->toThrow(RuntimeException::class, 'Enter at least one normalised rating.')
        ->and(fn () => $this->normalisation->normalise($a, 9, 'Panel', $this->hr))->toThrow(RuntimeException::class, 'between 1 and 5');

    // On the board: the reason is required, and unchanged or blank rows are not sent.
    Livewire::test(CalibrationBoard::class, ['cycle' => $cycle->id])
        ->callAction('normalise', ['who' => 'everyone', 'rows' => go3Rows([$a->id => [5, 3]]), 'reason' => ''])
        ->assertHasActionErrors(['reason' => 'required']);
    Livewire::test(CalibrationBoard::class, ['cycle' => $cycle->id])
        ->callAction('normalise', ['who' => 'everyone', 'rows' => go3Rows([$a->id => [3, 3]]), 'reason' => 'Panel'])
        ->assertNotified('Not normalised');
    expect($a->refresh()->calibrated_rating)->toBeNull();
});

it('normalises only the people chosen on the board: by name, organisation unit or pasted codes, within the board', function () {
    $cycle = go3Cycle();
    foreach ([$this->a, $this->b, $this->c, $this->d] as $employee) {
        go3System($cycle, $employee, 3);
    }
    [$a, $b, $c, $d] = array_map(fn ($e) => go3Appraisal($cycle, $e), [$this->a, $this->b, $this->c, $this->d]);
    $sales = Department::factory()->create(['name' => 'Sales']);
    EmployeePosition::query()->where('employee_id', $this->c->id)->update(['department_id' => $sales->id]);

    // Pasted codes: only A changes, whatever the grid sent.
    Livewire::test(CalibrationBoard::class, ['cycle' => $cycle->id])
        ->callAction('normalise', ['who' => 'codes', 'codes' => $this->a->employee_code, 'rows' => go3Rows([$a->id => [5, 3], $b->id => [1, 3]]), 'reason' => 'Codes'])
        ->assertHasNoActionErrors()->assertNotified('1 rating(s) normalised');
    expect((float) $a->refresh()->calibrated_rating)->toBe(5.0)->and($b->refresh()->calibrated_rating)->toBeNull();

    // An organisation unit (department with sub-departments).
    Livewire::test(CalibrationBoard::class, ['cycle' => $cycle->id])
        ->callAction('normalise', ['who' => 'organisation', 'organisation' => ['department_id' => [$sales->id]], 'rows' => go3Rows([$c->id => [2, 3], $b->id => [1, 3]]), 'reason' => 'Sales'])
        ->assertHasNoActionErrors();
    expect((float) $c->refresh()->calibrated_rating)->toBe(2.0)->and($b->refresh()->calibrated_rating)->toBeNull();

    // Chosen employees ("Search team member").
    Livewire::test(CalibrationBoard::class, ['cycle' => $cycle->id])
        ->callAction('normalise', ['who' => 'people', 'employee_ids' => [$this->d->id], 'rows' => go3Rows([$d->id => [4, 3]]), 'reason' => 'Named'])
        ->assertHasNoActionErrors();
    expect((float) $d->refresh()->calibrated_rating)->toBe(4.0);

    // The board's own filter narrows the candidates: B is not in Sales, so "everyone on the board" leaves B out.
    Livewire::test(CalibrationBoard::class, ['cycle' => $cycle->id, 'department' => $sales->id])
        ->callAction('normalise', ['who' => 'everyone', 'rows' => go3Rows([$b->id => [1, 3]]), 'reason' => 'Board filter'])
        ->assertNotified('Not normalised');
    expect($b->refresh()->calibrated_rating)->toBeNull();
});

it('runs a large batch as a WO-1 bulk run with the typed count and a result per row', function () {
    config(['peopleos.bulk.confirm_over' => 1]);
    $cycle = go3Cycle();
    $a = go3System($cycle, $this->a, 3);
    $b = go3System($cycle, $this->b, 3);
    $this->appraisals->finalize($b, $this->hr, 3.0);

    Livewire::test(CalibrationBoard::class, ['cycle' => $cycle->id])
        ->callAction('normalise', ['who' => 'everyone', 'rows' => go3Rows([$a->id => [4, 3], $b->id => [2, 3]]), 'reason' => 'Year-end panel'])
        ->assertHasActionErrors(['confirm_changes' => 'required']);

    Livewire::test(CalibrationBoard::class, ['cycle' => $cycle->id])
        ->callAction('normalise', ['who' => 'everyone', 'rows' => go3Rows([$a->id => [4, 3], $b->id => [2, 3]]), 'reason' => 'Year-end panel', 'confirm_changes' => 2])
        ->assertHasNoActionErrors();

    $run = BulkRun::query()->latest('id')->firstOrFail();
    expect($run->operation)->toBe('appraisals.normalise')->and($run->status)->toBe('completed')->and($run->done)->toBe(1)->and($run->refused)->toBe(1)
        ->and($run->input['reason'])->toBe('Year-end panel')
        ->and(BulkRunItem::query()->where('bulk_run_id', $run->id)->where('record_id', $b->id)->value('message'))->toBe('The appraisal is finalized.')
        ->and((float) $a->refresh()->calibrated_rating)->toBe(4.0)->and($a->calibration_note)->toBe('Year-end panel');
});

it('shows a manager their own team\'s distribution, system against final, suppressing small groups', function () {
    config(['peopleos.performance.analytics_min_group' => 2]);
    $cycle = go3Cycle();
    go3System($cycle, $this->a, 4);
    go3System($cycle, $this->b, 4);
    $c = go3System($cycle, $this->c, 2);
    go3System($cycle, $this->d, 5);       // not in the team
    go3System($cycle, $this->manager, 1); // the manager's own: never in their team view
    $this->normalisation->normalise($c, 3, 'Panel', $this->hr);

    $team = app(PerformanceDistribution::class)->team($cycle, $this->manager->user);
    $system = collect($team['system']['rows'])->keyBy('value');
    $final = collect($team['final']['rows'])->keyBy('value');
    expect($team)->toMatchArray(['members' => 3, 'counted' => 3, 'hidden' => 0, 'min_group' => 2])
        ->and($system[4.0]['count'])->toBe(2)->and($system[2.0]['count'])->toBe(1)->and($system[5.0]['count'])->toBe(0)->and($system[1.0]['count'])->toBe(0)
        ->and($final[4.0]['count'])->toBe(2)->and($final[3.0]['count'])->toBe(1)->and($final[2.0]['count'])->toBe(0)
        ->and($team['system']['suppressed'])->toBeFalse()
        ->and(collect($team['names'])->pluck('name')->all())->toBe(collect([$this->a, $this->b, $this->c])->map(fn ($e) => $e->person->full_name)->sort(fn ($x, $y) => strcasecmp($x, $y))->values()->all());

    // Below the minimum group, the curves are not shown (no count, no share).
    config(['peopleos.performance.analytics_min_group' => 5]);
    $small = app(PerformanceDistribution::class)->team($cycle, $this->manager->user);
    expect($small['system']['suppressed'])->toBeTrue()->and($small['final']['suppressed'])->toBeTrue()
        ->and(collect($small['final']['rows'])->pluck('count')->unique()->all())->toBe([null])
        ->and(collect($small['final']['rows'])->pluck('actual')->unique()->all())->toBe([null]);

    // The page: the manager's team only (D is never named), read-only.
    config(['peopleos.performance.analytics_min_group' => 2]);
    $this->actingAs($this->manager->user);
    Livewire::test(TeamRatingDistribution::class, ['cycle' => $cycle->id])->assertOk()
        ->assertSee('System calculated rating')->assertSee($this->a->person->full_name)->assertDontSee($this->d->person->full_name)
        ->assertActionHidden('normaliseTeam');

    // Not a manager: no page.
    $this->actingAs($this->a->user);
    expect(TeamRatingDistribution::canAccess())->toBeFalse();
    $url = TeamRatingDistribution::getUrl(['cycle' => $cycle->id]);
    actAsTenant(null);
    $this->get($url)->assertForbidden();
    $this->actingAs($this->manager->user);
    actAsTenant(null);
    $this->get($url)->assertOk()->assertSee('Team rating distribution');
});

it('counts and names nobody whose rating the performance policy keeps from the manager', function () {
    config(['peopleos.performance.analytics_min_group' => 1]);
    $cycle = go3Cycle();
    go3System($cycle, $this->a, 4);
    go3System($cycle, $this->b, 2);
    go3Policy(['final_rating_visible_to' => 'none']);

    $team = app(PerformanceDistribution::class)->team($cycle, $this->manager->user);
    expect($team)->toMatchArray(['members' => 3, 'counted' => 0, 'hidden' => 3, 'names' => []])
        ->and($team['final']['rated'])->toBe(0)->and($team['system']['rated'])->toBe(0);

    $this->actingAs($this->manager->user);
    Livewire::test(TeamRatingDistribution::class, ['cycle' => $cycle->id])->assertOk()
        ->assertSee('not counted')->assertDontSee($this->a->person->full_name)->assertDontSee($this->b->person->full_name);
});

it('normalises periodic review ratings per review period through the periodic review service', function () {
    go3Policy(['review_cycle' => 'quarterly', 'review_window_days' => 15, 'appraisal_basis' => 'average']);
    $cycle = go3Cycle();
    $service = app(PeriodicReviews::class);
    $this->travelTo('2026-10-01 09:00:00');
    $service->runSchedule();
    $q2 = fn (Employee $e) => PerformancePeriodReview::query()->where('employee_id', $e->id)->where('performance_cycle_id', $cycle->id)->where('sequence', 2)->sole();
    $service->submit($q2($this->a), 4, 'Strong quarter', $this->manager->user);
    $service->submit($q2($this->b), 4, null, $this->manager->user);
    $a = go3Appraisal($cycle, $this->a);
    expect((float) $a->computed_rating)->toBe(4.0)
        ->and($service->periodsToNormalise($cycle))->toBe([2 => '#2 · Jul 2026 – Sep 2026 (2 rated)']);

    $review = $service->normalise($q2($this->a), 3, 'Quarter moderation', $this->hr, 4.0);
    expect((float) $review->rating)->toBe(4.0)->and((float) $review->normalised_rating)->toBe(3.0)->and($review->normalisation_note)->toBe('Quarter moderation')
        ->and($review->normalised_by)->toBe($this->hr->id)->and($review->effectiveRating())->toBe(3.0)
        // The roll-up reads the normalised rating.
        ->and((float) $a->refresh()->computed_rating)->toBe(3.0)
        ->and(AuditEvent::query()->where('entity_type', PerformancePeriodReview::class)->where('entity_id', (string) $review->id)->where('reason', 'Quarter moderation')->exists())->toBeTrue();

    // The same rules: a reason, the scale, a rating seen, only submitted reviews, HR only, never one's own; read-only otherwise.
    $q3 = PerformancePeriodReview::query()->where('employee_id', $this->a->id)->where('sequence', 3)->sole();
    $ownReview = $q2($this->hrEmployee);
    expect(fn () => $service->normalise($q2($this->b), 3, '', $this->hr))->toThrow(RuntimeException::class, 'Say why')
        ->and(fn () => $service->normalise($q2($this->b), 7, 'Panel', $this->hr))->toThrow(RuntimeException::class, 'between 1 and 5')
        ->and(fn () => $service->normalise($q2($this->b), 3, 'Panel', $this->hr, 2.0))->toThrow(RuntimeException::class, 'changed by someone else')
        ->and(fn () => $service->normalise($q3, 3, 'Panel', $this->hr))->toThrow(RuntimeException::class, 'Only a submitted periodic review')
        ->and(fn () => $service->normalise($q2($this->b), 3, 'Panel', $this->manager->user))->toThrow(RuntimeException::class, 'You cannot normalise review ratings.')
        ->and($service->normaliseRefusal($ownReview, $this->hrEmployee->user->fresh()))->toBe('You cannot normalise your own rating.')
        ->and(fn () => $q2($this->a)->update(['rating' => 5]))->toThrow(RuntimeException::class, 'read-only');

    // A batch of one period: refusals per row, the rest done, one audit operation.
    $result = $service->normaliseBatch($cycle, 2, [$q2($this->b)->id => ['rating' => 5, 'expected' => 4], $q3->id => ['rating' => 2]], 'Batch panel', $this->hr);
    expect($result['done'])->toBe([$q2($this->b)->id])->and($result['refused'])->toBe([$q3->id => 'Not a periodic review of this period.'])
        ->and(AuditEvent::query()->where('action', AuditAction::BulkOperation)->latest('id')->first()->metadata['label'])->toBe('Normalise review ratings: Cycle GO3 #2');

    // On the board: "Normalise review ratings" for one review period.
    Livewire::test(CalibrationBoard::class, ['cycle' => $cycle->id])
        ->assertActionVisible('normaliseReviews')
        ->callAction('normaliseReviews', ['sequence' => 2, 'who' => 'everyone', 'rows' => go3Rows([$q2($this->a)->id => [2, 3]], 'review_id'), 'reason' => 'Board panel'])
        ->assertHasNoActionErrors()->assertNotified('1 review rating(s) normalised');
    expect((float) $q2($this->a)->normalised_rating)->toBe(2.0)->and((float) $a->refresh()->computed_rating)->toBe(2.0);

    // Finalized appraisals are refused.
    $this->appraisals->finalize($a->refresh(), $this->hr, 3.0);
    expect($service->normaliseRefusal($q2($this->a), $this->hr))->toBe('The appraisal is finalized.');
});

it('lets managers normalise only their own reports while the owner switches PER-2 on; HR sees who changed it', function () {
    $cycle = go3Cycle();
    $a = go3System($cycle, $this->a, 3);
    $b = go3System($cycle, $this->b, 3);
    $d = go3System($cycle, $this->d, 3);
    $mine = go3System($cycle, $this->manager, 3);
    $manager = $this->manager->user->fresh();

    $this->settings->set('performance.normalise.by_managers', true, 'PER-2 decided');
    expect($this->normalisation->mayNormalise($manager))->toBeTrue()->and($this->normalisation->asManager($manager))->toBeTrue();

    $result = $this->normalisation->apply($cycle, [$a->id => ['rating' => 4, 'expected' => 3], $d->id => ['rating' => 1], $mine->id => ['rating' => 5]], 'Team moderation', $manager);
    expect($result['done'])->toBe([$a->id])
        ->and($result['refused'])->toBe([$d->id => 'You cannot normalise this rating.', $mine->id => 'You cannot normalise this rating.'])
        ->and(CalibrationAdjustment::query()->where('appraisal_id', $a->id)->sole()->actor_id)->toBe($manager->id)
        ->and($a->refresh()->calibration_note)->toBe('Team moderation');

    // HR sees the change on the board ("Last normalised by") and in the audit trail.
    Livewire::test(CalibrationBoard::class, ['cycle' => $cycle->id])->toggleAllTableColumns()
        ->assertTableColumnFormattedStateSet('last_normaliser_id', $manager->name, $a);

    // The distribution check still applies: its warning comes back after the batch.
    $this->settings->set('performance.distribution.check', 'warn', 'Show the gap');
    app(PerformanceDistribution::class)->saveTargets($cycle, ['1' => 50, '2' => 50, '3' => 0, '4' => 0, '5' => 0], $this->hr);
    expect($this->normalisation->apply($cycle, [$b->id => ['rating' => 4]], 'Team moderation', $manager)['warning'])->toContain('differs from its targets');

    // The team page offers the grid over the manager's team only.
    $this->actingAs($manager);
    Livewire::test(TeamRatingDistribution::class, ['cycle' => $cycle->id])->assertActionVisible('normaliseTeam')
        ->callAction('normaliseTeam', ['who' => 'everyone', 'rows' => go3Rows([$b->id => [2, 4], $d->id => [5, 3]]), 'reason' => 'Second look'])
        ->assertHasNoActionErrors()->assertNotified('1 rating(s) normalised');
    expect((float) $b->refresh()->calibrated_rating)->toBe(2.0)->and($d->refresh()->calibrated_rating)->toBeNull();

    // A rating the policy keeps from the manager is refused.
    go3Policy(['final_rating_visible_to' => 'none']);
    expect($this->normalisation->refusal(go3Appraisal($cycle, $this->c), $manager))->toBe('The performance policy keeps this rating from you.');

    // Switched off again: back to HR only.
    $this->settings->set('performance.normalise.by_managers', false, 'Back to HR');
    expect($this->normalisation->refusal(go3Appraisal($cycle, $this->c), $manager))->toBe('You cannot normalise this rating.');
});

it('walks the wizard: the grid fills from the people chosen, then applies the changed rows with the reason', function () {
    $cycle = go3Cycle();
    $a = go3System($cycle, $this->a, 3);
    $b = go3System($cycle, $this->b, 4);
    $c = go3System($cycle, $this->c, 2);
    $this->appraisals->finalize($c, $this->hr, 2.0);

    $board = Livewire::test(CalibrationBoard::class, ['cycle' => $cycle->id])
        ->mountAction('normalise')
        ->fillForm(['who' => 'codes', 'codes' => $this->a->employee_code.', '.$this->b->employee_code.', '.$this->c->employee_code])
        ->goToNextWizardStep();
    $rows = collect($board->get('mountedActions.0.data.rows'));
    expect($rows->pluck('appraisal_id')->sort()->values()->all())->toBe(collect([$a->id, $b->id])->sort()->values()->all())
        ->and($rows->firstWhere('appraisal_id', $b->id))->toMatchArray(['code' => $this->b->employee_code, 'system' => '4', 'normalised' => '—', 'expected' => 4.0, 'rating' => null])
        ->and($board->get('mountedActions.0.data.skipped'))->toBe('Not in the grid: 1 — The appraisal is finalized.');

    $board->set("mountedActions.0.data.rows.row{$a->id}.rating", '5')
        ->set('mountedActions.0.data.reason', 'Walked the wizard')
        ->callMountedAction()
        ->assertHasNoActionErrors()->assertNotified('1 rating(s) normalised');
    expect((float) $a->refresh()->calibrated_rating)->toBe(5.0)->and($a->calibration_note)->toBe('Walked the wizard')
        ->and($b->refresh()->calibrated_rating)->toBeNull();

    // Nobody left to normalise: the wizard stops at "Who" and says why.
    Livewire::test(CalibrationBoard::class, ['cycle' => $cycle->id])
        ->mountAction('normalise')
        ->fillForm(['who' => 'codes', 'codes' => $this->c->employee_code])
        ->goToNextWizardStep()
        ->assertNotified('Cannot continue');
});
