<?php

namespace App\Domain\Notifications\Listeners;

use App\Domain\Assets\Events\AssetEvent;
use App\Domain\Attendance\Events\AttendanceEvent;
use App\Domain\Career\Events\CareerEvent;
use App\Domain\Configuration\Events\ConfigurationChangeProposed;
use App\Domain\Configuration\Events\FormSubmitted;
use App\Domain\Development\Events\DevelopmentEvent;
use App\Domain\Documents\Events\DocumentExpiring;
use App\Domain\Employment\Events\EmploymentEvent;
use App\Domain\Employment\Models\Employee;
use App\Domain\Exit\Events\ExitEvent;
use App\Domain\Identity\Models\User;
use App\Domain\Identity\Scopes\AccessScope;
use App\Domain\Learning\Events\LearningEvent;
use App\Domain\Leave\Events\LeaveEvent;
use App\Domain\Lifecycle\Events\EmployeeLifecycleChanged;
use App\Domain\Lifecycle\Events\EmployeeReminderDue;
use App\Domain\Notifications\Services\NotificationContext;
use App\Domain\Notifications\Services\NotificationEngine;
use App\Domain\Notifications\Services\Notifier;
use App\Domain\Notifications\Services\TemplateRenderer;
use App\Domain\Onboarding\Events\OnboardingTaskAssigned;
use App\Domain\Payroll\Events\PayrollEvent;
use App\Domain\Payroll\Models\Payslip;
use App\Domain\Performance\Events\PerformanceEvent;
use App\Domain\ServiceDesk\Events\ServiceDeskEvent;
use App\Domain\Skills\Events\SkillEvent;
use App\Domain\Succession\Events\SuccessionEvent;
use App\Domain\Talent\Events\TalentEvent;
use App\Domain\Workflow\Events\WorkflowCompleted;
use App\Domain\Workflow\Events\WorkflowTaskAssigned;
use Illuminate\Events\Dispatcher;

/**
 * Domain events -> notification engine (tenant rules). Task assignments additionally get a
 * built-in in-app message so the inbox works before any rule is configured.
 */
final class NotificationEventBridge
{
    public function __construct(
        private readonly NotificationEngine $engine,
        private readonly NotificationContext $context,
        private readonly Notifier $notifier,
        private readonly TemplateRenderer $renderer,
    ) {}

    public function subscribe(Dispatcher $events): array
    {
        return [
            EmployeeLifecycleChanged::class => 'onLifecycle',
            FormSubmitted::class => 'onFormSubmitted',
            ConfigurationChangeProposed::class => 'onChangeProposed',
            WorkflowTaskAssigned::class => 'onTaskAssigned',
            WorkflowCompleted::class => 'onWorkflowCompleted',
            EmployeeReminderDue::class => 'onReminder',
            OnboardingTaskAssigned::class => 'onOnboardingTaskAssigned',
            DocumentExpiring::class => 'onDocumentExpiring',
            AttendanceEvent::class => 'onAttendance',
            LeaveEvent::class => 'onLeave',
            EmploymentEvent::class => 'onEmployment',
            PayrollEvent::class => 'onPayroll',
            PerformanceEvent::class => 'onPerformance',
            LearningEvent::class => 'onLearning',
            SkillEvent::class => 'onSkillsOrDevelopment',
            DevelopmentEvent::class => 'onSkillsOrDevelopment',
            ServiceDeskEvent::class => 'onServiceDesk',
            ExitEvent::class => 'onExit',
            AssetEvent::class => 'onAsset',
            CareerEvent::class => 'onTalent',
            TalentEvent::class => 'onTalent',
            SuccessionEvent::class => 'onTalent',
        ];
    }

