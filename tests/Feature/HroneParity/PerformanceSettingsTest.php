<?php

use App\Domain\Lifecycle\Models\EmployeeTimelineEntry;
use App\Domain\Performance\Models\Appraisal;
use App\Domain\Performance\Models\Goal;
use App\Domain\Performance\Models\OneOnOne;
use App\Domain\Performance\Models\PerformanceCycle;
use App\Domain\Performance\Models\RatingScale;
use App\Domain\Performance\Services\Appraisals;
use App\Domain\Performance\Services\CareerPassport;
use App\Domain\Performance\Services\Goals;
use App\Domain\Performance\Services\OneOnOnes;
use App\Domain\Performance\Services\PerformanceReminders;
use App\Domain\Performance\Support\AppraisalVisibility;
use App\Domain\Performance\Support\OneOnOneFeedbackTaskSource;
use App\Domain\Performance\Support\PerformancePeriods;
use App\Domain\Performance\Support\RatingRules;
use App\Domain\Platform\Services\SettingsRepository;
use App\Filament\Pages\CalibrationBoard;
use App\Filament\Resources\Appraisals\Pages\ViewAppraisal;
use App\Filament\Resources\Appraisals\RelationManagers\ReviewsRelationManager;
use App\Filament\Resources\Employees\Pages\ViewEmployee;
use App\Filament\Resources\Employees\RelationManagers\PerformanceRelationManager;
use App\Filament\Resources\OneOnOnes\Pages\ManageOneOnOnes;
use App\Filament\Resources\PerformanceCycles\Pages\CreatePerformanceCycle;
use App\Filament\Support\PerformanceActions;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Illuminate\Support\Carbon;
use Livewire\Livewire;

require_once __DIR__.'/../Workflow/WorkflowTestHelpers.php';
require_once __DIR__.'/../Performance/PerformanceTestHelpers.php';

/* HROne setup parity HP-8b — Performance general settings and the matrix defects: rating publication (auto / manual,
 * bulk publish; employees never see unpublished ratings), the goal-edit window, decimals and rounding, cycle type and
 * performance year, one-on-one scope and feedback cards, scheduled goal check-in prompts, 360 feedback on the profile,
 * and the cycle options "Show self review to manager" / "Peer reviews anonymous by default" (read nowhere before). */

beforeEach(function () {
    $this->travelTo('2026-09-21 09:00:00'); // a Monday
    $this->tenant = provisionTenant();
    actAsTenant($this->tenant);
    $this->hr = tenantUser($this->tenant, ['*']);
    $this->actingAs($this->hr);
    $this->manager = activeEmployee(null, ['performance.team', 'performance.review', 'performance.goals', 'performance.checkins']);
    $this->employee = activeEmployee($this->manager);
    $this->peer = activeEmployee($this->manager);
    $this->appraisals = app(Appraisals::class);
    $this->settings = app(SettingsRepository::class);
});

/** A launched cycle that has reached its calibration stage, with the employee's self and manager reviews submitted. */
function perfSetReviewedCycle(array $settings = []): PerformanceCycle
{
    $appraisals = app(Appraisals::class);
    $cycle = $appraisals->launch(draftCycle(['settings' => $settings]));
    $cycle = $appraisals->advance($cycle); // self review
    foreach (Appraisal::query()->where('performance_cycle_id', $cycle->id)->get() as $a) {
        if ($a->review('self')) {
            $appraisals->submitReview($a->review('self'), [], array_fill_keys($cycle->competency_ids, 4), 4, ['strengths' => 'Self view']);
        }
    }
    $cycle = $appraisals->advance($cycle); // manager review
    foreach (Appraisal::query()->where('performance_cycle_id', $cycle->id)->get() as $a) {
        if ($a->review('manager')) {
            $appraisals->submitReview($a->review('manager'), [], array_fill_keys($cycle->competency_ids, 3), 3, ['strengths' => 'Manager view']);
        }
    }

    return $appraisals->advance($cycle); // calibration
}

