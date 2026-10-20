<?php

use App\Domain\Audit\Models\AuditEvent;
use App\Domain\Communication\Services\CommunicationProcessor;
use App\Domain\Communication\Services\Communications;
use App\Domain\Engagement\Exceptions\EngagementRuleViolation;
use App\Domain\Experience\Services\NeedsAttention;
use App\Domain\Knowledge\Models\Article;
use App\Domain\Knowledge\Services\KnowledgeBase;
use App\Domain\Leave\Models\LeaveType;
use App\Domain\Leave\Services\LeaveAccrual;
use App\Domain\Leave\Services\Leaves;

require_once __DIR__.'/../Workflow/WorkflowTestHelpers.php';
require_once __DIR__.'/../Performance/PerformanceTestHelpers.php';
require_once __DIR__.'/../ServiceDesk/ServiceDeskTestHelpers.php';

beforeEach(function () {
    $this->travelTo('2026-09-21 09:00:00');
    $this->tenant = provisionTenant();
    actAsTenant($this->tenant);
    $this->hr = tenantUser($this->tenant, ['*']);
    $this->actingAs($this->hr);
    $this->employee = activeEmployee(null, ['kb.view', 'communication.view', 'task.view']);
    $this->probation = activeEmployee(null, ['kb.view', 'communication.view', 'task.view']);
    forceLifecycle($this->probation, 'probation');
});

it('versions articles on publish, targets audiences, and tracks reads and acknowledgements', function () {
    $kb = app(KnowledgeBase::class);
    $article = Article::create(['title' => 'Leave policy', 'category' => 'leave', 'body' => '# Leave\nTake it.', 'requires_acknowledgement' => true, 'is_mandatory_reading' => true]);
    expect($article->slug)->toBe('leave-policy')->and($kb->visibleTo($this->employee))->toHaveCount(0);

    kbPublishForTests($article);
    expect($article->refresh()->status)->toBe('published')->and($article->version)->toBe(1)->and($article->versions()->count())->toBe(1)
        ->and($this->employee->user->notifications()->count())->toBe(1)
        ->and($kb->visibleTo($this->employee)->pluck('id')->all())->toBe([$article->id])
        ->and($kb->visibleTo($this->employee, 'take'))->toHaveCount(1)
        ->and($kb->visibleTo($this->employee, 'payroll'))->toHaveCount(0)
        ->and($kb->pendingAcknowledgements($this->employee))->toHaveCount(1);

    $kb->recordRead($article, $this->employee);
    $kb->acknowledge($article, $this->employee);
    expect($kb->pendingAcknowledgements($this->employee))->toHaveCount(0)
        ->and($kb->stats($article))->toBe(['audience' => 2, 'read' => 1, 'acknowledged' => 1]);

    // A new version (a revision, reviewed again) asks everyone again.
    $kb->startRevision($article->refresh(), $this->hr);
    $article->refresh()->update(['body' => '# Leave v2']);
    kbPublishForTests($article->refresh());
    expect($article->refresh()->version)->toBe(2)->and($article->versions()->count())->toBe(2)
        ->and($kb->pendingAcknowledgements($this->employee))->toHaveCount(1);

    // Audience by rule: only probation employees.
    $onboarding = Article::create(['title' => 'Probation guide', 'category' => 'hr_policy', 'body' => 'Welcome', 'audience' => [['field' => 'lifecycle_state', 'operator' => 'equals', 'value' => 'probation']]]);
    kbPublishForTests($onboarding);
    expect($kb->visibleTo($this->employee)->pluck('id')->all())->toBe([$article->id])
        ->and($kb->visibleTo($this->probation)->pluck('id')->sort()->values()->all())->toBe([$article->id, $onboarding->id]);
    expect($this->employee->user->can('view', $onboarding))->toBeTrue()->and($this->employee->user->can('view', Article::create(['title' => 'Draft', 'body' => 'x'])))->toBeFalse();
});

