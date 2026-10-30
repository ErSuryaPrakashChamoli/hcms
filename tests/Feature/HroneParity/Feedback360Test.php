<?php

use App\Domain\Configuration\Exceptions\ConfigurationException;
use App\Domain\Configuration\Models\Policy;
use App\Domain\Configuration\Models\PolicyAssignmentRule;
use App\Domain\Configuration\Services\Policies;
use App\Domain\Configuration\Services\SetupCatalogue;
use App\Domain\Employment\Models\EmployeePosition;
use App\Domain\Experience\Services\ApprovalCenter;
use App\Domain\Experience\Services\ApprovalDecisions;
use App\Domain\Feedback\Models\FeedbackTemplate;
use App\Domain\Identity\Models\UserAccessScope;
use App\Domain\Identity\Scopes\AccessScope;
use App\Domain\Offboarding\Support\DataInventory;
use App\Domain\Organisation\Models\Department;
use App\Domain\Performance\Models\Appraisal;
use App\Domain\Performance\Models\FeedbackNomination;
use App\Domain\Performance\Models\FeedbackParticipant;
use App\Domain\Performance\Models\FeedbackResponse;
use App\Domain\Performance\Models\FeedbackRound;
use App\Domain\Performance\Notifications\Feedback360Invitation;
use App\Domain\Performance\Policies\Feedback360Policy;
use App\Domain\Performance\Services\Appraisals;
use App\Domain\Performance\Services\Feedback360Rules;
use App\Domain\Performance\Services\FeedbackNominations;
use App\Domain\Performance\Services\FeedbackRoundResults;
use App\Domain\Performance\Services\FeedbackRounds;
use App\Domain\Performance\Services\Goals;
use App\Domain\Performance\Support\Feedback360TaskSource;
use App\Filament\Resources\FeedbackNominations\FeedbackNominationResource;
use App\Filament\Resources\FeedbackNominations\Pages\ListFeedbackNominations;
use App\Filament\Resources\FeedbackParticipants\FeedbackParticipantResource;
use App\Filament\Resources\FeedbackParticipants\Pages\ListFeedbackParticipants;
use App\Filament\Resources\FeedbackRounds\FeedbackRoundResource;
use App\Filament\Resources\FeedbackRounds\Pages\ListFeedbackRounds;
use App\Filament\Resources\FeedbackRounds\Pages\ViewFeedbackRound;
use App\Filament\Resources\FeedbackRounds\RelationManagers\ParticipantsRelationManager;
use App\Filament\Support\Feedback360Actions;
use App\Filament\Support\PolicySettingsSchema;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Schema;

require_once __DIR__.'/../Workflow/WorkflowTestHelpers.php';
require_once __DIR__.'/../Performance/PerformanceTestHelpers.php';

/* HROne setup parity HP-8h (SV-12, PF-1 … PF-5) — 360 feedback policy and rounds: frequency and window, sources
 * (manager, functional manager, peers and subordinates by criteria, clients by signed link), anonymity per source with a
 * minimum number of answers, per-source feedback templates, nominations (who nominates, from which pool, approval in the
 * Approval Center), extend / close, the export, the optional blend into the appraisal rating, tenant isolation. */

beforeEach(function () {
    $this->travelTo('2026-10-05 09:00:00');
    $this->tenant = provisionTenant();
    actAsTenant($this->tenant);
    $this->hr = tenantUser($this->tenant, ['*']);
    $this->actingAs($this->hr);
    $this->manager = activeEmployee(null, ['performance.team', 'performance.review', 'performance.goals', 'task.view']);
    $this->employee = activeEmployee($this->manager);
    $this->p1 = activeEmployee($this->manager);
    $this->p2 = activeEmployee($this->manager);
    $this->p3 = activeEmployee($this->manager);
    $this->outsider = activeEmployee();
    $this->template = FeedbackTemplate::create(['code' => 'F360', 'name' => '360 form', 'process' => 'feedback_360', 'questions' => [
        ['key' => 'teamwork', 'title' => 'Teamwork', 'type' => 'rating', 'scale_max' => 5, 'mandatory' => true],
        ['key' => 'comments', 'title' => 'Comments', 'type' => 'text', 'mandatory' => false],
    ]]);
    $this->rounds = app(FeedbackRounds::class);
    $this->nominations = app(FeedbackNominations::class);
    $this->results = app(FeedbackRoundResults::class);
});

