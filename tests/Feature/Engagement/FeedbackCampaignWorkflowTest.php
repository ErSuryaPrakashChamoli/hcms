<?php

use App\Domain\Audit\Models\AuditEvent;
use App\Domain\Communication\Jobs\DeliverCommunication;
use App\Domain\Communication\Jobs\ProcessCommunication;
use App\Domain\Communication\Services\Communications;
use App\Domain\Engagement\Exceptions\EngagementRuleViolation;
use App\Domain\Engagement\Jobs\ProcessEngagement;
use App\Domain\Engagement\Jobs\SendSurveyInvitations;
use App\Domain\Engagement\Models\EmployeeFeedback;
use App\Domain\Engagement\Models\EngagementIdentity;
use App\Domain\Engagement\Models\EngagementReminderLog;
use App\Domain\Engagement\Services\Campaigns;
use App\Domain\Engagement\Services\EngagementProcessor;
use App\Domain\Engagement\Services\Feedback;
use App\Domain\Engagement\Services\SurveyNotices;
use App\Domain\Engagement\Services\SurveyResponses;
use App\Domain\Engagement\Services\Surveys;
use App\Domain\Grievance\Models\GrievanceCategory;
use App\Domain\Knowledge\Models\Article;
use App\Domain\Notifications\Models\NotificationDelivery;
use App\Domain\ServiceDesk\Models\Ticket;
use App\Domain\ServiceDesk\Models\TicketCategory;
use App\Domain\Workflow\Models\WorkflowTask;
use App\Domain\Workflow\Services\WorkflowEngine;
use App\Support\Tenancy\Jobs\BindTenantContext;

require_once __DIR__.'/../Workflow/WorkflowTestHelpers.php';
require_once __DIR__.'/../ServiceDesk/ServiceDeskTestHelpers.php';
require_once __DIR__.'/EngagementTestHelpers.php';

beforeEach(function () {
    $this->travelTo('2026-10-05 09:00:00');
    $this->tenant = provisionTenant();
    actAsTenant($this->tenant);
    $this->actors = engagementActors();
    $this->staff = engagementTeam(5);
    $this->handler = tenantUser($this->tenant, ['engagement.feedback', 'servicedesk.agent', 'servicedesk.view']);
});

it('stores feedback by mode, refers identified feedback to the service desk and anonymous feedback to an anonymous grievance', function () {
    $feedback = app(Feedback::class);
    $identified = $feedback->submit($this->staff[0]->user, 'identified', 'process', 'The expense tool keeps timing out');
    $confidential = $feedback->submit($this->staff[1]->user, 'confidential', 'wellbeing', 'Workload is unsustainable in my team');
    $anonymous = $feedback->submit($this->staff[2]->user, 'anonymous', 'workplace', 'Harassment in the canteen queue');
    expect($identified['id'])->not->toBeNull()->and($confidential['id'])->toBeNull()->and($anonymous['id'])->toBeNull();

    $rows = EmployeeFeedback::query()->get()->keyBy('mode');
    expect($rows['identified']->employee_id)->toBe($this->staff[0]->id)->and($rows['confidential']->employee_id)->toBeNull()->and($rows['anonymous']->employee_id)->toBeNull()
        ->and(EngagementIdentity::query()->where('subject_type', 'feedback')->pluck('subject_id')->all())->toBe([$rows['confidential']->id])
        ->and($rows['anonymous']->getRawOriginal('body'))->not->toContain('Harassment');
    $audits = AuditEvent::query()->where('action', 'FEEDBACK_SUBMITTED')->get()->keyBy(fn ($e) => $e->metadata['mode']);
    expect($audits['identified']->actor_id)->toBe($this->staff[0]->user_id)->and($audits['anonymous']->actor_id)->toBeNull()->and($audits['anonymous']->entity_id)->toBeNull()
        ->and($audits['confidential']->actor_id)->toBeNull();
    expect($feedback->mine($this->staff[1]->user)->count())->toBe(0)->and($feedback->mine($this->staff[0]->user)->count())->toBe(1)
        ->and($feedback->inbox($this->handler)->count())->toBe(3);

    $category = TicketCategory::query()->where('status', 'active')->first();
    $ticket = $feedback->referToServiceDesk($rows['identified'], $category, $this->handler);
    expect($ticket)->toBeInstanceOf(Ticket::class)->and($ticket->employee_id)->toBe($this->staff[0]->id)->and($rows['identified']->refresh()->status)->toBe('referred')
        ->and(fn () => $feedback->referToServiceDesk($rows['identified']->refresh(), $category, $this->handler))->toThrow(EngagementRuleViolation::class);
    expect(fn () => $feedback->referToServiceDesk($rows['anonymous'], $category, $this->handler))->toThrow(EngagementRuleViolation::class, 'Anonymous')
        ->and(fn () => $feedback->referToServiceDesk($rows['confidential'], $category, $this->handler, 'too short'))->toThrow(EngagementRuleViolation::class);

    $grievanceCategory = GrievanceCategory::query()->where('allow_anonymous', true)->first();
    $grievance = $feedback->referToGrievance($rows['anonymous'], $grievanceCategory, $this->handler);
    expect($grievance->is_anonymous)->toBeTrue()->and($grievance->employee_id)->toBeNull()->and($rows['anonymous']->refresh()->referred_type)->toBe('grievance');
    expect(fn () => $rows['anonymous']->update(['body' => 'edited']))->toThrow(RuntimeException::class);
});