    /** Rules first; otherwise a direct in-app note to the recipients the event names. */
    public function onExit(ExitEvent $event): void
    {
        $sent = $this->engine->fire($event->name, $this->context->build($event->employee ?? $event->subject, ['exit' => $event->context]), $event->subject);

        if ($sent->isNotEmpty() || $event->recipientUserIds === []) {
            return;
        }

        $users = User::query()->whereIn('id', $event->recipientUserIds)->get()->filter(fn (User $u) => $u->isActive());
        if ($users->isEmpty()) {
            return;
        }

        $c = $event->context;
        $who = $event->employee?->person?->full_name ?? 'an employee';
        $title = match ($event->name) {
            'exit.initiated' => $c['type'].' initiated for '.$who.' (last day '.$c['last_working_day'].')',
            'exit.withdrawn' => 'Resignation withdrawn by '.$who,
            'exit.clearance.pending' => $c['stage'].' needed for '.$who.' by '.$c['last_working_day'],
            'exit.clearance.cleared' => $c['stage'].' cleared for '.$who,
            'exit.clearance.blocked' => $c['stage'].' blocked for '.$who.': '.$c['remarks'],
            'exit.settlement.approved' => 'Your full & final settlement is approved: net '.number_format($c['net'], 2),
            'exit.settlement.paid' => 'Your full & final settlement has been paid ('.$c['reference'].')',
            'exit.interview.submitted' => 'Exit interview submitted for '.$who,
            'exit.completed' => 'Exit completed for '.$who,
            'exit.alumni_created' => 'Welcome to the alumni network',
            'letter.requested' => 'Letter to approve: '.$c['number'].' ('.$c['type'].') for '.$who,
            'letter.approved' => 'Letter approved: '.$c['number'],
            'letter.issued' => 'Letter issued to you: '.$c['subject'],
            'alumni.request.created' => 'Alumni request '.$c['number'].': '.$c['type'].' from '.$who,
            'alumni.request.handled' => 'Your request '.$c['number'].' is '.$c['status'],
            default => str_replace('.', ' ', $event->name),
        };

        $this->notifier->send($users, ['in_app'], $title, $title.'.', $event->name, $event->subject);
    }

    /** Tickets, grievances, KB and announcements: rules first, else in-app to the named users. */
    public function onServiceDesk(ServiceDeskEvent $event): void
    {
        $sent = $this->engine->fire($event->name, $this->context->build($event->subject, ['request' => $event->context]), $event->subject);

        if ($sent->isNotEmpty() || $event->recipientUserIds === []) {
            return;
        }

        $users = User::query()->whereIn('id', $event->recipientUserIds)->get()->filter(fn (User $u) => $u->isActive());
        if ($users->isEmpty()) {
            return;
        }

        $c = $event->context;
        $title = match ($event->name) {
            'servicedesk.ticket.created' => 'New request '.$c['number'].': '.$c['subject'],
            'servicedesk.ticket.assigned' => 'Ticket assigned to you: '.$c['number'].' '.$c['subject'],
            'servicedesk.ticket.commented' => ($c['internal'] ? 'Internal note on ' : 'Reply on ').$c['number'].' from '.$c['by'],
            'servicedesk.ticket.resolved' => 'Resolved: '.$c['number'].' '.$c['subject'],
            'servicedesk.ticket.closed' => 'Closed: '.$c['number'],
            'servicedesk.ticket.reopened' => 'Reopened: '.$c['number'].' '.$c['subject'],
            'servicedesk.ticket.escalated' => 'SLA breached: '.$c['number'].' '.$c['subject'],
            'grievance.raised' => 'New grievance case '.$c['number'].' ('.$c['severity'].')',
            'grievance.assigned' => 'Grievance case assigned to you: '.$c['number'],
            'grievance.updated' => 'Update on grievance case '.$c['number'],
            'grievance.resolved' => 'Your grievance case '.$c['number'].' has been resolved',
            'grievance.escalated' => 'Grievance case overdue: '.$c['number'],
            'kb.article.published' => ($c['mandatory'] ? 'Mandatory reading: ' : 'Please acknowledge: ').$c['title'],
            'communication.published' => $c['type'].': '.$c['title'],
            default => str_replace('.', ' ', $event->name),
        };

        $this->notifier->send($users, ['in_app'], $title, $title.'.', $event->name, $event->subject);
    }

    public function onLearning(LearningEvent $event): void
    {
        if ($event->employee === null) {
            return; // catalogue events notify nobody directly
        }
        $sent = $this->engine->fire($event->name, $this->context->build($event->employee, ['learning' => $event->context]), $event->subject);

        $user = $event->employee->user()->first();
        if ($sent->isNotEmpty() || ! $user?->isActive()) {
            return;
        }

        $c = $event->context;
        $title = match ($event->name) {
            'learning.assigned' => 'New learning: '.$c['course'].($c['due_on'] ? ' (due '.$c['due_on'].')' : ''),
            'learning.due_soon' => 'Due soon: '.$c['course'].' by '.$c['due_on'],
            'learning.overdue' => 'Overdue: '.$c['course'],
            'learning.completed' => 'Completed: '.$c['course'],
            'learning.failed' => 'Not passed: '.$c['course'],
            'learning.certificate_expiring' => 'Certificate expiring on '.$c['expires_on'].': '.$c['course'],
            'learning.certificate_expired' => 'Certificate expired: '.$c['course'],
            'learning.certificate.issued' => 'Certificate issued: '.$c['course'],
            'learning.enrolment.approved' => 'Learning request approved: '.$c['course'],
            'learning.enrolment.rejected' => 'Learning request not approved: '.$c['course'],
            'learning.session.waitlisted' => 'Waitlisted: '.$c['session'].' on '.$c['starts_at'],
            'learning.program.completed' => 'Program completed: '.($c['program'] ?? ''),
            'learning.reminder.due' => 'Reminder: '.$c['course'].' is due on '.$c['due_on'],
            'learning.reminder.overdue_mandatory' => 'Mandatory learning overdue: '.$c['course'],
            'learning.session.registered' => 'Registered: '.$c['session'].' on '.$c['starts_at'],
            default => str_replace('.', ' ', $event->name),
        };

        $this->notifier->send([$user], ['in_app'], $title, $title.'.', $event->name, $event->subject);
    }

