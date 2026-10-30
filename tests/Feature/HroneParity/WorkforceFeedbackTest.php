<?php

use App\Domain\Configuration\Models\Policy;
use App\Domain\Configuration\Models\PolicyAssignmentRule;
use App\Domain\Configuration\Services\Policies;
use App\Domain\Exit\Services\ExitInterviews;
use App\Domain\Exit\Services\Exits;
use App\Domain\Experience\Services\ExperienceTasks;
use App\Domain\Feedback\Models\FeedbackRequest;
use App\Domain\Feedback\Models\FeedbackTemplate;
use App\Domain\Feedback\Services\FeedbackRequests;
use App\Domain\Feedback\Services\FeedbackTemplates;
use App\Domain\Identity\Models\Role;
use App\Domain\Lifecycle\Enums\LifecycleState;
use App\Domain\Lifecycle\Models\ConfirmationReview;
use App\Domain\Lifecycle\Services\ConfirmationReviews;
use App\Filament\Resources\FeedbackRequests\Pages\ListFeedbackRequests;
use Livewire\Livewire;

require_once __DIR__.'/../Workflow/WorkflowTestHelpers.php';

/* HROne setup parity P13 / 08.3 #10–#12 / 08.1 #11–#12: exit interviews by the employee, the manager and HR, confirmation feedback and probation self-review on feedback templates. */

beforeEach(function () {
    $this->travelTo('2026-09-21 10:00:00');
    $this->tenant = provisionTenant();
    actAsTenant($this->tenant);
    $this->hr = tenantUser($this->tenant, ['*']);
    $this->actingAs($this->hr);
    app(FeedbackTemplates::class)->addFromLibrary(array_keys(FeedbackTemplates::library()), $this->hr);
    $this->feedback = app(FeedbackRequests::class);
});

function wfbPolicy(string $type, array $settings): void
{
    $policy = Policy::create(['type' => $type, 'name' => $type, 'code' => strtoupper($type).'_FB']);
    app(Policies::class)->draft($policy, $settings);
    app(Policies::class)->publish($policy, '2020-01-01');
    PolicyAssignmentRule::create(['policy_type' => $type, 'policy_id' => $policy->id, 'name' => 'All', 'conditions' => []]);
}

function wfbTemplate(string $code): int
{
    return (int) FeedbackTemplate::query()->where('code', $code)->value('id');
}

/** @return list<string> the kinds of the user's My tasks items from feedback */
function wfbTaskKinds($user, $employee = null): array
{
    return app(ExperienceTasks::class)->for($user, $employee)->where('domain', 'feedback')->pluck('kind')->values()->all();
}

it('keeps today\'s exits and confirmations when the policies name no feedback template', function () {
    wfbPolicy('exit', ['notice_period_days' => 30]);
    wfbPolicy('confirmation', ['review_lead_days' => 10]);
    $employee = employeeWithUser();
    forceLifecycle($employee, LifecycleState::Active, ['joining_date' => '2024-01-01']);
    $case = app(Exits::class)->initiate($employee, 'resignation', 'Moving on');
    $probationer = employeeWithUser();
    forceLifecycle($probationer, LifecycleState::Probation, ['joining_date' => '2026-03-25', 'probation_end_date' => '2026-09-25']);
    app(ConfirmationReviews::class)->openDue();
    $review = ConfirmationReview::query()->where('employee_id', $probationer->id)->firstOrFail();

    expect(FeedbackRequest::query()->count())->toBe(0)
        ->and($this->feedback->summary($case))->toBeNull()
        ->and(wfbTaskKinds($employee->user, $employee))->toBe([]);

    // The standard exit interview and an undelayed decision stay as they were.
    app(ExitInterviews::class)->submit($case, ['reason_for_leaving' => array_key_first(config('peopleos.exit.interview_reasons')), 'ratings' => []], $employee->user, true);
    app(ConfirmationReviews::class)->confirm($review, null, $this->hr);
    expect($case->interview()->value('source'))->toBe('employee')->and($review->refresh()->status)->toBe('confirmed');
});