/** A published 360 policy for everyone (or the named employees). */
function f360Policy(array $settings, ?array $employeeIds = null, string $code = 'P360'): Policy
{
    $policy = Policy::create(['type' => 'feedback_360', 'name' => '360 policy', 'code' => $code]);
    app(Policies::class)->draft($policy, $settings);
    app(Policies::class)->publish($policy, '2020-01-01');
    PolicyAssignmentRule::create(['policy_type' => 'feedback_360', 'policy_id' => $policy->id, 'name' => 'Who', 'conditions' => $employeeIds === null ? [] : [['field' => 'employee_id', 'operator' => 'in', 'value' => $employeeIds]]]);

    return $policy;
}

/** Quarterly, manager + peers (+ more), the test template for every source, no approval. */
function f360Settings(array $extra = []): array
{
    $template = test()->template->id;

    return array_merge(['frequency' => 'quarterly', 'window_days' => 20, 'manager_feedback' => '1', 'manager_template_id' => $template,
        'peer_feedback' => '1', 'peer_template_id' => $template, 'client_template_id' => $template, 'subordinate_template_id' => $template], $extra);
}

function f360Participant(FeedbackRound $round, $employee): FeedbackParticipant
{
    return FeedbackParticipant::query()->withoutGlobalScope(AccessScope::class)->where('feedback_round_id', $round->id)->where('employee_id', $employee->id)->sole();
}

function f360Nomination(FeedbackParticipant $participant, $reviewer): FeedbackNomination
{
    return FeedbackNomination::query()->withoutGlobalScope(AccessScope::class)->where('feedback_participant_id', $participant->id)->where('reviewer_employee_id', $reviewer->id)->sole();
}

/** Launch, nominate the given peers and submit (no approval: the reviewers are asked at once). */
function f360Ready(array $settings, array $peers): array
{
    $test = test();
    $policy = f360Policy($settings);
    $round = $test->rounds->launch($policy, [], $test->hr);
    $participant = f360Participant($round, $test->employee);
    $test->nominations->nominate($participant, ['peer' => array_map(fn ($p) => $p->id, $peers)], [], $test->employee->user);
    $test->nominations->submit($participant, $test->employee->user);

    return [$round->refresh(), $participant->refresh()];
}

it('validates the 360 policy and gives it HROne\'s sections as wizard steps, listed in Setup → Performance', function () {
    $rules = app(Feedback360Rules::class);
    $other = FeedbackTemplate::create(['code' => 'GOALFB', 'name' => 'Goal sheet', 'process' => 'goal_review', 'questions' => [['title' => 'Fine?', 'type' => 'yes_no']]]);
    $template = $this->template->id;

    expect(fn () => $rules->validatePolicySettings([]))->toThrow(ConfigurationException::class, 'at least one source')
        ->and(fn () => $rules->validatePolicySettings(['peer_feedback' => '1']))->toThrow(ConfigurationException::class, 'Choose the feedback template for peer feedback')
        ->and(fn () => $rules->validatePolicySettings(['peer_feedback' => '1', 'peer_template_id' => $other->id]))->toThrow(ConfigurationException::class, 'active 360 feedback template')
        ->and(fn () => $rules->validatePolicySettings(['peer_feedback' => '1', 'peer_template_id' => $template, 'peer_match' => ['department_id', 'grade_id', 'level_id', 'location_id']]))->toThrow(ConfigurationException::class, 'at most three')
        ->and(fn () => $rules->validatePolicySettings(['peer_feedback' => '1', 'peer_template_id' => $template, 'nominator' => 'role']))->toThrow(ConfigurationException::class, 'Choose the role')
        ->and(fn () => $rules->validatePolicySettings(['peer_feedback' => '1', 'peer_template_id' => $template, 'peer_max' => 2, 'min_nominations' => 5]))->toThrow(ConfigurationException::class, 'more than the maximums allow')
        ->and(fn () => $rules->validatePolicySettings(['manager_feedback' => '1', 'manager_template_id' => $template, 'include_in_appraisal' => '1']))->toThrow(ConfigurationException::class, 'weight of the 360 rating');
    $rules->validatePolicySettings(f360Settings(['peer_match' => ['department_id', 'grade_id'], 'peer_max' => 4, 'min_nominations' => 2, 'include_in_appraisal' => '1', 'appraisal_weight' => 20]));

    // Publishing runs the validator too.
    $policy = Policy::create(['type' => 'feedback_360', 'name' => 'Broken', 'code' => 'BROKEN']);
    app(Policies::class)->draft($policy, ['peer_feedback' => '1']);
    expect(fn () => app(Policies::class)->publish($policy, '2020-01-01'))->toThrow(ConfigurationException::class);

    expect(array_column(PolicySettingsSchema::steps('feedback_360'), 'label'))->toBe(['Frequency & duration', 'Manager feedback', 'Peer feedback', 'Subordinate feedback', 'Client feedback', 'Nomination settings', 'Results and privacy'])
        ->and(config('peopleos_setup.modules.performance.policy_types'))->toContain('feedback_360', 'performance')
        ->and(config('peopleos.feedback.processes'))->toHaveKeys(['feedback_360', 'performance_review'])
        ->and(DataInventory::categoryOf('feedback_responses'))->toBe('performance')
        ->and(DataInventory::categoryOf('feedback_nominations'))->toBe('performance')
        ->and(app(SetupCatalogue::class))->not->toBeNull();
});