    public function onAsset(AssetEvent $event): void
    {
        $sent = $this->engine->fire($event->name, $this->context->build($event->employee ?? $event->subject, ['asset' => $event->context]), $event->subject);

        $user = $event->employee?->user()->first();
        if ($sent->isNotEmpty() || ! $user?->isActive()) {
            return;
        }

        $c = $event->context;
        $title = match ($event->name) {
            'asset.assigned' => 'Asset assigned to you: '.$c['asset'].' ['.$c['tag'].']',
            'asset.transferred' => 'Asset transferred to you: '.$c['asset'].' ['.$c['tag'].']',
            'asset.returned' => 'Asset returned: '.$c['asset'].' ['.$c['tag'].']',
            'asset.lost' => 'Asset reported lost: '.$c['asset'].' ['.$c['tag'].']',
            default => str_replace('.', ' ', $event->name),
        };

        $this->notifier->send([$user], ['in_app'], $title, $title.'.', $event->name, $event->subject);
    }

    /** Phase 8 skill and development events: rules first, otherwise an in-app note to the named recipients. */
    public function onSkillsOrDevelopment(object $event): void
    {
        $sent = $event->employee ? $this->engine->fire($event->name, $this->context->build($event->employee, ['learning' => $event->context]), $event->subject) : collect();
        if ($sent->isNotEmpty() || $event->recipientEmployeeIds === []) {
            return;
        }
        $users = Employee::query()->with('user')->whereIn('id', $event->recipientEmployeeIds)->get()->pluck('user')->filter(fn ($u) => $u?->isActive());
        if ($users->isEmpty()) {
            return;
        }
        $c = $event->context;
        $title = match ($event->name) {
            'skill.assessed' => 'A skill assessment was finalized'.(isset($c['type']) ? ' ('.$c['type'].')' : ''),
            'skill.reminder.assessment_due' => 'Reminder: finish the '.($c['skill'] ?? 'skill').' assessment for '.($c['employee'] ?? 'your report'),
            'development.plan.created' => 'Development plan created: '.($c['title'] ?? ''),
            'development.plan.completed' => 'Development plan completed: '.($c['title'] ?? ''),
            'development.reminder.milestone_due' => 'Development milestone due '.($c['due_on'] ?? '').': '.($c['title'] ?? ''),
            'learning.reminder.team_overdue' => ($c['employee'] ?? 'A team member').' is overdue on mandatory learning: '.($c['course'] ?? ''),
            default => str_replace('.', ' ', $event->name),
        };

        $this->notifier->send($users, ['in_app'], $title, $title.'.', $event->name, $event->subject);
    }

    /**
     * Phase 9 career, talent and succession events. Talent and succession are confidential: no tenant
     * rule fires with the employee's context, and the employee concerned is never a recipient — only
     * the users and employees the event names (plan owner, assessor, review participants).
     */
    public function onTalent(CareerEvent|TalentEvent|SuccessionEvent $event): void
    {
        $subjectUserId = $event->employee?->user_id;
        $users = User::query()->whereIn('id', $event->recipientUserIds)->get()
            ->merge(Employee::query()->withoutGlobalScope(AccessScope::class)->with('user')->whereIn('id', $event->recipientEmployeeIds)->get()->pluck('user'))
            ->filter(fn ($u) => $u?->isActive())
            ->reject(fn (User $u) => ! $event instanceof CareerEvent && $subjectUserId !== null && (int) $u->id === (int) $subjectUserId)
            ->unique('id')->values();
        if ($users->isEmpty()) {
            return;
        }
        $c = $event->context;
        $title = match ($event->name) {
            'succession.reminder.position_review' => 'Critical position review due '.($c['due_on'] ?? '').': '.($c['title'] ?? ''),
            'succession.reminder.plan_review' => 'Succession plan review due '.($c['due_on'] ?? '').': '.($c['title'] ?? ''),
            'succession.reminder.readiness_expiring' => 'A readiness assessment you recorded expires on '.($c['expires_on'] ?? '').($c['title'] ?? null ? ' ('.$c['title'].')' : ''),
            'talent.reminder.review_scheduled' => 'Talent review '.($c['name'] ?? '').' is scheduled for '.($c['scheduled_for'] ?? ''),
            'talent.review.completed' => 'Talent review completed: '.($c['name'] ?? ''),
            'succession.successor.added' => 'A successor was added to a succession plan you own',
            'succession.successor.removed' => 'A successor was removed from a succession plan you own',
            'succession.plan.created' => 'Succession plan created: '.($c['position'] ?? ''),
            'talent.pool.membership_changed' => 'Talent pool membership changed'.(isset($c['pool']) ? ': '.$c['pool'] : ''),
            default => str_replace(['.', '_'], ' ', $event->name),
        };

        $this->notifier->send($users, ['in_app'], $title, $title.'.', $event->name, $event->subject);
    }