it('asks the exit interviews of the employee, the manager and an HR role on their templates, as My tasks, read only by the right people', function () {
    $hrRole = Role::query()->where('slug', 'hr-manager')->firstOrFail();
    wfbPolicy('exit', ['notice_period_days' => 30, 'interview_employee_template_id' => wfbTemplate('EXIT_EMPLOYEE'), 'interview_manager' => true, 'interview_manager_template_id' => wfbTemplate('EXIT_MANAGER'),
        'interview_hr' => true, 'interview_hr_template_id' => wfbTemplate('EXIT_HR'), 'interview_hr_role' => 'hr-manager']);
    $manager = employeeWithUser();
    $employee = employeeWithUser($manager);
    forceLifecycle($employee, LifecycleState::Active, ['joining_date' => '2024-01-01']);
    $interviewer = tenantUser($this->tenant);
    $interviewer->roles()->attach($hrRole);
    $outsider = tenantUser($this->tenant, ['employee.view']);

    $case = app(Exits::class)->initiate($employee, 'resignation', 'Higher studies');
    $requests = FeedbackRequest::query()->where('subject_id', $case->id)->get()->keyBy('process');
    [$own, $byManager, $byHr] = [$requests['exit_interview'], $requests['exit_interview_manager'], $requests['exit_interview_hr']];

    expect($requests)->toHaveCount(3)
        ->and($own->respondent_user_id)->toBe($employee->user_id)->and($byManager->respondent_user_id)->toBe($manager->user_id)
        ->and($byHr->respondent_user_id)->toBeNull()->and($byHr->respondent_role_id)->toBe($hrRole->id)
        ->and($byManager->due_on->toDateString())->toBe($case->last_working_day->toDateString())
        ->and($byManager->questions)->toBe(FeedbackTemplate::query()->where('code', 'EXIT_MANAGER')->first()->questions)
        ->and(wfbTaskKinds($manager->user, $manager))->toBe(['exit_interview_manager'])
        ->and(wfbTaskKinds($interviewer))->toBe(['exit_interview_hr'])
        ->and(wfbTaskKinds($employee->user, $employee))->toBe(['exit_interview'])
        ->and($this->feedback->summary($case))->toContain('Exit interview by the manager: pending (due');

    // Answered once, by the person (or role) asked, on the questions it was asked with.
    $reasons = $byManager->questions[0];
    expect(fn () => $this->feedback->submit($byManager, [], $employee->user))->toThrow(RuntimeException::class, 'not asked of you')
        ->and(fn () => $this->feedback->submit($byManager, ['q1' => 'better_role_or_pay'], $manager->user))->toThrow(RuntimeException::class, 'Answer "Was the resignation expected?"')
        ->and(fn () => $this->feedback->submit($byManager, ['q1' => 'boredom'], $manager->user))->toThrow(RuntimeException::class, 'Choose one of the options');
    $this->feedback->submit($byManager, ['q1' => $reasons['options'][1]['value'], 'q2' => 'no', 'q3' => 4, 'q4' => 'yes', 'q5' => 'Handed over to the team lead'], $manager->user);
    $this->feedback->submit($byHr, ['q1' => 'career_growth', 'q2' => 'attempted_declined', 'q3' => 'no', 'q4' => 'yes', 'q5' => 'Amicable'], $interviewer);
    expect($byManager->refresh()->status)->toBe('submitted')->and($byManager->answers['q3'])->toBe(4)
        ->and(fn () => $this->feedback->submit($byManager, [], $manager->user))->toThrow(RuntimeException::class, 'already been given')
        ->and(wfbTaskKinds($manager->user, $manager))->toBe([]);

    // Readers: the respondent, HR who conduct exit interviews; never the employee (others' answers) nor the manager (the
    // employee's own), nor anyone else.
    expect($this->feedback->canRead($manager->user, $byManager))->toBeTrue()
        ->and($this->feedback->canRead($this->hr, $byManager))->toBeTrue()
        ->and($this->feedback->canRead($interviewer, $byHr))->toBeTrue()
        ->and($this->feedback->canRead($employee->user, $byManager))->toBeFalse()
        ->and($this->feedback->canRead($employee->user, $byHr))->toBeFalse()
        ->and($this->feedback->canRead($employee->user, $own))->toBeTrue()
        ->and($this->feedback->canRead($manager->user, $own))->toBeFalse()
        ->and($this->feedback->canRead($outsider, $byManager))->toBeFalse()
        ->and($this->feedback->visibleTo($employee->user)->pluck('id')->all())->toBe([$own->id])
        ->and($this->feedback->visibleTo($manager->user)->pluck('id')->all())->toBe([$byManager->id])
        ->and($this->feedback->visibleTo($this->hr)->count())->toBe(3)
        ->and($manager->user->can('view', $byManager))->toBeTrue()->and($employee->user->can('view', $byManager))->toBeFalse();

    // Only HR who read the process cancels; withdrawing the resignation cancels what is still pending.
    expect(fn () => $this->feedback->cancel($own, 'Not needed', $manager->user))->toThrow(RuntimeException::class, 'cannot cancel');
    app(Exits::class)->withdraw($case, 'Staying after all', $this->hr);
    expect($own->refresh()->status)->toBe('cancelled')->and($byManager->refresh()->status)->toBe('submitted');
});