it('launches a round for the policy\'s population, asks the manager and lets the employee nominate', function () {
    $policy = f360Policy(f360Settings(), [$this->employee->id, $this->p1->id]);
    expect(fn () => $this->rounds->launch($policy, [], $this->manager->user))->toThrow(RuntimeException::class, 'cannot launch');

    $round = $this->rounds->launch($policy, [], $this->hr);
    $participant = f360Participant($round, $this->employee);
    $manager = f360Nomination($participant, $this->manager);

    expect($round->participants()->withoutGlobalScope(AccessScope::class)->pluck('employee_id')->sort()->values()->all())->toBe(collect([$this->employee->id, $this->p1->id])->sort()->values()->all())
        // Quarterly from January (no performance year set): Q4; the window opens on the first day for 20 days.
        ->and([$round->period_start->toDateString(), $round->period_end->toDateString(), $round->window_opens_on->toDateString(), $round->window_closes_on->toDateString()])->toBe(['2026-10-01', '2026-12-31', '2026-10-01', '2026-10-20'])
        ->and($round->templates['peer']['questions'])->toBe($this->template->questions)
        ->and($manager->relation)->toBe('manager')->and($manager->status)->toBe('requested')->and($manager->is_anonymous)->toBeFalse()
        ->and($participant->nomination_status)->toBe('draft')->and($participant->nominator_user_id)->toBe($this->employee->user_id)
        // Without criteria anyone may be a peer, except whom the system already asks (the manager).
        ->and($this->nominations->candidates($participant, 'peer')->pluck('id'))->not->toContain($this->manager->id)->not->toContain($this->employee->id)->toContain($this->outsider->id)
        ->and($this->employee->user->notifications()->get()->pluck('data.title')->filter(fn ($t) => str_contains((string) $t, 'Nominate reviewers'))->count())->toBe(1)
        ->and($this->manager->user->notifications()->get()->pluck('data.title')->filter(fn ($t) => str_contains((string) $t, 'Your 360 feedback is asked'))->count())->toBe(2);

    $tasks = app(Feedback360TaskSource::class)->tasksFor($this->manager->user, $this->manager)->pluck('kind')->countBy()->all();
    expect($tasks)->toBe(['feedback_360' => 2])
        ->and(app(Feedback360TaskSource::class)->tasksFor($this->employee->user, $this->employee)->pluck('kind')->all())->toBe(['feedback_360_nomination'])
        // An employee already in an open round of the policy for the period is not included twice.
        ->and(fn () => $this->rounds->launch($policy, [], $this->hr))->toThrow(RuntimeException::class, 'Nobody to include');
});

