<?php

namespace App\Domain\Workflow\Listeners;

use App\Domain\Assets\Events\AssetEvent;
use App\Domain\Attendance\Events\AttendanceEvent;
use App\Domain\Bgv\Events\BgvCompleted;
use App\Domain\Compensation\Events\CompensationEvent;
use App\Domain\Configuration\Events\ConfigurationChangeProposed;
use App\Domain\Configuration\Events\FormSubmitted;
use App\Domain\Configuration\Services\EmployeeRuleContext;
use App\Domain\Configuration\Services\RuleEngine;
use App\Domain\Documents\Events\DocumentExpiring;
use App\Domain\Employment\Events\EmploymentEvent;
use App\Domain\Employment\Events\ImportEvent;
use App\Domain\Exit\Events\ExitEvent;
use App\Domain\Expense\Events\ExpenseEvent;
use App\Domain\Leave\Events\LeaveEvent;
use App\Domain\Lifecycle\Events\EmployeeLifecycleChanged;
use App\Domain\Lifecycle\Events\EmployeeReminderDue;
use App\Domain\Notifications\Services\NotificationContext;
use App\Domain\Onboarding\Events\OnboardingCompleted;
use App\Domain\Payroll\Events\PayrollEvent;
use App\Domain\Performance\Events\PerformanceEvent;
use App\Domain\Platform\Services\SettingsRepository;
use App\Domain\ServiceDesk\Events\ServiceDeskEvent;
use App\Domain\Workflow\Models\Workflow;
use App\Domain\Workflow\Services\WorkflowEngine;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Events\Dispatcher;

/** Automation (§44): domain events start every published workflow that listens for them. */
final class WorkflowTrigger
{
    /** HROne setup parity P5: payroll events that start approval workflows (when listed in workflows.trigger_events). */
    public const PAYROLL_TRIGGERS = ['payroll.loan_requested', 'payroll.claim_submitted', 'payroll.variable_pay_requested', 'payroll.tax_declaration_submitted'];

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
            ExpenseEvent::class => 'onExpense',
            ImportEvent::class => 'onImport',
            PerformanceEvent::class => 'onPerformance',
            AssetEvent::class => 'onAsset',
            // HROne setup parity P5: payroll requests (loan, claim, variable pay, tax declaration) and compensation changes.
            PayrollEvent::class => 'onPayroll',
            CompensationEvent::class => 'onCompensation',
            // HROne parity MS-3: a self-service profile change (only profile.change_requested starts workflows).
            EmploymentEvent::class => 'onEmployment',
        ];
    }

    /** HROne parity MS-3: a self-service profile change waiting for its route (section in the context). Other employment events start nothing (as before). */
    public function onEmployment(EmploymentEvent $event): void
    {
        if ($event->name === 'profile.change_requested' && array_key_exists($event->name, (array) config('peopleos.workflows.trigger_events'))) {
            $this->fire($event->name, $event->subject, $event->context + ['employee_id' => $event->employee->id]);
        }
    }

    /**
     * HROne setup parity HP-8d / HP-8f: a goal sheet submitted for workflow approval, an improvement plan and its outcome
     * waiting for approval. Other performance events start no workflow (as before).
     */
    public function onPerformance(PerformanceEvent $event): void
    {
        $starts = in_array($event->name, ['performance.pip.requested', 'performance.pip.evaluation_requested'], true)
            || ($event->name === 'performance.goal_plan.submitted' && ($event->context['approval'] ?? null) === 'workflow');
        if ($starts && array_key_exists($event->name, (array) config('peopleos.workflows.trigger_events'))) {
            $this->fire($event->name, $event->subject, $event->context + ['employee_id' => $event->employee?->id]);
        }
    }

    /** HROne setup parity P5: a loan, reimbursement claim, variable pay request or tax declaration waiting for approval. */
    public function onPayroll(PayrollEvent $event): void
    {
        if (in_array($event->name, self::PAYROLL_TRIGGERS, true) && array_key_exists($event->name, (array) config('peopleos.workflows.trigger_events'))) {
            $this->fire($event->name, $event->subject, $event->context);
        }
    }

    /** HROne setup parity P5: a compensation change submitted (waiting for review) or reviewed (waiting for approval). */
    public function onCompensation(CompensationEvent $event): void
    {
        if (in_array($event->name, ['compensation.change.submitted', 'compensation.change.reviewed'], true) && array_key_exists($event->name, (array) config('peopleos.workflows.trigger_events'))) {
            $this->fire($event->name, $event->subject, $event->context + ['employee_id' => $event->employee?->id]);
        }
    }

    public function onExit(ExitEvent $event): void
    {
        // HROne setup parity P5: exit.resignation_submitted (an employee's own resignation, for its approval workflow).
        if (in_array($event->name, ['exit.initiated', 'exit.resignation_submitted', 'letter.requested', 'alumni.request.created'], true)) {
            $this->fire($event->name, $event->subject, $event->context + ['employee_id' => $event->employee?->id]);
        }
    }

    /** HROne setup parity EX-C/EX-D: each submitted report part, the exceptional part, advance requests and cancellations. */
    public function onExpense(ExpenseEvent $event): void
    {
        if (array_key_exists($event->name, (array) config('peopleos.workflows.trigger_events'))) {
            $this->fire($event->name, $event->subject, $event->context + ['employee_id' => $event->employee->id]);
        }
    }

    /** HROne setup parity AS-B: asset requests that need approval (asset.requested / asset.return_requested). */
    public function onAsset(AssetEvent $event): void
    {
        if ($event->employee !== null && in_array($event->name, ['asset.requested', 'asset.return_requested'], true)
            && array_key_exists($event->name, (array) config('peopleos.workflows.trigger_events'))) {
            $this->fire($event->name, $event->subject, $event->context + ['employee_id' => $event->employee->id]);
        }
    }

    /** HROne setup parity P2: an upload under workflow approval was validated (subject: the upload). */
    public function onImport(ImportEvent $event): void
    {
        if ($event->startsWorkflow && array_key_exists($event->name, (array) config('peopleos.workflows.trigger_events'))) {
            $this->fire($event->name, $event->import, $event->context);
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

    /**
     * Start the published workflows listening for the trigger whose start conditions match. HROne setup parity P5
     * (conditional workflow): with workflow.first_match_only on, only the first match starts, tried by priority (lower
     * first, then the oldest); off (the default), every match starts, as before.
     */
    private function fire(string $trigger, Model $subject, array $context): void
    {
        $firstOnly = (bool) app(SettingsRepository::class)->get('workflow.first_match_only', false);

        Workflow::query()
            ->where('trigger_event', $trigger)
            ->where('status', 'active')
            ->whereHas('published')
            ->where(fn ($q) => $q->whereNull('subject_type')->orWhere('subject_type', $subject::class))
            ->when($firstOnly, fn ($q) => $q->orderBy('priority'))
            ->orderBy('id')
            ->get()
            ->each(function (Workflow $workflow) use ($subject, $context, $trigger, $firstOnly) {
                if (! empty($workflow->start_conditions) && ! $this->rules->matches($workflow->start_conditions, $this->contextFor($subject, $context), 'all')) {
                    return true;
                }

                $this->engine->start($workflow, $subject, $context + ['trigger' => $trigger]);

                return ! $firstOnly;
            });
    }

    private function contextFor(Model $subject, array $context): array
    {
        $employee = $this->notificationContext->employeeOf($subject);

        return ($employee ? $this->employeeContext->build($employee) : []) + $context;
    }
}