function perfSetFinalOnlyCycle(string $code = 'FINAL'): PerformanceCycle
{
    return app(Appraisals::class)->launch(draftCycle(['code' => $code, 'stages' => [['key' => 'final', 'name' => 'Final rating', 'starts_on' => '2026-09-01', 'ends_on' => '2026-12-31']]]));
}

function perfSetTitles($user): array
{
    return $user->notifications()->get()->pluck('data.title')->all();
}

it('keeps the earlier behaviour by default: the rating is published (and the employee told) when it is finalized', function () {
    expect($this->settings->get('performance.rating_publish'))->toBe('auto')
        ->and($this->settings->get('performance.goals.edit_only_in_goal_stage'))->toBeFalse()
        ->and($this->settings->get('performance.one_on_one.scope'))->toBe('team')
        ->and($this->settings->get('performance.one_on_one.feedback_from'))->toBe([])
        ->and(RatingRules::decimals())->toBe(0)->and(RatingRules::rounding())->toBe('round')
        ->and(PerformancePeriods::yearStartMonth())->toBe(0)->and(PerformancePeriods::defaultType())->toBe('annual');

    $cycle = perfSetFinalOnlyCycle();
    $appraisal = Appraisal::query()->where('performance_cycle_id', $cycle->id)->where('employee_id', $this->employee->id)->firstOrFail();
    $this->appraisals->finalize($appraisal, $this->hr, 4.0, 'Good year');

    expect($appraisal->refresh()->isPublished())->toBeTrue()->and($appraisal->published_by)->toBe($this->hr->id)
        ->and(collect(perfSetTitles($this->employee->user))->filter(fn ($t) => str_contains($t, 'is final'))->count())->toBe(1)
        ->and(AppraisalVisibility::ratingsVisible($appraisal, $this->employee->user))->toBeTrue()
        ->and(PerformanceActions::unpublished($cycle))->toBe(0);
    $this->appraisals->acknowledge($appraisal, 'Thanks');
    expect($appraisal->refresh()->status)->toBe('acknowledged');
});

