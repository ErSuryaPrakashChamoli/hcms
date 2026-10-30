<?php

namespace App\Domain\Notifications\Listeners;

use App\Domain\Assets\Events\AssetEvent;
use App\Domain\Attendance\Events\AttendanceEvent;
use App\Domain\Career\Events\CareerEvent;
use App\Domain\Communication\Events\CommunicationEvent;
use App\Domain\Compensation\Events\CompensationEvent;
use App\Domain\Configuration\Events\ConfigurationChangeProposed;
use App\Domain\Configuration\Events\FormSubmitted;
use App\Domain\Development\Events\DevelopmentEvent;
use App\Domain\Documents\Events\DocumentExpiring;
use App\Domain\Employment\Events\EmploymentEvent;
use App\Domain\Employment\Events\ImportEvent;
use App\Domain\Employment\Models\Employee;
use App\Domain\Engagement\Events\EngagementEvent;
use App\Domain\Exit\Events\ExitEvent;
use App\Domain\Expense\Events\ExpenseEvent;
use App\Domain\Experience\Events\RequestFollowUpEvent;
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
use App\Domain\Workflow\Events\InboxEvent;
use App\Domain\Workflow\Events\WorkflowCompleted;
use App\Domain\Workflow\Events\WorkflowTaskAssigned;
use App\Domain\Workforce\Events\WorkforceEvent;
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
            ExpenseEvent::class => 'onExpense',
            CareerEvent::class => 'onTalent',
            TalentEvent::class => 'onTalent',
            SuccessionEvent::class => 'onTalent',
            WorkforceEvent::class => 'onWorkforce',
            CompensationEvent::class => 'onCompensation',
            EngagementEvent::class => 'onEngagement',
            CommunicationEvent::class => 'onCommunication',
            ImportEvent::class => 'onImport',
            InboxEvent::class => 'onInbox',
            // HROne parity RQ-2: a requester's reminder to the approvers, and colleagues they chose to inform.
            RequestFollowUpEvent::class => 'onRequestFollowUp',
        ];
    }

    /** HROne setup parity P2: uploads waiting for a checker, approved, rejected by their workflow, completed. Rules first, else in-app. */
    public function onImport(ImportEvent $event): void
    {
        $sent = $this->engine->fire($event->name, $this->context->build($event->import, ['import' => $event->context]), $event->import);
        if ($sent->isNotEmpty() || $event->recipientUserIds === []) {
            return;
        }
        $users = User::forCurrentTenant()->whereIn('id', $event->recipientUserIds)->get()->filter(fn (User $u) => $u->isActive());
        if ($users->isEmpty()) {
            return;
        }
        $c = $event->context;
        $what = ($c['type_label'] ?? 'Upload').' '.($c['file'] ?? '');
        $title = match ($event->name) {
            'import.validated' => $what.' is waiting for your approval',
            'import.approved' => $what.' was approved'.(isset($c['by']) ? ' by '.$c['by'] : '').'; it can be run',
            'import.rejected' => $what.' was rejected by its workflow; upload a corrected file',
            'import.completed' => $what.' has been run',
            default => str_replace('.', ' ', $event->name),
        };

        $this->notifier->send($users, ['in_app'], $title, $title.'.', $event->name, $event->import);
    }

    /**
     * HROne setup parity P3: a request sent back to its requester, resubmitted, or a new message in its discussion. Rules
     * first (the request's employee context), else in-app to the named people. Never the message text.
     */
    public function onInbox(InboxEvent $event): void
    {
        $sent = $this->engine->fire($event->name, $this->context->build($event->subject, ['inbox' => $event->context]), $event->source);
        if ($sent->isNotEmpty() || $event->recipientUserIds === []) {
            return;
        }
        $users = User::forCurrentTenant()->whereIn('id', $event->recipientUserIds)->get()->filter(fn (User $u) => $u->isActive());
        if ($users->isEmpty()) {
            return;
        }
        $c = $event->context;
        $what = trim(($c['kind'] ?? 'Request').(isset($c['label']) ? ': '.$c['label'] : ''));
        $title = match ($event->name) {
            'workflow.task.sent_back' => ($c['by'] ?? 'An approver').' sent back '.$what,
            'workflow.task.resubmitted' => ($c['by'] ?? 'The requester').' resubmitted '.$what,
            'workflow.task.commented' => 'New message from '.($c['by'] ?? 'someone').' on '.$what,
            // HROne setup parity P4: an out-of-office period hands someone's approvals to the recipient.
            'workflow.task.delegated' => ($c['by'] ?? 'A colleague').' is out of office from '.($c['from'] ?? '').' to '.($c['to'] ?? '').': you decide their approvals ('.($c['label'] ?? 'approvals').')',
            default => str_replace('.', ' ', $event->name),
        };

        $this->notifier->send($users, ['in_app'], $title, $title.'.', $event->name, $event->source);
    }

    /**
     * HROne parity RQ-2: "Send reminder" (request.reminder) and "Notify others" (request.intimated). Rules first — the
     * request's employee context, `request.*` labels, audience `named` (the recipients) and, for a reminder, `assignee`
     * (the approvers) — else in-app to the recipients. A reminder never carries the request's reason; an intimation carries
     * the requester's own message.
     */
    public function onRequestFollowUp(RequestFollowUpEvent $event): void
    {
        $extra = ['request' => $event->context, 'named_user_ids' => $event->recipientUserIds];
        if ($event->name === 'request.reminder') {
            $extra['task'] = ['assignee_user_ids' => $event->recipientUserIds];
        }
        $sent = $this->engine->fire($event->name, $this->context->build($event->request, $extra), $event->request);
        if ($sent->isNotEmpty() || $event->recipientUserIds === []) {
            return;
        }
        $users = User::forCurrentTenant()->whereIn('id', $event->recipientUserIds)->get()->filter(fn (User $u) => $u->isActive());
        if ($users->isEmpty()) {
            return;
        }
        $c = $event->context;
        $what = trim(($c['type'] ?? 'Request').(isset($c['title']) && $c['title'] !== '' ? ': '.$c['title'] : ''));
        [$title, $body] = match ($event->name) {
            'request.reminder' => [($c['by'] ?? 'The requester').' sent a reminder about '.$what,
                'It is waiting for your decision'.(isset($c['requested_on']) ? ' since '.$c['requested_on'] : '').'. Open your Approval Center to decide.'],
            'request.intimated' => [($c['by'] ?? 'A colleague').' wants you to know about '.$what,
                trim((string) ($c['message'] ?? '')) !== '' ? (string) $c['message'] : 'No message.'],
            default => [str_replace('.', ' ', $event->name), ''],
        };

        $this->notifier->send($users, ['in_app'], $title, $body, $event->name, $event->request);
    }

    /** Rules first; otherwise a direct in-app note to the recipients the event names. */
    public function onExit(ExitEvent $event): void
    {
        $sent = $this->engine->fire($event->name, $this->context->build($event->employee ?? $event->subject, ['exit' => $event->context]), $event->subject);

        if ($sent->isNotEmpty() || $event->recipientUserIds === []) {
            return;
        }

        $users = User::forCurrentTenant()->whereIn('id', $event->recipientUserIds)->get()->filter(fn (User $u) => $u->isActive());
        if ($users->isEmpty()) {
            return;
        }

        $c = $event->context;
        $who = $event->employee?->person?->full_name ?? 'an employee';
        $title = match ($event->name) {
            'exit.initiated' => $c['type'].' initiated for '.$who.' (last day '.$c['last_working_day'].')',
            'exit.withdrawn' => 'Resignation withdrawn by '.$who,
            // HROne setup parity P5: the resignation's approval workflow accepted it.
            'exit.resignation_accepted' => 'Your resignation was accepted (last day '.$c['last_working_day'].')',
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

        $users = User::forCurrentTenant()->whereIn('id', $event->recipientUserIds)->get()->filter(fn (User $u) => $u->isActive());
        if ($users->isEmpty()) {
            return;
        }

        $c = $event->context;
        // Phase 12: references only (number, service, status). Never the free-text subject, form data,
        // comments, resolutions or grievance details.
        $ref = fn () => ($c['number'] ?? '').(isset($c['service']) ? ' ('.$c['service'].')' : '');
        $title = match ($event->name) {
            'servicedesk.ticket.created' => 'New HR request '.$ref(),
            'servicedesk.ticket.assigned' => 'HR request assigned to you: '.$ref(),
            'servicedesk.ticket.intimated' => 'HR request assigned: '.$ref(),
            'servicedesk.ticket.reassigned' => 'HR request reassigned: '.$ref(),
            'servicedesk.ticket.acknowledged' => 'HR has acknowledged your request '.$ref(),
            'servicedesk.ticket.commented' => (($c['internal'] ?? false) ? 'Internal note on ' : 'New message on ').$ref().(isset($c['by']) ? ' from '.$c['by'] : ''),
            'servicedesk.ticket.waiting_for_employee' => 'HR needs your input on request '.$ref(),
            'servicedesk.ticket.approval_required' => 'HR request '.$ref().' is waiting for approval',
            'servicedesk.ticket.ready_to_execute' => 'Approved — HR request '.$ref().' is ready to execute',
            'servicedesk.ticket.resolved' => 'Resolved: HR request '.$ref(),
            'servicedesk.ticket.closed' => 'Closed: HR request '.$ref(),
            'servicedesk.ticket.cancelled' => 'Cancelled: HR request '.$ref(),
            'servicedesk.ticket.reopened' => 'Reopened: HR request '.$ref(),
            'servicedesk.ticket.sla_warning' => 'SLA approaching: HR request '.$ref(),
            'servicedesk.ticket.escalated' => 'SLA breached: HR request '.$ref().(isset($c['level']) ? ' (level '.$c['level'].')' : ''),
            'servicedesk.reminder.waiting_for_employee' => 'Reminder: HR is waiting for your input on request '.$ref(),
            'servicedesk.reminder.waiting_for_hr' => 'Reminder: HR request '.$ref().' is waiting for HR',
            'grievance.raised' => 'New grievance case '.$c['number'].' ('.$c['severity'].')',
            'grievance.assigned' => 'Grievance case assigned to you: '.$c['number'],
            'grievance.updated' => 'Update on grievance case '.$c['number'],
            'grievance.resolved' => 'Your grievance case '.$c['number'].' has been resolved',
            'grievance.escalated' => 'Grievance case overdue: '.$c['number'],
            'kb.article.published' => ($c['mandatory'] ? 'Mandatory reading: ' : 'Please acknowledge: ').$c['title'],
            'kb.article.review_requested' => 'Knowledge article to review: '.$c['title'],
            'kb.reminder.acknowledgement' => 'Reminder: please acknowledge '.$c['title'],
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

    /** HROne setup parity EX-C/EX-D: expense reports and advances. Rules first, else in-app to the named users (references and amounts only). */
    public function onExpense(ExpenseEvent $event): void
    {
        $sent = $this->engine->fire($event->name, $this->context->build($event->employee, ['expense' => $event->context]), $event->subject);
        if ($sent->isNotEmpty() || $event->recipientUserIds === []) {
            return;
        }
        $users = User::forCurrentTenant()->whereIn('id', $event->recipientUserIds)->get()->filter(fn (User $u) => $u->isActive());
        if ($users->isEmpty()) {
            return;
        }
        $c = $event->context;
        $who = $event->employee->person?->full_name ?? 'an employee';
        $amount = fn (string $key) => number_format((float) ($c[$key] ?? 0), 2);
        $title = match ($event->name) {
            'expense.report_submitted' => 'Expense report '.$c['number'].' ('.($c['part'] ?? '').') from '.$who.' to approve: '.$amount('amount'),
            'expense.exceptional_submitted' => 'Exceptional expense in report '.$c['number'].' from '.$who.' to approve: '.$amount('amount'),
            'expense.report_approved' => 'Your expense report '.$c['number'].' is approved: '.$amount('approved'),
            'expense.report_partly_approved' => 'Your expense report '.$c['number'].' is partly approved: '.$amount('approved'),
            'expense.report_rejected' => 'Your expense report '.$c['number'].' is rejected',
            'expense.report_returned' => 'Your expense report '.$c['number'].' was returned to you for changes',
            'expense.report_cancelled' => 'Expense report '.$c['number'].' from '.$who.' was cancelled',
            'expense.report_paid' => 'Your expense report '.$c['number'].' was paid: '.$amount('amount'),
            'expense.advance_requested' => 'Expense advance '.$c['number'].' from '.$who.' to approve: '.$amount('amount'),
            'expense.advance_cancel_requested' => 'Cancellation of expense advance '.$c['number'].' from '.$who.' to approve',
            'expense.advance_approved' => 'Your expense advance '.$c['number'].' is approved: '.$amount('approved'),
            'expense.advance_rejected' => 'Your expense advance '.$c['number'].' is rejected',
            'expense.advance_cancelled' => 'Expense advance '.$c['number'].' is cancelled',
            'expense.advance_dispensed' => 'Your expense advance '.$c['number'].' was paid: '.$amount('amount').' (settle by '.($c['settle_by'] ?? '').')',
            'expense.advance_settled' => 'Your expense advance '.$c['number'].' is settled',
            'expense.advance_written_off' => 'The balance of your expense advance '.$c['number'].' was written off',
            // HROne setup parity EX-F: travel requests, their bookings and cancellation.
            'expense.travel_requested' => 'Travel request '.$c['number'].(isset($c['part']) ? ' ('.$c['part'].')' : '').' from '.$who.' to approve ('.($c['start_date'] ?? '').' to '.($c['end_date'] ?? '').')',
            'expense.travel_approved' => 'Your travel request '.$c['number'].' is approved',
            'expense.travel_rejected' => 'Your travel request '.$c['number'].' is rejected',
            'expense.travel_cancel_requested' => 'Cancellation of travel request '.$c['number'].' from '.$who.' to approve',
            'expense.travel_cancelled' => 'Travel request '.$c['number'].' is cancelled',
            'expense.travel_booking_requested' => 'Travel request '.$c['number'].' of '.$who.' to book ('.($c['legs'] ?? 0).' leg(s))',
            'expense.travel_booked' => 'Booked for travel request '.$c['number'].': '.($c['leg'] ?? '').' ('.($c['reference'] ?? '').')',
            default => str_replace(['.', '_'], ' ', $event->name),
        };

        $this->notifier->send($users, ['in_app'], $title, $title.'.', $event->name, $event->subject);
    }

    public function onAsset(AssetEvent $event): void
    {
        $sent = $this->engine->fire($event->name, $this->context->build($event->employee ?? $event->subject, ['asset' => $event->context]), $event->subject);
        if ($sent->isNotEmpty()) {
            return;
        }
        // HROne setup parity AS-B: asset requests name who hears of them (the owner role, the manager, the employee).
        $users = $event->recipientUserIds !== []
            ? User::forCurrentTenant()->whereIn('id', $event->recipientUserIds)->get()->filter(fn (User $u) => $u->isActive())->values()->all()
            : (str_starts_with($event->name, 'asset.request') || $event->name === 'asset.return_requested' ? [] : array_filter([$event->employee?->user()->first()]));
        if ($users === [] || ! collect($users)->every(fn (User $u) => $u->isActive())) {
            return;
        }

        $c = $event->context;
        $who = fn () => $event->employee?->person?->full_name ?? 'an employee';
        $title = match ($event->name) {
            'asset.assigned' => 'Asset assigned to you: '.$c['asset'].' ['.$c['tag'].']',
            'asset.transferred' => 'Asset transferred to you: '.$c['asset'].' ['.$c['tag'].']',
            'asset.returned' => 'Asset returned: '.$c['asset'].' ['.$c['tag'].']',
            'asset.lost' => 'Asset reported lost: '.$c['asset'].' ['.$c['tag'].']',
            'asset.acknowledgement_due' => 'Please acknowledge receipt of '.$c['asset'].' ['.$c['tag'].']',
            'asset.requested' => 'Asset request '.$c['number'].' from '.$who().' to approve: '.($c['asset_type'] ?? ''),
            'asset.return_requested' => 'Asset return '.$c['number'].' from '.$who().' to approve: '.($c['tag'] ?? $c['asset_type'] ?? ''),
            'asset.request_to_fulfil' => 'Asset request '.$c['number'].' for '.$who().' to fulfil: '.($c['kind'] ?? '').' · '.($c['asset_type'] ?? '').($c['due_on'] ? ' (due '.$c['due_on'].')' : ''),
            'asset.request_approved' => 'Your asset request '.$c['number'].' is approved',
            'asset.request_rejected' => 'Your asset request '.$c['number'].' is rejected',
            'asset.request_fulfilled' => 'Your asset request '.$c['number'].' is fulfilled',
            'asset.request_cancelled' => 'Asset request '.$c['number'].' was cancelled',
            default => str_replace('.', ' ', $event->name),
        };

        $this->notifier->send($users, ['in_app'], $title, $title.'.', $event->name, $event->subject);
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
        $users = User::forCurrentTenant()->whereIn('id', $event->recipientUserIds)->get()
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

    /**
     * Phase 10 workforce events: in-app to the users the event names only (position owner, plan owner,
     * submitter). No tenant rule fires with an employee's context; planning details are not sent to
     * employees.
     */
    public function onWorkforce(WorkforceEvent $event): void
    {
        $users = User::forCurrentTenant()->whereIn('id', $event->recipientUserIds)->get()->filter(fn (User $u) => $u->isActive())->values();
        if ($users->isEmpty()) {
            return;
        }
        $c = $event->context;
        $title = match ($event->name) {
            'workforce.position.occupied' => 'Position '.($c['code'] ?? '').' is now occupied',
            'workforce.position.vacated' => 'Position '.($c['code'] ?? '').' is vacant from '.($c['effective_date'] ?? ''),
            'workforce.reminder.vacancy' => 'Position '.($c['code'] ?? '').' has been vacant for a while',
            'workforce.reminder.pending_approval' => 'Workforce plan '.($c['plan'] ?? '').' v'.($c['version'] ?? '').' is waiting ('.str_replace('_', ' ', (string) ($c['status'] ?? '')).')',
            'workforce.reminder.plan_expiry' => 'Workforce plan '.($c['plan'] ?? '').' ends on '.($c['period_end'] ?? ''),
            'workforce.plan.published' => 'Workforce plan '.($c['plan'] ?? '').' v'.($c['version'] ?? '').' is now active',
            default => ucfirst(str_replace(['workforce.', '.', '_'], ['', ' ', ' '], $event->name)).(isset($c['code']) ? ': '.$c['code'] : (isset($c['plan']) ? ': '.$c['plan'] : '')),
        };

        $this->notifier->send($users, ['in_app'], $title, $title.'.', $event->name, $event->subject);
    }

    /**
     * Phase 11 compensation events: in-app to the users the event names only (the next person in the
     * approval chain, the proposer, the approver). Titles carry references and dates, never amounts,
     * reasons or notes; nothing is sent to the employee concerned.
     */
    public function onCompensation(CompensationEvent $event): void
    {
        $users = User::forCurrentTenant()->whereIn('id', $event->recipientUserIds)->get()->filter(fn (User $u) => $u->isActive() && ($event->employee === null || (int) $event->employee->user_id !== (int) $u->id))->values();
        if ($users->isEmpty()) {
            return;
        }
        $c = $event->context;
        $who = isset($c['employee_code']) ? ' for '.$c['employee_code'] : '';
        $title = match ($event->name) {
            'compensation.change.submitted' => 'Compensation change'.$who.' is waiting for review',
            'compensation.change.reviewed' => 'Compensation change'.$who.' is waiting for approval',
            'compensation.change.approved' => 'Compensation change'.$who.' was approved and is ready to execute',
            'compensation.change.rejected' => 'Compensation change'.$who.' was rejected',
            'compensation.change.returned' => 'Compensation change'.$who.' was returned to you',
            'compensation.change.cancelled' => 'Compensation change'.$who.' was cancelled',
            'compensation.change.scheduled', 'compensation.change.corrected' => 'Compensation change'.$who.' is scheduled from '.($c['effective_date'] ?? ''),
            'compensation.change.effective' => 'Compensation change'.$who.' is effective from '.($c['effective_date'] ?? ''),
            'compensation.reminder.pending_change' => 'Compensation change'.$who.' is still waiting ('.str_replace('_', ' ', (string) ($c['status'] ?? '')).')',
            'compensation.reminder.pending_cycle' => 'Compensation cycle '.($c['cycle'] ?? '').' is still waiting ('.str_replace('_', ' ', (string) ($c['status'] ?? '')).')',
            default => ucfirst(str_replace(['compensation.', '.', '_'], ['', ' ', ' '], $event->name)).(isset($c['cycle']) ? ': '.$c['cycle'] : (isset($c['structure']) ? ': '.$c['structure'] : '')),
        };

        $this->notifier->send($users, ['in_app'], $title, $title.'.', $event->name, $event->subject);
    }

    /**
     * Phase 13 engagement events: in-app to the users the event names (approvers, the preparer,
     * feedback handlers). Tenant rules are not fired. Titles carry survey / campaign references only,
     * never answers, respondents, feedback text or authors. Survey invitations and reminders are sent
     * by SurveyNotices, and response submissions are never bridged.
     */
    public function onEngagement(EngagementEvent $event): void
    {
        $users = User::forCurrentTenant()->whereIn('id', $event->recipientUserIds)->get()->filter(fn (User $u) => $u->isActive())->values();
        if ($users->isEmpty()) {
            return;
        }
        $c = $event->context;
        $survey = ($c['survey'] ?? '').(isset($c['version']) ? ' v'.$c['version'] : '');
        $title = match ($event->name) {
            'survey.review_requested' => 'Survey to review: '.$survey,
            'survey.approved' => 'Survey approved: '.$survey,
            'survey.returned' => 'Survey returned to you: '.$survey,
            'survey.published' => 'Survey scheduled: '.$survey,
            'survey.opened' => 'Survey is open: '.$survey,
            'survey.closed' => 'Survey closed: '.$survey,
            'campaign.review_requested' => 'Campaign to review: '.($c['campaign'] ?? ''),
            'campaign.launched' => 'Campaign launched: '.($c['campaign'] ?? '').(($c['partial'] ?? false) ? ' (some items were not ready)' : ''),
            'feedback.submitted' => 'New employee feedback: '.($c['category'] ?? ''),
            default => ucfirst(str_replace(['.', '_'], ' ', $event->name)).(isset($c['survey']) ? ': '.$survey : (isset($c['campaign']) ? ': '.$c['campaign'] : '')),
        };

        $this->notifier->send($users, ['in_app'], $title, $title.'.', $event->name, $event->subject);
    }

    /**
     * Phase 13 communication lifecycle events: in-app to the approvers / preparer they name. The
     * announcement itself reaches its audience through CommunicationDelivery (preferences, tracking),
     * never through this bridge, so tenant rules are not fired here.
     */
    public function onCommunication(CommunicationEvent $event): void
    {
        $users = User::forCurrentTenant()->whereIn('id', $event->recipientUserIds)->get()->filter(fn (User $u) => $u->isActive())->values();
        if ($users->isEmpty()) {
            return;
        }
        $c = $event->context;
        $title = match ($event->name) {
            'communication.review_requested' => 'Announcement to review: '.($c['title'] ?? ''),
            'communication.approved' => 'Announcement approved: '.($c['title'] ?? ''),
            'communication.returned' => 'Announcement returned to you: '.($c['title'] ?? ''),
            'communication.published' => 'Announcement published: '.($c['title'] ?? ''),
            default => ucfirst(str_replace(['communication.', '.', '_'], ['', ' ', ' '], $event->name)).': '.($c['title'] ?? ''),
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
            // HROne setup parity HP-8b: scheduled goal check-in prompts.
            'performance.reminder.goal_check_in' => 'Goal check-in: update the progress of your '.($event->context['scope'] === 'employee' ? '' : $event->context['scope'].' ').'goals ('.$event->context['goals'].')',
            'performance.check_in.submitted' => 'Check-in submitted for '.$event->context['period'],
            'performance.check_in.reviewed' => 'Your manager responded to your check-in for '.$event->context['period'],
            'performance.pip.completed' => 'Improvement plan outcome recorded: '.$event->context['outcome'],
            // HROne setup parity HP-8d / HP-8e / HP-8f: goal sheets for approval, system-mapped goal sheets, PIP decisions.
            'performance.goal_plan.submitted' => 'Goal sheet of '.($event->employee?->person?->full_name ?? 'an employee').' waits for approval ('.($event->context['cycle'] ?? '').')',
            'performance.goal_plan.approved' => 'Your goal sheet for '.($event->context['cycle'] ?? 'the cycle').' was approved',
            'performance.goal_plan.rejected' => 'Your goal sheet for '.($event->context['cycle'] ?? 'the cycle').' was sent back: '.($event->context['note'] ?? ''),
            'performance.goal_sheet.assigned' => 'Goal sheet ready to complete for '.($event->context['for'] ?? 'an employee').': '.$event->context['goals'].' goal(s) from '.$event->context['template'],
            'performance.pip.rejected' => 'Improvement plan request rejected: '.($event->context['note'] ?? ''),
            // HROne setup parity HP-8j: initiatives (titles and names only; never the feedback text).
            'performance.initiative.assigned' => 'New initiative for you: '.($event->context['title'] ?? '').' (from '.($event->context['by'] ?? 'your manager').')',
            'performance.initiative.created' => ($event->context['for'] ?? 'A team member').' created an initiative: '.($event->context['title'] ?? ''),
            'performance.initiative.feedback_requested' => 'Your feedback is asked on '.($event->context['about'] ?? 'a colleague').'\'s initiative: '.($event->context['title'] ?? ''),
            'performance.initiative.feedback_given' => ($event->context['from'] ?? 'A colleague').' gave feedback on the initiative '.($event->context['title'] ?? ''),
            'performance.initiative.completed' => 'Initiative completed by '.($event->context['for'] ?? 'an employee').': '.($event->context['title'] ?? ''),
            // HROne setup parity HP-8h / HP-8i: 360 feedback rounds (names and round titles only; never answers or who said
            // what) and periodic reviews.
            'performance.feedback360.nominate' => 'Nominate reviewers for the 360 feedback of '.($event->context['for'] ?? 'an employee').' ('.($event->context['round'] ?? '').')',
            'performance.feedback360.nominations_submitted' => '360 feedback nominations for '.($event->context['for'] ?? 'an employee').' wait for approval',
            'performance.feedback360.nominations_returned' => '360 feedback nominations for '.($event->context['for'] ?? 'an employee').' were sent back: '.($event->context['note'] ?? ''),
            'performance.feedback360.requested' => 'Your 360 feedback is asked for '.($event->context['for'] ?? 'a colleague').' (by '.($event->context['due'] ?? 'the window end').')',
            'performance.feedback360.results_ready' => 'Your 360 feedback for '.($event->context['round'] ?? 'the round').' is ready to read',
            'performance.period_review.opened' => 'Periodic review to complete for '.($event->context['for'] ?? 'an employee').' ('.($event->context['period'] ?? '').')',
            // HROne parity GO-1: HR reopened a submitted review (the reviewer fills it in again; the reason is HR's).
            'performance.review.reopened' => 'Your '.mb_strtolower((string) ($event->context['type'] ?? '')).' review for '.($event->context['cycle'] ?? 'the cycle').' was reopened by HR: '.($event->context['reason'] ?? ''),
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

        // HROne setup parity BP-9: the tasks these events stand for reach the people they name when no rule is set up.
        $c = $event->context;
        [$title, $body] = match ($event->name) {
            'payroll.claim_reminder' => ['Reimbursement to claim', 'You can still claim '.number_format((float) ($c['total'] ?? 0), 2).' ('.collect((array) ($c['balances'] ?? []))->map(fn ($v, $k) => $k.' '.number_format((float) $v, 2))->implode(', ').'). Claim it with your bills before the year ends.'],
            'payroll.variable_pay_due' => ['Variable pay to enter', 'Enter the '.($c['month'] ?? '').' payout of '.($c['plan'] ?? 'the variable pay plan').' for '.($c['employee'] ?? 'your team member').' (target '.number_format((float) ($c['target'] ?? 0), 2).').'],
            'payroll.loan_guarantor_requested' => ['Stand guarantor for a loan?', ($c['employee'] ?? 'A colleague').' named you as guarantor for loan '.($c['number'] ?? '').'. Accept or decline it in Approvals.'],
            default => [null, null],
        };
        if ($title !== null && $sent->isEmpty() && ! empty($c['recipients'])) {
            $users = User::forCurrentTenant()->whereIn('id', (array) $c['recipients'])->get()->filter(fn (User $u) => $u->isActive());
            if ($users->isNotEmpty()) {
                $this->notifier->send($users, ['in_app'], $title, $body, $event->name, $subject);
            }
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
            // HROne setup parity P3: a person moved the task to someone else (Reassign).
            'reassigned' => 'workflow.task.reassigned',
            default => 'workflow.task.assigned',
        };

        $sent = $this->engine->fire($event_name, $vars, $task);

        if ($sent->isEmpty() && $recipients->isNotEmpty()) {
            $prefix = match ($event->reason) {
                'reminder' => 'Reminder: ',
                'escalated' => 'Escalated to you: ',
                'reassigned' => 'Reassigned to you: ',
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