    public function onPerformance(PerformanceEvent $event): void
    {
        $sent = $this->engine->fire($event->name, $this->context->build($event->employee ?? $event->subject, ['performance' => $event->context]), $event->subject);

        if ($sent->isNotEmpty() || $event->recipientEmployeeIds === []) {
            return;
        }

        $users = Employee::query()->with('user')->whereIn('id', $event->recipientEmployeeIds)->get()->pluck('user')->filter(fn ($u) => $u?->isActive());
        if ($users->isEmpty()) {
            return;
        }

        $title = match ($event->name) {
            'performance.cycle.launched' => 'Performance cycle launched: '.$event->context['cycle'],
            'performance.cycle.stage_changed' => $event->context['cycle'].' moved to '.$event->context['stage'],
            'performance.review.assigned' => $event->context['type'].' review to complete for '.$event->context['cycle'],
            'performance.review.submitted' => $event->context['type'].' review submitted for '.($event->employee?->person?->full_name ?? 'an employee'),
            'performance.appraisal.finalized' => 'Your appraisal for '.$event->context['cycle'].' is final: '.$event->context['label'],
            'performance.feedback.received' => 'New '.$event->context['type'].' feedback from '.$event->context['from'],
            'performance.feedback.requested' => ($event->context['about'] ?? 'A colleague').' asked you for feedback',
            'performance.goal.assigned' => 'New goal: '.$event->context['title'],
            'performance.pip.opened' => 'Improvement plan opened until '.$event->context['until'],
            'performance.promotion.recommended' => 'Promotion recommended for '.($event->employee?->person?->full_name ?? 'an employee'),
            'performance.reminder.review_due' => 'Reminder: review due by '.$event->context['due'].' for '.$event->context['cycle'],
            'performance.reminder.check_in_response' => 'Reminder: a check-in for '.$event->context['period'].' is waiting for your response',
            'performance.reminder.pip_checkpoint' => 'Reminder: improvement-plan checkpoint due '.$event->context['due'],
            'performance.check_in.submitted' => 'Check-in submitted for '.$event->context['period'],
            'performance.check_in.reviewed' => 'Your manager responded to your check-in for '.$event->context['period'],
            'performance.pip.completed' => 'Improvement plan outcome recorded: '.$event->context['outcome'],
            default => str_replace('.', ' ', $event->name),
        };

        $this->notifier->send($users, ['in_app'], $title, $title.'.', $event->name, $event->subject);
    }

    public function onPayroll(PayrollEvent $event): void
    {
        $subject = $event->subject;
        $employee = $subject instanceof Payslip ? $subject->employee()->with('user')->first() : null;
        $sent = $this->engine->fire($event->name, $this->context->build($employee ?? $subject, ['payroll' => $event->context]), $subject);

        // Payslips always reach the employee, rule or no rule.
        if ($event->name === 'payroll.payslip_generated' && $sent->isEmpty() && $employee?->user?->isActive()) {
            $this->notifier->send([$employee->user], ['in_app'], 'Payslip for '.$event->context['period'], 'Your payslip for '.$event->context['period'].' is ready.', $event->name, $subject);
        }
    }

    public function onEmployment(EmploymentEvent $event): void
    {
        $this->engine->fire($event->name, $this->context->build($event->employee, ['employment' => $event->context]), $event->subject);
    }

    public function onLeave(LeaveEvent $event): void
    {
        $this->engine->fire($event->name, $this->context->build($event->employee, ['leave' => $event->context]), $event->subject);
    }