it('publishes ratings manually: the employee sees no rating or review by others until HR publishes, then is told once', function () {
    $this->settings->set('performance.rating_publish', 'manual', 'Calibrate first');
    $cycle = perfSetReviewedCycle();
    $appraisal = Appraisal::query()->where('performance_cycle_id', $cycle->id)->where('employee_id', $this->employee->id)->firstOrFail();
    $peerReview = $this->appraisals->addPeerReview($appraisal, $this->peer);
    $this->appraisals->submitReview($peerReview, [], array_fill_keys($cycle->competency_ids, 4), 4);
    $this->appraisals->finalize($appraisal->refresh(), $this->hr, 4.0, 'Strong');

    $appraisal->refresh();
    expect($appraisal->status)->toBe('finalized')->and($appraisal->isPublished())->toBeFalse()
        ->and(collect(perfSetTitles($this->employee->user))->filter(fn ($t) => str_contains($t, 'is final')))->toBeEmpty()
        ->and(EmployeeTimelineEntry::query()->where('employee_id', $this->employee->id)->where('category', 'performance')->exists())->toBeFalse()
        ->and(AppraisalVisibility::ratingsVisible($appraisal, $this->employee->user))->toBeFalse()
        ->and(AppraisalVisibility::ratingsVisible($appraisal, $this->manager->user))->toBeTrue()
        ->and(AppraisalVisibility::ratingsVisible($appraisal, $this->hr))->toBeTrue()
        ->and(AppraisalVisibility::ratingsVisible($appraisal, $this->peer->user))->toBeFalse()
        ->and(fn () => $this->appraisals->acknowledge($appraisal, 'ok'))->toThrow(RuntimeException::class, 'not been published')
        ->and(app(CareerPassport::class)->build($this->employee)['performance'])->toBe([]);

    // The employee sees their own self review only (a peer reviewer never sees the outcome).
    $reviews = $appraisal->reviews()->get()->keyBy('type');
    $this->actingAs($this->employee->user);
    Livewire::test(ReviewsRelationManager::class, ['ownerRecord' => $appraisal, 'pageClass' => ViewAppraisal::class])
        ->assertCanSeeTableRecords([$reviews['self']])->assertCanNotSeeTableRecords([$reviews['manager'], $reviews['peer']]);
    expect(fn () => $this->appraisals->publish($cycle, null, $this->employee->user))->toThrow(RuntimeException::class, 'cannot publish');
    $this->actingAs($this->hr);

    // The cycle cannot close (and lock) with unpublished ratings.
    $cycle->appraisals()->where('id', '!=', $appraisal->id)->get()->each(fn (Appraisal $a) => $this->appraisals->finalize($a, $this->hr, 3.0));
    expect(PerformanceActions::unpublished($cycle))->toBe(3)
        ->and(fn () => $this->appraisals->close($cycle->refresh()))->toThrow(RuntimeException::class, 'not published yet');

    // Publishing one appraisal, then the rest of the cycle; each once.
    expect($this->appraisals->publish($cycle, [$appraisal->id], $this->hr))->toBe(1)
        ->and($this->appraisals->publish($cycle, [$appraisal->id], $this->hr))->toBe(0);
    $appraisal->refresh();
    expect($appraisal->isPublished())->toBeTrue()->and($appraisal->published_by)->toBe($this->hr->id)
        ->and(collect(perfSetTitles($this->employee->user))->filter(fn ($t) => str_contains($t, 'is final: Exceeds Expectations'))->count())->toBe(1)
        ->and(EmployeeTimelineEntry::query()->where('employee_id', $this->employee->id)->where('category', 'performance')->count())->toBe(1)
        ->and(AppraisalVisibility::ratingsVisible($appraisal, $this->employee->user))->toBeTrue()
        ->and(AppraisalVisibility::ratingsVisible($appraisal, $this->peer->user))->toBeFalse();
    $this->actingAs($this->employee->user);
    Livewire::test(ReviewsRelationManager::class, ['ownerRecord' => $appraisal, 'pageClass' => ViewAppraisal::class])
        ->assertCanSeeTableRecords([$reviews['self'], $reviews['manager'], $reviews['peer']]);
    $this->appraisals->acknowledge($appraisal, 'Thanks');
    $this->actingAs($this->hr);

    Livewire::test(CalibrationBoard::class)->assertActionVisible('publishRatings')->callAction('publishRatings')->assertNotified('2 rating(s) published');
    expect(PerformanceActions::unpublished($cycle))->toBe(0)
        ->and($this->appraisals->close($cycle->refresh())->status)->toBe('closed');
});

it('wires the cycle options: the self review hidden from managers when turned off, peers anonymous by default', function () {
    $cycle = perfSetReviewedCycle(['show_self_to_manager' => false, 'peer_anonymous' => true]);
    $appraisal = Appraisal::query()->where('performance_cycle_id', $cycle->id)->where('employee_id', $this->employee->id)->firstOrFail();
    expect(AppraisalVisibility::selfReviewVisible($appraisal, $this->manager->user))->toBeFalse()
        ->and(AppraisalVisibility::selfReviewVisible($appraisal, $this->hr))->toBeTrue()
        ->and(AppraisalVisibility::selfReviewVisible($appraisal, $this->employee->user))->toBeTrue()
        ->and($this->appraisals->addPeerReview($appraisal, $this->peer)->is_anonymous)->toBeTrue()
        ->and($this->appraisals->addPeerReview($appraisal, $this->manager, 'upward', false)->is_anonymous)->toBeFalse();

    $self = $appraisal->reviews()->where('type', 'self')->firstOrFail();
    $this->actingAs($this->manager->user);
    Livewire::test(ReviewsRelationManager::class, ['ownerRecord' => $appraisal, 'pageClass' => ViewAppraisal::class])
        ->assertCanNotSeeTableRecords([$self])->assertCanSeeTableRecords([$appraisal->reviews()->where('type', 'manager')->firstOrFail()]);

    // A cycle without the options behaves as before.
    $this->actingAs($this->hr);
    $plain = perfSetFinalOnlyCycle('PLAIN');
    $other = Appraisal::query()->where('performance_cycle_id', $plain->id)->where('employee_id', $this->employee->id)->firstOrFail();
    expect(AppraisalVisibility::selfReviewVisible($other, $this->manager->user))->toBeTrue()
        ->and($this->appraisals->addPeerReview($other, $this->peer)->is_anonymous)->toBeFalse();
});