it('takes peers who share the policy\'s criteria, within the maximums, and waits for the manager\'s approval', function () {
    $sales = Department::factory()->create(['name' => 'Sales']);
    EmployeePosition::query()->whereIn('employee_id', [$this->employee->id, $this->p1->id, $this->p2->id])->update(['department_id' => $sales->id]);
    $policy = f360Policy(f360Settings(['peer_match' => ['department_id'], 'peer_max' => 2, 'client_feedback' => '1', 'client_max' => 1, 'nomination_approval' => 'manager']));
    $round = $this->rounds->launch($policy, ['employee_ids' => [$this->employee->id]], $this->hr);
    $participant = f360Participant($round, $this->employee);
    $user = $this->employee->user;

    expect($this->nominations->candidates($participant, 'peer')->pluck('id')->sort()->values()->all())->toBe(collect([$this->p1->id, $this->p2->id])->sort()->values()->all())
        ->and(fn () => $this->nominations->nominate($participant, ['peer' => [$this->p3->id]], [], $user))->toThrow(RuntimeException::class, 'does not meet the policy\'s criteria')
        ->and(fn () => $this->nominations->nominate($participant, [], [['name' => 'A', 'email' => 'a@client.test'], ['name' => 'B', 'email' => 'b@client.test']], $user))->toThrow(RuntimeException::class, 'at most 1 client')
        ->and(fn () => $this->nominations->nominate($participant, [], [['name' => 'A', 'email' => 'not-an-email']], $user))->toThrow(RuntimeException::class, 'valid e-mail')
        ->and(fn () => $this->nominations->nominate($participant, ['subordinate' => [$this->p1->id]], [], $user))->toThrow(RuntimeException::class, 'does not collect Subordinate feedback')
        ->and(fn () => $this->nominations->nominate($participant, ['peer' => [$this->p1->id]], [], $this->p3->user))->toThrow(RuntimeException::class, 'You do not nominate');

    $this->nominations->nominate($participant, ['peer' => [$this->p1->id, $this->p2->id]], [['name' => 'Asha Buyer', 'email' => 'Buyer@Client.test']], $user);
    $this->nominations->submit($participant, $user);
    $id = 'feedback_360_nomination:'.$participant->id;
    expect($participant->refresh()->nomination_status)->toBe('submitted')
        ->and(app(ApprovalCenter::class)->pending($this->manager->user)->pluck('id'))->toContain($id)
        ->and(app(ApprovalCenter::class)->pending($user)->pluck('id'))->not->toContain($id)
        ->and(app(ApprovalCenter::class)->pending($this->p1->user)->pluck('id'))->not->toContain($id)
        ->and(f360Nomination($participant, $this->p1)->status)->toBe('draft'); // nobody is asked before the approval

    $this->nominations->sendBack($participant, 'Add someone from another team', $this->manager->user);
    expect($participant->refresh()->nomination_status)->toBe('returned')
        ->and($user->notifications()->get()->pluck('data.title')->filter(fn ($t) => str_contains((string) $t, 'were sent back'))->count())->toBe(1);

    $this->nominations->nominate($participant, ['peer' => [$this->p1->id]], [['name' => 'Asha Buyer', 'email' => 'buyer@client.test']], $user);
    $this->nominations->submit($participant, $user);
    app(ApprovalDecisions::class)->decide($this->manager->user, $id, 'approve', 'Fine');

    $client = FeedbackNomination::query()->withoutGlobalScope(AccessScope::class)->where('feedback_participant_id', $participant->id)->where('relation', 'client')->sole();
    expect($participant->refresh()->nomination_status)->toBe('approved')->and($participant->decided_by)->toBe($this->manager->user_id)
        ->and(f360Nomination($participant, $this->p1)->status)->toBe('requested')
        ->and(f360Nomination($participant, $this->p2)->status)->toBe('cancelled')
        ->and($client->status)->toBe('requested')->and($client->external_email)->toBe('buyer@client.test')->and(strlen((string) $client->token_hash))->toBe(64)
        ->and($client->toArray())->not->toHaveKey('token_hash');
});

