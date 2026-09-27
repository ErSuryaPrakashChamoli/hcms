<?php

namespace App\Domain\Enterprise\Services;

use App\Domain\Assets\Events\AssetEvent;
use App\Domain\Attendance\Events\AttendanceEvent;
use App\Domain\Documents\Events\DocumentExpiring;
use App\Domain\Exit\Events\ExitEvent;
use App\Domain\Learning\Events\LearningEvent;
use App\Domain\Leave\Events\LeaveEvent;
use App\Domain\Lifecycle\Events\EmployeeLifecycleChanged;
use App\Domain\Payroll\Events\PayrollEvent;
use App\Domain\Performance\Events\PerformanceEvent;
use App\Domain\ServiceDesk\Events\ServiceDeskEvent;
use App\Domain\Workflow\Events\WorkflowCompleted;
use Illuminate\Events\Dispatcher;

/** Domain events → outbound webhooks (§88). Payloads carry identifiers and business fields, never sensitive detail. */
final class WebhookEventBridge
{
    public function __construct(private readonly Webhooks $webhooks) {}

    public function subscribe(Dispatcher $events): array
    {
        return [
            EmployeeLifecycleChanged::class => 'onLifecycle',
            LeaveEvent::class => 'onNamed', AttendanceEvent::class => 'onNamed', PayrollEvent::class => 'onNamed', PerformanceEvent::class => 'onNamed',
            LearningEvent::class => 'onNamed', AssetEvent::class => 'onNamed', ServiceDeskEvent::class => 'onNamed', ExitEvent::class => 'onNamed',
            WorkflowCompleted::class => 'onWorkflowCompleted', DocumentExpiring::class => 'onDocumentExpiring',
        ];
    }

    public function onLifecycle(EmployeeLifecycleChanged $event): void
    {
        $this->webhooks->publish('employee.'.$event->to->value, ['employee_id' => $event->employee->id, 'employee_code' => $event->employee->employee_code, 'from' => $event->from?->value, 'to' => $event->to->value, 'effective_date' => $event->effectiveDate->toDateString()], $event->employee);
    }

    public function onNamed(object $event): void
    {
        $name = $event->name ?? null;
        if ($name === null || ! in_array($name, config('peopleos.enterprise.webhook_events', []), true)) {
            return;
        }
        $context = $event->context ?? [];
        $employee = $event->employee ?? null;
        $this->webhooks->publish($name, array_merge(['employee_id' => $employee?->id, 'employee_code' => $employee?->employee_code], array_filter($context, fn ($v) => is_scalar($v) || $v === null)), $event->subject ?? null);
    }

    public function onWorkflowCompleted(WorkflowCompleted $event): void
    {
        $instance = $event->instance ?? null;
        $this->webhooks->publish('workflow.completed', ['instance_id' => $instance?->id, 'workflow' => $instance?->workflow?->key, 'outcome' => $instance?->outcome], $instance);
    }

    public function onDocumentExpiring(DocumentExpiring $event): void
    {
        $document = $event->document ?? null;
        $this->webhooks->publish('document.expiring', ['document_id' => $document?->id, 'employee_id' => $document?->employee_id, 'expires_on' => $document?->expires_on?->toDateString()], $document);
    }
}