it('limits goal changes to the goal-setting period when asked (administrators and check-ins are not limited)', function () {
    $goals = app(Goals::class);
    $draft = draftCycle(['code' => 'DRAFT']);
    $early = $goals->create(['employee_id' => $this->employee->id, 'performance_cycle_id' => $draft->id, 'title' => 'Before the window', 'weight' => 10], [], $this->employee->user);
    expect($early->exists)->toBeTrue();

    $this->settings->set('performance.goals.edit_only_in_goal_stage', true, 'Goal window');
    expect(fn () => $goals->create(['employee_id' => $this->employee->id, 'performance_cycle_id' => $draft->id, 'title' => 'Too early'], [], $this->employee->user))->toThrow(RuntimeException::class, 'goal-setting period')
        ->and($goals->create(['employee_id' => $this->employee->id, 'title' => 'Outside any cycle'], [], $this->employee->user)->exists)->toBeTrue()
        ->and($goals->create(['employee_id' => $this->employee->id, 'performance_cycle_id' => $draft->id, 'title' => 'By HR'], [], $this->hr)->exists)->toBeTrue();

    $cycle = $this->appraisals->launch(draftCycle(['code' => 'WINDOW', 'stages' => [
        ['key' => 'goal_setting', 'name' => 'Goal setting', 'starts_on' => '2026-09-01', 'ends_on' => '2026-09-30'],
        ['key' => 'self_review', 'name' => 'Self review', 'starts_on' => '2026-10-01', 'ends_on' => '2026-10-15'],
        ['key' => 'final', 'name' => 'Final rating', 'starts_on' => '2026-10-16', 'ends_on' => '2026-10-31'],
    ]]));
    $goal = $goals->create(['employee_id' => $this->employee->id, 'performance_cycle_id' => $cycle->id, 'title' => 'Ship v2', 'weight' => 50], [], $this->employee->user);
    $this->actingAs($this->employee->user);
    expect($goals->update($goal, ['description' => 'Scope agreed'], null, $this->employee->user)->description)->toBe('Scope agreed');

    $this->actingAs($this->hr);
    $cycle = $this->appraisals->advance($cycle); // self review: the goal period is over
    $this->actingAs($this->employee->user);
    expect(fn () => $goals->update($goal->refresh(), ['description' => 'Late change'], null, $this->employee->user))->toThrow(RuntimeException::class, 'goal-setting period')
        ->and(fn () => $goals->close($goal->refresh(), 'cancelled', 'Dropped'))->toThrow(RuntimeException::class, 'goal-setting period')
        ->and((float) $goals->checkIn($goal->refresh(), 40, null, null, $this->employee->user)->progress)->toBe(40.0);
    $this->actingAs($this->hr);
    expect($goals->update($goal->refresh(), ['description' => 'HR note'], null, $this->hr)->description)->toBe('HR note');
});