it('keeps anonymous peer answers unlinked and aggregated, shown only once enough people answered and to the employee after the round closes', function () {
    [$round, $participant] = f360Ready(f360Settings(), [$this->p1, $this->p2, $this->p3]);
    $respond = fn ($reviewer, int $rating, string $comment) => $this->nominations->respond(f360Nomination($participant, $reviewer), ['teamwork' => $rating, 'comments' => $comment], $reviewer->user);

    $respond($this->p1, 4, 'Great partner');
    $respond($this->p2, 2, 'Needs focus');
    $respond($this->manager, 5, 'Solid quarter');

    expect(FeedbackResponse::query()->withoutGlobalScope(AccessScope::class)->where('relation', 'peer')->whereNotNull('feedback_nomination_id')->count())->toBe(0)
        ->and(FeedbackResponse::query()->withoutGlobalScope(AccessScope::class)->where('relation', 'manager')->sole()->feedback_nomination_id)->toBe(f360Nomination($participant, $this->manager)->id)
        ->and(Schema::hasColumn('feedback_responses', 'created_at'))->toBeFalse()
        ->and(fn () => $respond($this->p1, 3, 'Again'))->toThrow(RuntimeException::class, 'already given')
        ->and(fn () => $this->nominations->respond(f360Nomination($participant, $this->p3), ['teamwork' => 3], $this->p1->user))->toThrow(RuntimeException::class, 'not asked of you')
        ->and(fn () => $this->nominations->respond(f360Nomination($participant, $this->p3), ['comments' => 'x'], $this->p3->user))->toThrow(RuntimeException::class, 'Answer "Teamwork"');

    // Two peer answers: below the minimum of three, the peers are not shown; the named manager is.
    $hr = collect($this->results->results($participant, $this->hr))->keyBy('relation');
    expect($hr['peer']['shown'])->toBeFalse()->and($hr['peer']['note'])->toContain('fewer than 3')->and($hr['peer']['questions'])->toBe([])
        ->and($hr['manager']['named'][0]['reviewer'])->toBe($this->manager->person->full_name)
        ->and($this->results->canSee($this->employee->user, $participant))->toBeFalse()
        ->and($this->results->canSee($this->manager->user, $participant))->toBeFalse();

    $respond($this->p3, 3, 'Good');
    $peer = collect($this->results->results($participant, $this->hr))->firstWhere('relation', 'peer');
    $comments = collect($peer['questions'])->firstWhere('title', 'Comments')['texts'];
    expect($peer['shown'])->toBeTrue()->and($peer['score'])->toBe(50.0)->and($peer['named'])->toBe([])
        ->and($comments)->toBe(['Good', 'Great partner', 'Needs focus'])
        ->and(collect($peer['questions'])->firstWhere('title', 'Teamwork')['average'])->toBe(3.0);
    foreach ([$this->p1, $this->p2, $this->p3] as $peerEmployee) {
        expect(json_encode($peer))->not->toContain($peerEmployee->person->full_name);
    }

    $this->rounds->close($round, $this->hr);
    $participant->refresh();
    expect($this->results->canSee($this->employee->user, $participant))->toBeTrue()
        ->and($this->results->canSee($this->manager->user, $participant))->toBeTrue()
        ->and($this->results->canSee($this->p1->user, $participant))->toBeFalse()
        ->and($this->employee->user->notifications()->get()->pluck('data.title')->filter(fn ($t) => str_contains((string) $t, 'is ready to read'))->count())->toBe(1);

    // The export: aggregated rows, never answers or text.
    $rows = collect($this->results->exportRows($round, $this->hr));
    $peerRow = $rows->where('employee_code', $this->employee->employee_code)->firstWhere('source', 'Peer');
    expect($peerRow['average_score_pct'])->toBe(50.0)->and($peerRow['answered'])->toBe(3)->and($peerRow['anonymous'])->toBe('yes')
        ->and(json_encode($rows))->not->toContain('Great partner')->not->toContain('Solid quarter');

    // A policy that says "never": the employee does not see their results.
    $round->update(['settings' => [...$round->settings, 'employee_sees' => 'never']]);
    expect($this->results->canSee($this->employee->user, $participant->refresh()))->toBeFalse();
});

it('takes answers only inside the window, extends it with a reason and closes rounds whose window has passed', function () {
    [$round, $participant] = f360Ready(f360Settings(['window_days' => 10]), [$this->p1]);
    $nomination = f360Nomination($participant, $this->p1);

    $this->travelTo('2026-10-11 09:00:00');
    expect(fn () => $this->nominations->respond($nomination, ['teamwork' => 4], $this->p1->user))->toThrow(RuntimeException::class, 'window is closed')
        ->and(fn () => $this->rounds->extend($round, '2026-10-20', '', $this->hr))->toThrow(RuntimeException::class, 'reason')
        ->and(fn () => $this->rounds->extend($round, '2026-10-20', 'More time', $this->manager->user))->toThrow(RuntimeException::class, 'cannot extend')
        ->and(fn () => $this->rounds->extend($round, '2026-10-09', 'More time', $this->hr))->toThrow(RuntimeException::class, 'Choose a closing date after');

    $round = $this->rounds->extend($round, '2026-10-20', 'Holidays', $this->hr);
    expect($round->window_closes_on->toDateString())->toBe('2026-10-20')->and($round->extensions)->toBe(1);
    $this->nominations->respond($nomination, ['teamwork' => 4], $this->p1->user);
    expect($nomination->refresh()->status)->toBe('submitted')->and($nomination->responded_on->toDateString())->toBe('2026-10-11');

    $this->travelTo('2026-10-21 09:00:00');
    expect($this->rounds->runSchedule())->toBe(['closed' => 1, 'launched' => 0])
        ->and($round->refresh()->status)->toBe('closed')
        ->and(fn () => $this->rounds->close($round, $this->hr))->toThrow(RuntimeException::class, 'not open');
});