it('groups items in a campaign, launches approved items through their own modules once, and reports partial launches', function () {
    $campaigns = app(Campaigns::class);
    $comms = app(Communications::class);
    $campaign = $campaigns->create(['code' => 'wellbeing-oct', 'name' => 'Wellbeing month', 'starts_on' => '2026-10-06', 'ends_on' => '2026-10-31'], $this->actors['preparer'], 'idem-1');
    expect($campaigns->create(['code' => 'x', 'name' => 'x', 'starts_on' => '2026-10-06'], $this->actors['preparer'], 'idem-1')->id)->toBe($campaign->id);

    // A survey version approved (not yet published), an approved announcement, a draft announcement, a KB article reference.
    $survey = app(Surveys::class)->create(['code' => 'WB', 'name' => 'Wellbeing pulse', 'closes_at' => '2026-10-20', 'audience_criteria' => ['lifecycle_states' => ['active']]], $this->actors['preparer']);
    $sv = $survey->versions()->first();
    app(Surveys::class)->saveQuestion($sv, ['key' => 'ok', 'type' => 'yes_no', 'prompt' => 'Are you OK?'], $this->actors['preparer']);
    app(Surveys::class)->approve(app(Surveys::class)->submit($sv->refresh(), $this->actors['preparer']), null, $this->actors['approver']);
    $ready = $comms->approve($comms->submit($comms->create(['title' => 'Wellbeing month starts', 'body' => 'x'], $this->actors['preparer']), $this->actors['preparer']), null, $this->actors['approver']);
    $notReady = $comms->create(['title' => 'Draft piece', 'body' => 'x'], $this->actors['preparer']);
    $article = Article::create(['title' => 'Mental health guide', 'body' => 'x']);
    foreach ([['survey', $survey->id], ['announcement', $ready->id], ['announcement', $notReady->id], ['article', $article->id]] as [$type, $id]) {
        $campaigns->addItem($campaign, $type, $id, $this->actors['preparer']);
    }
    expect(fn () => $campaigns->addItem($campaign, 'announcement', $ready->id, $this->actors['preparer']))->toThrow(EngagementRuleViolation::class);

    $campaign = $campaigns->submit($campaign, $this->actors['preparer']);
    expect(fn () => $campaigns->approve($campaign, null, $this->actors['preparer']))->toThrow(EngagementRuleViolation::class);
    $campaign = $campaigns->schedule($campaigns->approve($campaign, null, $this->actors['approver']), $this->actors['preparer']);
    expect($campaign->status)->toBe('scheduled');

    $this->travelTo('2026-10-06 07:00:00');
    $run = app(EngagementProcessor::class)->run();
    expect($run['campaigns_launched'])->toBe(1)->and($campaign->refresh()->status)->toBe('active')
        ->and($sv->refresh()->status)->toBe('open')->and($ready->refresh()->status)->toBe('published')->and($notReady->refresh()->status)->toBe('draft');
    $launch = AuditEvent::query()->where('action', 'CAMPAIGN_PUBLISHED')->sole();
    expect($launch->metadata['partial'])->toBeTrue()->and($launch->metadata['skipped_items'])->toBe(['announcement:'.$notReady->id])->and($launch->operation_id)->toBe($campaign->operation_id);
    // Launching again changes nothing.
    $campaigns->launch($campaign->refresh());
    expect(AuditEvent::query()->where('action', 'CAMPAIGN_PUBLISHED')->count())->toBe(1);
    expect($campaigns->stats($campaign))->toMatchArray(['recipients' => 5, 'sent' => 5, 'survey_eligible' => 5, 'survey_submitted' => 0]);

    $this->travelTo('2026-11-01 07:00:00');
    expect(app(EngagementProcessor::class)->run()['campaigns_completed'])->toBe(1);
    expect(fn () => $campaigns->cancel($campaign->refresh(), 'late', $this->actors['preparer']))->toThrow(EngagementRuleViolation::class);
});