it('gives final ratings in decimals and rounds the default and the label by the chosen rule', function () {
    $scale = RatingScale::default();
    expect(RatingRules::defaultFor($scale, 3.84))->toBe('4')->and(RatingRules::label($scale, 3.4))->toBe('Meets Expectations');

    $this->settings->set('performance.rating.rounding', 'floor', 'Round down');
    expect(RatingRules::defaultFor($scale, 3.84))->toBe('3')->and(RatingRules::label($scale, 3.84))->toBe('Meets Expectations');
    $this->settings->set('performance.rating.rounding', 'ceil', 'Round up');
    expect(RatingRules::defaultFor($scale, 3.2))->toBe('4')->and(RatingRules::label($scale, 3.2))->toBe('Exceeds Expectations');

    $cycle = perfSetFinalOnlyCycle();
    $appraisal = Appraisal::query()->where('performance_cycle_id', $cycle->id)->where('employee_id', $this->employee->id)->firstOrFail();
    expect(PerformanceActions::ratingField('rating', 'Final rating', $appraisal->load('cycle')))->toBeInstanceOf(Select::class);

    $this->settings->set('performance.rating.final_decimals', 1, 'Decimals');
    expect(RatingRules::defaultFor($scale, 3.84))->toBe('3.9')
        ->and(PerformanceActions::ratingField('rating', 'Final rating', $appraisal))->toBeInstanceOf(TextInput::class);
    $this->settings->set('performance.rating.rounding', 'round', 'Nearest');
    expect(RatingRules::round(3.84))->toBe(3.8);

    $this->settings->set('performance.rating.rounding', 'floor', 'Round down');
    $this->appraisals->finalize($appraisal, $this->hr, 3.7);
    expect((float) $appraisal->refresh()->final_rating)->toBe(3.7)->and($appraisal->final_label)->toBe('Meets Expectations');
});

it('fills a new cycle from the default type and the performance year, and opens pages on the current period', function () {
    expect(PerformancePeriods::containing('annual'))->toBeNull();
    $this->settings->set('performance.year_start_month', 4, 'April year');
    $this->settings->set('performance.cycle.default_type', 'half_yearly', 'Two cycles a year');
    $dates = fn (string $type) => array_map(fn ($d) => $d->toDateString(), PerformancePeriods::containing($type) ?? []);
    expect($dates('annual'))->toBe(['2026-04-01', '2027-03-31'])
        ->and($dates('half_yearly'))->toBe(['2026-04-01', '2026-09-30'])
        ->and($dates('quarterly'))->toBe(['2026-07-01', '2026-09-30'])
        ->and($dates('probation'))->toBe([])
        ->and(array_map(fn ($d) => $d->toDateString(), PerformancePeriods::containing('annual', Carbon::parse('2027-02-10'))))->toBe(['2026-04-01', '2027-03-31']);

    Livewire::test(CreatePerformanceCycle::class)
        ->assertFormSet(['type' => 'half_yearly', 'period_start' => '2026-04-01', 'period_end' => '2026-09-30'])
        ->fillForm(['type' => 'quarterly'])->assertFormSet(['period_start' => '2026-07-01', 'period_end' => '2026-09-30']);

    $current = draftCycle(['name' => 'H1', 'code' => 'H1', 'type' => 'half_yearly', 'period_start' => '2026-04-01', 'period_end' => '2026-09-30', 'status' => 'active']);
    draftCycle(['name' => 'H2', 'code' => 'H2', 'type' => 'half_yearly', 'period_start' => '2026-10-01', 'period_end' => '2027-03-31', 'status' => 'active']);
    expect(Livewire::test(CalibrationBoard::class)->get('cycle'))->toBe($current->id);
    $this->settings->set('performance.year_start_month', 0, 'Not set');
    expect(Livewire::test(CalibrationBoard::class)->get('cycle'))->not->toBe($current->id);
});