it('lets the manager answer on the Feedback screen and HR read the answers there', function () {
    wfbPolicy('exit', ['notice_period_days' => 30, 'interview_manager' => true, 'interview_manager_template_id' => wfbTemplate('EXIT_MANAGER')]);
    $manager = employeeWithUser();
    $employee = employeeWithUser($manager);
    forceLifecycle($employee, LifecycleState::Active, ['joining_date' => '2024-01-01']);
    $case = app(Exits::class)->initiate($employee, 'resignation', 'Relocating');
    $request = FeedbackRequest::query()->where('subject_id', $case->id)->sole();

    $this->actingAs($manager->user);
    Livewire::test(ListFeedbackRequests::class)->assertOk()->assertCanSeeTableRecords([$request])
        ->callTableAction('answer', $request, ['answers' => ['q1' => 'relocation', 'q2' => 'yes', 'q3' => '5', 'q4' => 'yes', 'q5' => 'Documented in the wiki']])
        ->assertHasNoTableActionErrors();
    expect($request->refresh()->status)->toBe('submitted')->and($request->answers['q1'])->toBe('relocation');

    $this->actingAs($employee->user);
    Livewire::test(ListFeedbackRequests::class)->assertOk()->assertCanNotSeeTableRecords([$request]);

    $this->actingAs($this->hr);
    Livewire::test(ListFeedbackRequests::class)->assertOk()->assertCanSeeTableRecords([$request])->assertTableActionVisible('read', $request)->assertTableActionHidden('answer', $request);
});

it('asks the manager\'s confirmation feedback before the decision and the employee\'s self-review, copied to the next level when the policy says so', function () {
    wfbPolicy('confirmation', ['review_lead_days' => 10, 'feedback_template_id' => wfbTemplate('CONFIRMATION'), 'self_review_template_id' => wfbTemplate('PROBATION_SELF'), 'share_feedback_with_next_level' => true]);
    $skipLevel = employeeWithUser();
    $manager = employeeWithUser($skipLevel);
    $employee = employeeWithUser($manager);
    forceLifecycle($skipLevel, LifecycleState::Confirmed);
    forceLifecycle($manager, LifecycleState::Confirmed);
    forceLifecycle($employee, LifecycleState::Probation, ['joining_date' => '2026-03-25', 'probation_end_date' => '2026-09-25']);
    $reviews = app(ConfirmationReviews::class);
    $lifecycleHr = tenantUser($this->tenant, ['employee.lifecycle']);

    $reviews->openDue();
    $review = ConfirmationReview::query()->where('employee_id', $employee->id)->firstOrFail();
    $requests = FeedbackRequest::query()->where('subject_id', $review->id)->get()->keyBy('process');
    [$assessment, $selfReview] = [$requests['confirmation'], $requests['probation']];

    expect($assessment->respondent_user_id)->toBe($manager->user_id)->and($assessment->share_with_next_level)->toBeTrue()
        ->and($selfReview->respondent_user_id)->toBe($employee->user_id)->and($selfReview->due_on->toDateString())->toBe('2026-09-25')
        ->and(wfbTaskKinds($employee->user, $employee))->toBe(['probation'])
        ->and(fn () => $reviews->confirm($review, 'Good', $this->hr))->toThrow(RuntimeException::class, 'confirmation feedback is still pending');

    $this->feedback->submit($assessment, ['q1' => 4, 'q2' => 4, 'q3' => 5, 'q4' => 4, 'q5' => 'confirm', 'q6' => 'Solid start'], $manager->user);
    expect($this->feedback->canRead($skipLevel->user, $assessment))->toBeTrue()
        ->and($this->feedback->canRead($lifecycleHr, $assessment))->toBeTrue()
        ->and($this->feedback->canRead($employee->user, $assessment))->toBeFalse()
        ->and($this->feedback->visibleTo($skipLevel->user)->pluck('process')->sort()->values()->all())->toBe(['confirmation', 'probation']);

    $reviews->confirm($review, 'Good', $this->hr);
    expect($review->refresh()->status)->toBe('confirmed')
        ->and($selfReview->refresh()->status)->toBe('cancelled')->and($selfReview->cancel_reason)->toBe('Confirmation review decided');
});

it('lets HR cancel a manager\'s feedback that cannot come, so the review can be decided', function () {
    wfbPolicy('confirmation', ['review_lead_days' => 10, 'feedback_template_id' => wfbTemplate('CONFIRMATION')]);
    $manager = employeeWithUser();
    $employee = employeeWithUser($manager);
    forceLifecycle($employee, LifecycleState::Probation, ['joining_date' => '2026-03-25', 'probation_end_date' => '2026-09-25']);
    app(ConfirmationReviews::class)->openDue();
    $review = ConfirmationReview::query()->where('employee_id', $employee->id)->firstOrFail();
    $assessment = FeedbackRequest::query()->where('subject_id', $review->id)->sole();

    expect(fn () => $this->feedback->cancel($assessment, '', $this->hr))->toThrow(RuntimeException::class, 'Say why')
        ->and(fn () => $this->feedback->canRead($manager->user, $assessment))->not->toThrow(Exception::class)
        ->and($this->feedback->canRead($employee->user, $assessment))->toBeFalse();
    $this->feedback->cancel($assessment, 'The manager has left', $this->hr);
    app(ConfirmationReviews::class)->extend($review, 2, 'More time without a manager', $this->hr);
    expect($review->refresh()->status)->toBe('extended');
});