it('publishes approved announcements to a snapshotted audience with a feed, read and acknowledgement tracking', function () {
    $comms = app(Communications::class);
    $preparer = tenantUser($this->tenant, ['communication.manage']);
    $approver = tenantUser($this->tenant, ['communication.approve']);
    // Phase 13: prepared by one person, approved by another, then published (audience snapshot + delivery).
    $publish = function (array $data) use ($comms, $preparer, $approver) {
        $draft = $comms->create($data, $preparer);
        $approved = $comms->approve($comms->submit($draft, $preparer), null, $approver);

        return $comms->publish($approved, $preparer);
    };
    $all = $publish(['title' => 'Office closed on Friday', 'type' => 'circular', 'body' => 'Deep cleaning.', 'requires_acknowledgement' => true, 'is_pinned' => true]);
    $probationOnly = $publish(['title' => 'Buddy programme', 'body' => 'Meet your buddy.', 'audience_criteria' => ['lifecycle_states' => ['probation']]]);
    $shortLived = $publish(['title' => 'Lift maintenance today', 'body' => 'x', 'expires_at' => now()->addHours(2)]);
    $future = $publish(['title' => 'Later', 'body' => 'x', 'publish_at' => now()->addDay()]);

    expect($future->status)->toBe('scheduled')->and($all->status)->toBe('published')->and($all->recipients_count)->toBe(2)
        ->and($comms->feedFor($this->employee)->pluck('id')->all())->toBe([$all->id, $shortLived->id])
        ->and($comms->feedFor($this->probation)->pluck('id')->all())->toBe([$all->id, $shortLived->id, $probationOnly->id])
        ->and($this->employee->user->notifications()->count())->toBe(2)
        ->and($this->probation->user->notifications()->count())->toBe(3)
        ->and($comms->pendingAcknowledgements($this->employee))->toHaveCount(1);

    $comms->markRead($all, $this->employee);
    $comms->acknowledge($all, $this->employee);
    $comms->acknowledge($all, $this->employee);
    expect($comms->pendingAcknowledgements($this->employee))->toHaveCount(0)
        ->and($comms->stats($all))->toMatchArray(['audience' => 2, 'read' => 1, 'acknowledged' => 1, 'sent' => 2, 'failed' => 0])
        ->and(AuditEvent::query()->where('action', 'ANNOUNCEMENT_ACKNOWLEDGED')->count())->toBe(1);
    expect(fn () => $comms->acknowledge($probationOnly, $this->employee))->toThrow(EngagementRuleViolation::class);

    $this->travelTo('2026-09-23 09:00:00');
    app(CommunicationProcessor::class)->run();
    expect($comms->feedFor($this->employee)->pluck('id')->all())->toBe([$all->id, $future->id]);
});

it('builds the Needs Attention list for an employee and a manager', function () {
    $attention = app(NeedsAttention::class);
    $items = $attention->forEmployee($this->employee, $this->employee->user)->pluck('count', 'key');
    expect($items->has('bank'))->toBeTrue()->and($items->has('pan'))->toBeTrue()->and($items->has('tax_declaration'))->toBeTrue()->and($items->has('kb_ack'))->toBeFalse();

    $article = Article::create(['title' => 'Code of conduct', 'category' => 'conduct', 'body' => 'Be kind.', 'is_mandatory_reading' => true]);
    kbPublishForTests($article);
    expect($attention->forEmployee($this->employee, $this->employee->user)->firstWhere('key', 'kb_ack')['count'])->toBe(1);

    $manager = activeEmployee(null, ['performance.team', 'leave.approve', 'task.view']);
    $report = activeEmployee($manager);
    leavePolicyForTests();
    app(LeaveAccrual::class)->accrue($report);
    app(Leaves::class)->request($report, LeaveType::query()->where('code', 'EL')->first(), '2026-09-28', '2026-09-28', 'Trip');
    $team = $attention->forManager($manager, $manager->user)->pluck('count', 'key');
    expect($team->get('leave'))->toBe(1);
});

function leavePolicyForTests(): void
{
    require_once __DIR__.'/../Leave/LeaveTestHelpers.php';
    leavePolicy(['EL' => ['days' => 24, 'accrual_frequency' => 'annual']]);
}
