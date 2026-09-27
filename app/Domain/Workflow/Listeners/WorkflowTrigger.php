<?php

namespace App\Domain\Workflow\Listeners;

use App\Domain\Attendance\Events\AttendanceEvent;
use App\Domain\Bgv\Events\BgvCompleted;
use App\Domain\Configuration\Events\ConfigurationChangeProposed;
use App\Domain\Configuration\Events\FormSubmitted;
use App\Domain\Configuration\Services\EmployeeRuleContext;
use App\Domain\Configuration\Services\RuleEngine;
use App\Domain\Documents\Events\DocumentExpiring;
use App\Domain\Exit\Events\ExitEvent;
use App\Domain\Leave\Events\LeaveEvent;
use App\Domain\Lifecycle\Events\EmployeeLifecycleChanged;
use App\Domain\Lifecycle\Events\EmployeeReminderDue;
use App\Domain\Notifications\Services\NotificationContext;
use App\Domain\Onboarding\Events\OnboardingCompleted;
use App\Domain\ServiceDesk\Events\ServiceDeskEvent;
use App\Domain\Workflow\Models\Workflow;
use App\Domain\Workflow\Services\WorkflowEngine;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Events\Dispatcher;

/** Automation (§44): domain events start every published workflow that listens for them. */
final class WorkflowTrigger
{
    public function __construct(
        private readonly WorkflowEngine $engine,
        private readonly RuleEngine $rules,
        private readonly EmployeeRuleContext $employeeContext,
        private readonly NotificationContext $notificationContext,
    ) {}

    public function subscribe(Dispatcher $events): array
    {
        return [
            EmployeeLifecycleChanged::class => 'onLifecycle',
            FormSubmitted::class => 'onFormSubmitted',
            ConfigurationChangeProposed::class => 'onChangeProposed',
            EmployeeReminderDue::class => 'onReminder',
            OnboardingCompleted::class => 'onOnboardingCompleted',
            BgvCompleted::class => 'onBgvCompleted',
            DocumentExpiring::class => 'onDocumentExpiring',
            AttendanceEvent::class => 'onAttendance',
            LeaveEvent::class => 'onLeave',
            ServiceDeskEvent::class => 'onServiceDesk',
            ExitEvent::class => 'onExit',
        ];
    }

    public function onExit(ExitEvent $event): void
    {
        if (in_array($event->name, ['exit.initiated', 'letter.requested', 'alumni.request.created'], true)) {
            $this->fire($event->name, $event->subject, $event->context + ['employee_id' => $event->employee?->id]);
        }
    }

    public function onServiceDesk(ServiceDeskEvent $event): void
    {
        if (in_array($event->name, ['servicedesk.ticket.created', 'grievance.raised'], true)) {
            $this->fire($event->name, $event->subject, $event->context);
        }
    }

    public function onLeave(LeaveEvent $event): void
    {
        $this->fire($event->name, $event->subject, $event->context + ['employee_id' => $event->employee->id]);
    }

    public function onAttendance(AttendanceEvent $event): void
    {
        $this->fire($event->name, $event->subject, $event->context + ['employee_id' => $event->employee->id]);
    }

    public function onReminder(EmployeeReminderDue $event): void
    {
        $this->fire($event->name, $event->employee, $event->context);
    }

    public function onOnboardingCompleted(OnboardingCompleted $event): void
    {
        $this->fire('onboarding.completed', $event->plan->employee, ['plan_id' => $event->plan->id]);
    }

    public function onBgvCompleted(BgvCompleted $event): void
    {
        $this->fire('bgv.completed', $event->case->employee, ['bgv_case_id' => $event->case->id, 'bgv_result' => $event->case->overall_result]);
    }

    public function onDocumentExpiring(DocumentExpiring $event): void
    {
        $this->fire('document.expiring', $event->document->employee, ['document_id' => $event->document->id, 'expires_on' => $event->document->expires_on?->toDateString()]);
    }

    public function onLifecycle(EmployeeLifecycleChanged $event): void
    {
        $this->fire($event->name(), $event->employee, ['from' => $event->from?->value, 'to' => $event->to->value, 'reason' => $event->reason]);
    }

    public function onFormSubmitted(FormSubmitted $event): void
    {
        $this->fire('form.submitted', $event->submission, ['form' => $event->submission->form->key]);
    }

    public function onChangeProposed(ConfigurationChangeProposed $event): void
    {
        $this->fire('configuration.change.proposed', $event->change, ['risk' => $event->change->risk_level->value]);
    }

    private function fire(string $trigger, Model $subject, array $context): void
    {
        Workflow::query()
            ->where('trigger_event', $trigger)
            ->where('status', 'active')
            ->whereHas('published')
            ->where(fn ($q) => $q->whereNull('subject_type')->orWhere('subject_type', $subject::class))
            ->orderBy('id')
            ->get()
            ->each(function (Workflow $workflow) use ($subject, $context, $trigger) {
                if (! empty($workflow->start_conditions) && ! $this->rules->matches($workflow->start_conditions, $this->contextFor($subject, $context), 'all')) {
                    return;
                }

                $this->engine->start($workflow, $subject, $context + ['trigger' => $trigger]);
            });
    }

    private function contextFor(Model $subject, array $context): array
    {
        $employee = $this->notificationContext->employeeOf($subject);

        return ($employee ? $this->employeeContext->build($employee) : []) + $context;
    }
}