it('launches the round of the period automatically once its window opens, once', function () {
    f360Policy(f360Settings(['auto_launch' => '1', 'window_start_day' => 10]));

    $this->travelTo('2026-10-08 09:00:00');
    expect($this->rounds->runSchedule())->toBe(['closed' => 0, 'launched' => 0]);
    $this->travelTo('2026-10-10 09:00:00');
    expect($this->rounds->runSchedule())->toBe(['closed' => 0, 'launched' => 1])
        ->and($this->rounds->runSchedule())->toBe(['closed' => 0, 'launched' => 0]);
    $round = FeedbackRound::query()->sole();
    expect($round->auto)->toBeTrue()->and($round->launched_by)->toBeNull()->and($round->window_opens_on->toDateString())->toBe('2026-10-10');
});

it('asks clients through a signed link that expires with the window, works once and keeps only their name and e-mail', function () {
    Notification::fake();
    $policy = f360Policy(f360Settings(['client_feedback' => '1', 'client_max' => 2]));
    $round = $this->rounds->launch($policy, ['employee_ids' => [$this->employee->id]], $this->hr);
    $participant = f360Participant($round, $this->employee);
    $this->nominations->nominate($participant, [], [['name' => 'Asha Buyer', 'email' => 'asha@client.test'], ['name' => 'Ravi Vendor', 'email' => 'ravi@client.test']], $this->employee->user);
    $this->nominations->submit($participant, $this->employee->user);
    $urls = [];
    Notification::assertSentOnDemand(Feedback360Invitation::class, function ($n, $channels, $notifiable) use (&$urls) {
        $urls[$notifiable->routes['mail']] = $n->url;

        return true;
    });
    $url = $urls['asha@client.test'];

    $this->get($url)->assertOk()->assertSee('Feedback about '.$this->employee->person->full_name)->assertSee('Teamwork')
        ->assertHeader('X-Robots-Tag', 'noindex, nofollow')->assertHeader('Referrer-Policy', 'no-referrer');
    // The signature covers the tenant and the token.
    $this->get(str_replace('/360/'.$this->tenant->id.'/', '/360/'.($this->tenant->id + 1).'/', $url))->assertForbidden();
    $this->get(preg_replace('~/360/(\d+)/[A-Za-z0-9]+~', '/360/$1/'.str_repeat('a', 64), $url))->assertForbidden();

    $this->post($url, ['answers' => ['comments' => 'No rating']])->assertStatus(422)->assertSee('Answer &quot;Teamwork&quot;', false);
    $this->post($url, ['answers' => ['teamwork' => 5, 'comments' => 'Reliable']])->assertOk()->assertSee('Thank you');
    $asha = FeedbackNomination::query()->withoutGlobalScope(AccessScope::class)->where('external_email', 'asha@client.test')->sole();
    expect($asha->status)->toBe('submitted')->and($asha->token_hash)->toBeNull()
        ->and(FeedbackResponse::query()->withoutGlobalScope(AccessScope::class)->where('relation', 'client')->sole()->feedback_nomination_id)->toBe($asha->id)
        ->and(array_values(array_intersect(Schema::getColumnListing('feedback_nominations'), ['external_name', 'external_email', 'external_phone', 'external_designation'])))->toBe(['external_name', 'external_email']);
    Notification::assertSentOnDemand(Feedback360Invitation::class, fn ($n, $channels, $notifiable) => $notifiable->routes['mail'] === 'asha@client.test' && $n->about === $this->employee->person->full_name && $channels === ['mail']);
    $this->get($url)->assertStatus(410)->assertSee('not available');

    // Ravi's link stops working when the window has closed.
    $this->travelTo('2026-10-21 00:30:00');
    $this->get($urls['ravi@client.test'])->assertStatus(410);
});