    public function onAttendance(AttendanceEvent $event): void
    {
        $this->engine->fire($event->name, $this->context->build($event->employee, ['attendance' => $event->context]), $event->subject);
    }

    public function onReminder(EmployeeReminderDue $event): void
    {
        $this->engine->fire($event->name, $this->context->build($event->employee, ['reminder' => $event->context]), $event->employee);
    }

    public function onOnboardingTaskAssigned(OnboardingTaskAssigned $event): void
    {
        $task = $event->task->loadMissing(['plan.employee', 'owner', 'ownerRole']);
        $recipients = $task->owner ? collect([$task->owner]) : ($task->ownerRole?->users()->get() ?? collect());
        $vars = $this->context->build($task->plan->employee, ['task' => ['id' => $task->id, 'title' => $task->title, 'phase' => $task->phaseLabel(), 'due_on' => $task->due_on, 'assignee_user_ids' => $recipients->pluck('id')->all()]]);

        $sent = $this->engine->fire('onboarding.task.assigned', $vars, $task);

        if ($sent->isEmpty() && $recipients->isNotEmpty()) {
            $this->notifier->send($recipients->filter(fn (User $u) => $u->isActive()), ['in_app'], 'Onboarding: '.$task->title, $this->renderer->render('{{ task.phase }} task for {{ employee.name }}, due {{ task.due_on }}.', $vars), 'onboarding.task.assigned', $task);
        }
    }

    public function onDocumentExpiring(DocumentExpiring $event): void
    {
        $document = $event->document;
        $this->engine->fire('document.expiring', $this->context->build($document->employee, ['document' => ['id' => $document->id, 'title' => $document->title, 'type' => $document->type?->name, 'expires_on' => $document->expires_on]]), $document);
    }

    public function onLifecycle(EmployeeLifecycleChanged $event): void
    {
        $this->engine->fire($event->name(), $this->context->build($event->employee, ['lifecycle' => ['from' => $event->from?->getLabel(), 'to' => $event->to->getLabel(), 'reason' => $event->reason, 'effective_date' => $event->effectiveDate]]), $event->employee);
    }

    public function onFormSubmitted(FormSubmitted $event): void
    {
        $this->engine->fire('form.submitted', $this->context->build($event->submission, ['form' => ['name' => $event->submission->form->name, 'key' => $event->submission->form->key]], $event->submission->submitter), $event->submission);
    }

    public function onChangeProposed(ConfigurationChangeProposed $event): void
    {
        $this->engine->fire('configuration.change.proposed', $this->context->build($event->change, ['change' => ['id' => $event->change->id, 'risk' => $event->change->risk_level->getLabel(), 'summary' => $event->change->impact['summary'] ?? null]], $event->change->requester), $event->change);
    }

    public function onTaskAssigned(WorkflowTaskAssigned $event): void
    {
        $task = $event->task->loadMissing(['instance.workflow', 'instance.subject', 'instance.starter', 'assignee', 'assigneeRole']);
        $instance = $task->instance;
        $recipients = $task->assignee ? collect([$task->assignee]) : ($task->assigneeRole?->users()->get() ?? collect());

        $vars = $this->context->build($instance->subject, [
            'task' => ['id' => $task->id, 'title' => $task->title, 'type' => $task->type, 'due_at' => $task->due_at, 'assignee_user_ids' => $recipients->pluck('id')->all()],
            'workflow' => ['name' => $instance->workflow->name, 'run' => $instance->id],
        ], $instance->starter);

        $event_name = match ($event->reason) {
            'reminder' => 'workflow.task.reminder',
            'escalated' => 'workflow.task.escalated',
            default => 'workflow.task.assigned',
        };

        $sent = $this->engine->fire($event_name, $vars, $task);

        if ($sent->isEmpty() && $recipients->isNotEmpty()) {
            $prefix = match ($event->reason) {
                'reminder' => 'Reminder: ',
                'escalated' => 'Escalated to you: ',
                default => '',
            };

            $this->notifier->send(
                $recipients->filter(fn (User $u) => $u->isActive()),
                ['in_app'],
                $prefix.$task->title,
                $this->renderer->render('{{ workflow.name }} for {{ subject.label }}. Due {{ task.due_at }}.', $vars),
                $event_name,
                $task,
            );
        }
    }

    public function onWorkflowCompleted(WorkflowCompleted $event): void
    {
        $instance = $event->instance;
        $this->engine->fire('workflow.completed', $this->context->build($instance->subject, ['workflow' => ['name' => $instance->workflow->name, 'run' => $instance->id, 'outcome' => $instance->outcome]], $instance->starter), $instance);
    }
}