it('schedules one-on-ones with the team, or with anyone when the scope is the organisation', function () {
    $meetings = app(OneOnOnes::class);
    $at = ['scheduled_at' => '2026-09-22 10:00:00', 'status' => 'scheduled'];
    expect(fn () => $meetings->schedule(['employee_id' => $this->peer->id] + $at, $this->employee->user))->toThrow(RuntimeException::class, 'team members only');
    $mine = $meetings->schedule(['employee_id' => $this->employee->id] + $at, $this->manager->user);
    expect($mine->manager_id)->toBe($this->manager->id)->and($mine->initiated_by)->toBe($this->manager->id);

    $this->settings->set('performance.one_on_one.scope', 'organisation', 'Open 1-on-1s');
    $peerMeeting = $meetings->schedule(['employee_id' => $this->peer->id] + $at, $this->employee->user);
    expect($peerMeeting->manager_id)->toBe($this->employee->id)->and($peerMeeting->initiated_by)->toBe($this->employee->id)
        ->and($peerMeeting->initiatorId())->toBe($this->employee->id)->and($peerMeeting->participantId())->toBe($this->peer->id)
        ->and(fn () => $meetings->schedule(['employee_id' => $this->employee->id] + $at, $this->employee->user))->toThrow(RuntimeException::class, 'someone else')
        ->and(fn () => $meetings->schedule(['employee_id' => $this->peer->id, 'manager_id' => $this->manager->id] + $at, $this->employee->user))->toThrow(RuntimeException::class, 'you host');

    // The page schedules through the same rules.
    $this->actingAs($this->peer->user);
    Livewire::test(ManageOneOnOnes::class)->callAction('create', ['employee_id' => $this->manager->id, 'scheduled_at' => '2026-09-23 10:00:00', 'status' => 'scheduled'])->assertHasNoActionErrors();
    $hosted = OneOnOne::query()->where('manager_id', $this->peer->id)->where('employee_id', $this->manager->id)->firstOrFail();
    // The host who scheduled it may open and update it (no other performance rights over the colleague).
    expect($hosted->initiated_by)->toBe($this->peer->id)
        ->and($this->peer->user->can('update', $hosted))->toBeTrue()
        ->and($this->peer->user->can('update', $mine))->toBeFalse()
        ->and($this->peer->user->can('view', Goal::query()->make(['employee_id' => $this->manager->id])))->toBeFalse();
});

it('asks the chosen roles for a feedback card after a held one-on-one, once, through My tasks', function () {
    $meetings = app(OneOnOnes::class);
    $meeting = $meetings->schedule(['employee_id' => $this->employee->id, 'scheduled_at' => '2026-09-18 10:00:00', 'status' => 'scheduled'], $this->manager->user);
    $source = app(OneOnOneFeedbackTaskSource::class);
    $meeting->update(['status' => 'held', 'held_at' => now()]);
    expect($meetings->owesFeedback($meeting, $this->employee))->toBeFalse()
        ->and($source->tasksFor($this->employee->user, $this->employee))->toBeEmpty();

    $this->settings->set('performance.one_on_one.feedback_from', ['participant'], 'Hear the team');
    expect($meetings->owesFeedback($meeting, $this->employee))->toBeTrue()
        ->and($meetings->owesFeedback($meeting, $this->manager))->toBeFalse()
        ->and($source->tasksFor($this->employee->user, $this->employee))->toHaveCount(1)
        ->and($source->tasksFor($this->manager->user, $this->manager))->toBeEmpty()
        ->and(fn () => $meetings->giveFeedback($meeting, $this->employee->user, 6))->toThrow(RuntimeException::class, 'from 1 to 5')
        ->and(fn () => $meetings->giveFeedback($meeting, $this->manager->user, 4))->toThrow(RuntimeException::class, 'no feedback for you');

    $this->actingAs($this->employee->user);
    Livewire::test(ManageOneOnOnes::class)->callTableAction('giveFeedback', $meeting, ['rating' => 4, 'comment' => 'Clear priorities'])->assertNotified('Thank you for your feedback');
    $feedback = $meeting->feedback()->firstOrFail();
    expect($feedback->role)->toBe('participant')->and($feedback->rating)->toBe(4)->and($feedback->acknowledged_at)->not->toBeNull()
        ->and($source->tasksFor($this->employee->user, $this->employee))->toBeEmpty()
        ->and(fn () => $meetings->giveFeedback($meeting, $this->employee->user, 5))->toThrow(RuntimeException::class, 'no feedback for you')
        ->and(fn () => $feedback->update(['rating' => 1]))->toThrow(RuntimeException::class, 'given once');
});