it('blends the 360 rating into the appraisal only when the round\'s policy asks for it', function () {
    $cycle = app(Appraisals::class)->launch(draftCycle(['competency_ids' => [], 'stages' => [
        ['key' => 'manager_review', 'name' => 'Manager review', 'starts_on' => '2026-09-01', 'ends_on' => '2026-12-31'],
        ['key' => 'final', 'name' => 'Final rating', 'starts_on' => '2026-09-01', 'ends_on' => '2026-12-31'],
    ]]));
    $goal = app(Goals::class)->create(['employee_id' => $this->employee->id, 'performance_cycle_id' => $cycle->id, 'title' => 'Revenue', 'weight' => 100, 'target_value' => 100]);
    $appraisal = Appraisal::query()->where('performance_cycle_id', $cycle->id)->where('employee_id', $this->employee->id)->sole();
    app(Appraisals::class)->submitReview($appraisal->review('manager'), [$goal->id => 4], [], 4);
    expect((float) $appraisal->refresh()->computed_rating)->toBe(4.0);

    $policy = f360Policy(f360Settings());
    $round = $this->rounds->launch($policy, ['employee_ids' => [$this->employee->id], 'performance_cycle_id' => $cycle->id], $this->hr);
    $participant = f360Participant($round, $this->employee);
    $this->nominations->nominate($participant, ['peer' => [$this->p1->id, $this->p2->id, $this->p3->id]], [], $this->employee->user);
    $this->nominations->submit($participant, $this->employee->user);
    foreach ([$this->p1, $this->p2, $this->p3] as $peer) {
        $this->nominations->respond(f360Nomination($participant, $peer), ['teamwork' => 5], $peer->user);
    }
    $this->rounds->close($round, $this->hr);

    // Default: no blend — the computed rating is the manager review's.
    expect((float) $appraisal->refresh()->computed_rating)->toBe(4.0)->and($appraisal->peer_rating)->toBeNull();

    $round->update(['settings' => [...$round->settings, 'include_in_appraisal' => '1', 'appraisal_weight' => 20]]);
    app(Appraisals::class)->score($appraisal->refresh());
    // 4 × 80 % + 5 × 20 % (the peers' 100 % on the 1–5 scale).
    expect((float) $appraisal->refresh()->computed_rating)->toBe(4.2)->and((float) $appraisal->peer_rating)->toBe(5.0);

    // Fewer answers than the minimum: no blend (an anonymous answer would show through the rating).
    $round->update(['settings' => [...$round->settings, 'min_responses' => 4]]);
    app(Appraisals::class)->score($appraisal->refresh());
    expect((float) $appraisal->refresh()->computed_rating)->toBe(4.0);
});

it('keeps rounds, nominations and answers inside their tenant and fails closed on access', function () {
    [$round, $participant] = f360Ready(f360Settings(), [$this->p1]);
    $this->nominations->respond(f360Nomination($participant, $this->p1), ['teamwork' => 4], $this->p1->user);
    $policy = new Feedback360Policy;

    // Access: only HR runs rounds; only reviewers answer; results only for those allowed; HR outside the scope sees nothing.
    $scoped = tenantUser($this->tenant, ['performance.feedback_360']);
    UserAccessScope::query()->create(['user_id' => $scoped->id, 'dimension' => 'department', 'scope_id' => Department::factory()->create()->id]);
    expect(fn () => $this->rounds->close($round, $this->employee->user))->toThrow(RuntimeException::class, 'cannot close')
        ->and(fn () => $this->rounds->cancel($round, 'No', $this->manager->user))->toThrow(RuntimeException::class, 'cannot cancel')
        ->and($this->results->canSee($scoped, $participant))->toBeFalse()
        ->and($this->results->exportRows($round, $scoped))->toBe([])
        ->and(fn () => $this->results->results($participant, $this->p1->user))->toThrow(RuntimeException::class, 'cannot see')
        ->and(fn () => $this->results->exportRows($round, $this->employee->user))->toThrow(RuntimeException::class, 'cannot export')
        ->and($policy->view($this->p2->user, f360Nomination($participant, $this->p1)))->toBeFalse()
        ->and($policy->view($this->p1->user, f360Nomination($participant, $this->p1)))->toBeTrue()
        ->and($policy->view($this->employee->user, $round))->toBeFalse()
        ->and($policy->update($this->hr, $round))->toBeFalse()->and($policy->delete($this->hr, $round))->toBeFalse()
        ->and(fn () => $round->delete())->toThrow(RuntimeException::class, 'never deleted');

    $this->actingAs($this->employee->user);
    expect(FeedbackRoundResource::canViewAny())->toBeFalse()
        ->and(FeedbackNominationResource::getEloquentQuery()->count())->toBe(0);
    $this->actingAs($this->p1->user);
    expect(FeedbackNominationResource::getEloquentQuery()->pluck('id')->all())->toBe([f360Nomination($participant, $this->p1)->id]);

    // Another tenant sees none of it.
    $beta = provisionTenant('Beta');
    actAsTenant($beta);
    expect(FeedbackRound::query()->count())->toBe(0)
        ->and(FeedbackParticipant::query()->withoutGlobalScope(AccessScope::class)->count())->toBe(0)
        ->and(FeedbackNomination::query()->withoutGlobalScope(AccessScope::class)->count())->toBe(0)
        ->and(FeedbackResponse::query()->withoutGlobalScope(AccessScope::class)->count())->toBe(0);
});