it('lets a configured workflow decide a survey version and refuses an approval completed by the preparer', function () {
    $workflowApprover = tenantUser($this->tenant, ['task.view', 'task.act']);
    sdApprovalWorkflow($workflowApprover, 'survey_approval');
    config(['peopleos.engagement.approval_workflows.survey' => 'survey_approval']);
    $surveys = app(Surveys::class);
    $version = $surveys->create(['code' => 'WF', 'name' => 'Workflow survey', 'closes_at' => '2026-10-20', 'audience_criteria' => ['lifecycle_states' => ['active']]], $this->actors['preparer'])->versions()->first();
    $surveys->saveQuestion($version, ['key' => 'q', 'type' => 'yes_no', 'prompt' => 'Q?'], $this->actors['preparer']);
    $version = $surveys->submit($version->refresh(), $this->actors['preparer']);
    expect($version->workflow_instance_id)->not->toBeNull()
        ->and(fn () => $surveys->approve($version, null, $this->actors['approver']))->toThrow(EngagementRuleViolation::class, 'workflow');

    $task = WorkflowTask::query()->where('workflow_instance_id', $version->workflow_instance_id)->firstOrFail();
    app(WorkflowEngine::class)->completeTask($task, 'approved', 'fine', $workflowApprover);
    $version->refresh();
    expect($version->status)->toBe('approved')->and($version->approved_by)->toBe($workflowApprover->id)->and($version->checksum)->toHaveLength(64);

    // SOD re-check: the preparer completing the workflow approval is refused.
    $self = tenantUser($this->tenant, ['engagement.manage', 'task.view', 'task.act']);
    sdApprovalWorkflow($self, 'survey_self');
    config(['peopleos.engagement.approval_workflows.survey' => 'survey_self']);
    $v2 = $surveys->create(['code' => 'WF2', 'name' => 'Self-approved', 'closes_at' => '2026-10-20', 'audience_criteria' => ['lifecycle_states' => ['active']]], $self)->versions()->first();
    $surveys->saveQuestion($v2, ['key' => 'q', 'type' => 'yes_no', 'prompt' => 'Q?'], $self);
    $v2 = $surveys->submit($v2->refresh(), $self);
    app(WorkflowEngine::class)->completeTask(WorkflowTask::query()->where('workflow_instance_id', $v2->workflow_instance_id)->firstOrFail(), 'approved', null, $self);
    expect($v2->refresh()->status)->toBe('in_review')->and($v2->workflow_instance_id)->toBeNull()
        ->and(AuditEvent::query()->where('action', 'REJECTED')->where('entity_id', (string) $v2->id)->value('reason'))->toContain('separation of duties');
});

it('opens and closes surveys on their dates, sends invitations and reminders once, and stops at the maximum', function () {
    $surveys = app(Surveys::class);
    $version = $surveys->create(['code' => 'SCH', 'name' => 'Scheduled', 'opens_at' => '2026-10-06 09:00:00', 'closes_at' => '2026-10-16 18:00:00', 'anonymity_mode' => 'identified',
        'audience_criteria' => ['lifecycle_states' => ['active']], 'reminder_policy' => ['after_days' => [2, 4], 'closing_days_before' => 1, 'max' => 2]], $this->actors['preparer'])->versions()->first();
    $surveys->saveQuestion($version, ['key' => 'q', 'type' => 'yes_no', 'prompt' => 'Q?', 'required' => true], $this->actors['preparer']);
    $version = $surveys->publish($surveys->approve($surveys->submit($version->refresh(), $this->actors['preparer']), null, $this->actors['approver']), $this->actors['preparer']);
    expect($version->status)->toBe('scheduled');

    $this->travelTo('2026-10-06 09:10:00');
    $run = app(EngagementProcessor::class)->run();
    expect($run['opened'])->toBe(1)->and($version->refresh()->status)->toBe('open')
        ->and(NotificationDelivery::query()->where('event', 'survey.invitation')->distinct()->count('user_id'))->toBe(5)
        ->and(AuditEvent::query()->where('action', 'SURVEY_INVITATION_SENT')->count())->toBe(1);
    expect(app(EngagementProcessor::class)->run()['invitations'])->toBe(0);

    app(SurveyResponses::class)->submit($version, $this->staff[0]->user, ['q' => 'yes']);
    $this->travelTo('2026-10-10 09:00:00'); // after:2 and after:4 are both due; identified → only the 4 who have not answered
    expect(app(SurveyNotices::class)->remind($version->refresh()))->toBe(8);
    $this->travelTo('2026-10-15 09:00:00'); // closing reminder due, but the maximum (2) is reached
    expect(app(SurveyNotices::class)->remind($version->refresh()))->toBe(0)
        ->and(EngagementReminderLog::query()->where('reminder', 'survey.reminder')->count())->toBe(8);

    $this->travelTo('2026-10-16 18:05:00');
    expect(app(EngagementProcessor::class)->run()['closed'])->toBe(1)->and($version->refresh()->status)->toBe('closed');
});

it('carries tenant context on every engagement and communication job', function () {
    foreach ([new SendSurveyInvitations($this->tenant->id, 1), new ProcessEngagement, new DeliverCommunication($this->tenant->id, 1), new ProcessCommunication] as $job) {
        expect($job->tenantId())->toBe($this->tenant->id)->and($job->middleware()[0])->toBeInstanceOf(BindTenantContext::class);
    }
    $this->artisan('peopleos:engagement:process', ['--tenant' => $this->tenant->id])->assertSuccessful();
    $this->artisan('peopleos:communication:process', ['--tenant' => $this->tenant->id])->assertSuccessful();
});