it('prompts goal check-ins on the scheduled days at the scheduled hour, once per person and day', function () {
    $goals = app(Goals::class);
    $goals->create(['employee_id' => $this->employee->id, 'title' => 'Personal goal', 'weight' => 20]);
    $goals->create(['level' => 'company', 'title' => 'Company revenue', 'created_by' => $this->manager->user->id]);
    $reminders = app(PerformanceReminders::class);
    expect($reminders->checkInPrompts(today()))->toBe(['company' => 0, 'team' => 0, 'employee' => 0]);

    $this->settings->set('performance.checkin.employee.interval', 'weekly', 'Mondays');
    $this->settings->set('performance.checkin.company.interval', 'monthly', 'Month');
    $this->settings->set('performance.checkin.company.day', 21, 'The 21st');
    expect($reminders->checkInPrompts(today()))->toBe(['company' => 1, 'team' => 0, 'employee' => 1])
        ->and($reminders->checkInPrompts(today()))->toBe(['company' => 0, 'team' => 0, 'employee' => 0])
        ->and($reminders->checkInPrompts(today()->addDay()))->toBe(['company' => 0, 'team' => 0, 'employee' => 0])
        ->and(collect(perfSetTitles($this->employee->user))->filter(fn ($t) => str_starts_with($t, 'Goal check-in'))->count())->toBe(1)
        ->and(collect(perfSetTitles($this->manager->user))->filter(fn ($t) => str_contains($t, 'company goals (1)'))->count())->toBe(1);

    // The command runs each tenant once a day, once the tenant's clock reaches the scheduled hour.
    $this->travelTo('2026-09-28 09:00:00'); // next Monday
    $hour = Carbon::now($this->tenant->timezone)->hour;
    if ($hour < 23) {
        $this->settings->set('performance.checkin.send_at', $hour + 1, 'Later');
        $this->artisan('peopleos:performance:check-in-prompts', ['--tenant' => $this->tenant->slug])->expectsOutputToContain('before')->assertSuccessful();
    }
    $this->settings->set('performance.checkin.send_at', 0, 'Midnight');
    $this->artisan('peopleos:performance:check-in-prompts', ['--tenant' => $this->tenant->slug])->expectsOutputToContain('employee 1')->assertSuccessful();
    $this->artisan('peopleos:performance:check-in-prompts', ['--tenant' => $this->tenant->slug])->expectsOutputToContain('already run today')->assertSuccessful();
    expect(Goal::query()->count())->toBe(2);
});

it('shows 360 feedback on the profile when turned on, after publication, with anonymous authors hidden', function () {
    $cycle = perfSetFinalOnlyCycle();
    $appraisal = Appraisal::query()->where('performance_cycle_id', $cycle->id)->where('employee_id', $this->employee->id)->firstOrFail();
    $review = $this->appraisals->addPeerReview($appraisal, $this->peer, 'peer', true);
    $this->appraisals->submitReview($review, [], array_fill_keys($cycle->competency_ids, 4), 4, ['strengths' => 'Great partner']);
    $this->appraisals->finalize($appraisal->refresh(), $this->hr, 4.0);

    $page = fn () => Livewire::test(PerformanceRelationManager::class, ['ownerRecord' => $this->employee, 'pageClass' => ViewEmployee::class]);
    $page()->assertTableColumnHidden('peer_rating')->assertTableActionHidden('feedback360', $appraisal);
    $this->settings->set('performance.profile.show_360', true, 'Show 360');
    $page()->assertTableColumnVisible('peer_rating')->assertTableActionVisible('feedback360', $appraisal);

    expect(PerformanceRelationManager::feedback360($appraisal))->toBe(['Peer · '.$this->peer->person->full_name.' · rating 4 — Great partner']);
    $this->actingAs($this->employee->user);
    expect(PerformanceRelationManager::feedback360($appraisal))->toBe(['Peer · Anonymous · rating 4 — Great partner']);
});