it('runs 360 feedback from the screens: launch, nominate, approve, give feedback, results and export', function () {
    $policy = f360Policy(f360Settings(['nomination_approval' => 'manager']), [$this->employee->id]);

    Livewire\Livewire::test(ListFeedbackRounds::class)->assertOk()
        ->callAction('launchRound', data: ['policy_id' => $policy->id, 'name' => 'Q4 360', 'day' => '2026-10-05'])->assertNotified('360 feedback launched for 1 employee(s)');
    $round = FeedbackRound::query()->sole();
    $participant = f360Participant($round, $this->employee);
    $this->get(FeedbackRoundResource::getUrl('index'))->assertOk()->assertSee('Q4 360');
    $this->get(FeedbackRoundResource::getUrl('view', ['record' => $round]))->assertOk()->assertSee('Q4 360')->assertSee('360 form');
    Livewire\Livewire::test(ParticipantsRelationManager::class, ['ownerRecord' => $round, 'pageClass' => ViewFeedbackRound::class])
        ->assertOk()->assertCanSeeTableRecords([$participant]);

    $this->actingAs($this->employee->user);
    $this->get(FeedbackParticipantResource::getUrl('index'))->assertOk();
    Livewire\Livewire::test(ListFeedbackParticipants::class, ['activeTab' => 'nominate'])->assertCanSeeTableRecords([$participant])
        ->callTableAction('nominate', $participant, ['peer' => [$this->p1->id]])->assertNotified('Nominations saved')
        ->callTableAction('submitNominations', $participant)->assertNotified('Nominations submitted');

    $this->actingAs($this->manager->user);
    Livewire\Livewire::test(ListFeedbackParticipants::class, ['activeTab' => 'approve'])->assertCanSeeTableRecords([$participant])
        ->callTableAction('approve', $participant, ['note' => 'Fine'])->assertNotified('Nominations approved');
    $nomination = f360Nomination($participant, $this->manager);
    $this->get(FeedbackNominationResource::getUrl('index'))->assertOk();
    Livewire\Livewire::test(ListFeedbackNominations::class)->assertCanSeeTableRecords([$nomination])
        ->callTableAction('give', $nomination, ['answers' => ['teamwork' => 4, 'comments' => 'Clear priorities']])->assertNotified('Feedback given');
    expect($nomination->refresh()->status)->toBe('submitted');

    $this->actingAs($this->p1->user);
    $peer = f360Nomination($participant, $this->p1);
    Livewire\Livewire::test(ListFeedbackNominations::class)->assertCanSeeTableRecords([$peer])->assertCanNotSeeTableRecords([$nomination])
        ->callTableAction('decline', $peer)->assertNotified('Noted: you are not taking part');
    expect($peer->refresh()->status)->toBe('declined');

    $this->actingAs($this->hr);
    Livewire\Livewire::test(ViewFeedbackRound::class, ['record' => $round->id])
        ->callAction('extend', data: ['closes_on' => '2026-10-25', 'reason' => 'Holidays'])->assertNotified('Window extended')
        ->callAction('close')->assertNotified('Round closed');
    Livewire\Livewire::test(ListFeedbackRounds::class)->callTableAction('export', $round)->assertFileDownloaded('feedback-360-'.$round->id.'.csv');

    $this->actingAs($this->employee->user);
    Livewire\Livewire::test(ListFeedbackParticipants::class)->assertCanSeeTableRecords([$participant->refresh()])
        ->assertTableActionVisible('results', $participant)->assertTableActionHidden('nominate', $participant);
    expect(Feedback360Actions::resultLines($this->results->results($participant, $this->employee->user)))
        ->toContain('Manager · 1 of 1 answered · average 75%');
});
