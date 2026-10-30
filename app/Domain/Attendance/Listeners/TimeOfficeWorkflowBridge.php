<?php

namespace App\Domain\Attendance\Listeners;

use App\Domain\Attendance\Models\LocationRequest;
use App\Domain\Attendance\Models\TimeOfficeRequest;
use App\Domain\Attendance\Services\GeoLocations;
use App\Domain\Attendance\Services\TimeOfficeRequests;
use App\Domain\Identity\Models\User;
use App\Domain\Workflow\Events\WorkflowCompleted;
use App\Domain\Workflow\Models\WorkflowTask;
use RuntimeException;

/**
 * HP-1: when a workflow on a time-office or location request ends, its outcome decides the request, in the
 * name of the person who completed its last task. With nobody to decide in, the request stays pending and
 * goes back to the Approval Center.
 */
final class TimeOfficeWorkflowBridge
{
    public function handle(WorkflowCompleted $event): void
    {
        $instance = $event->instance;
        $subject = $instance->subject;

        if (! $subject instanceof TimeOfficeRequest && ! $subject instanceof LocationRequest) {
            return;
        }

        $actorId = WorkflowTask::query()->where('workflow_instance_id', $instance->id)->whereNotNull('completed_by')->orderByDesc('completed_at')->orderByDesc('id')->value('completed_by');
        $actor = $actorId ? User::query()->find($actorId) : null;
        if ($actor === null) {
            return;
        }
        $note = 'Workflow "'.$instance->workflow->name.'" run #'.$instance->id.($instance->contextValue('last_note') ? ': '.$instance->contextValue('last_note') : '');

        try {
            if ($subject instanceof TimeOfficeRequest) {
                match (true) {
                    $instance->outcome === 'approved' && $subject->status === 'pending' => app(TimeOfficeRequests::class)->approve($subject, $note, $actor),
                    $instance->outcome === 'rejected' && $subject->status === 'pending' => app(TimeOfficeRequests::class)->reject($subject, $note, $actor),
                    $instance->outcome === 'approved' && $subject->status === 'cancel_requested' => app(TimeOfficeRequests::class)->approveCancellation($subject, $note, $actor),
                    $instance->outcome === 'rejected' && $subject->status === 'cancel_requested' => app(TimeOfficeRequests::class)->rejectCancellation($subject, $note, $actor),
                    default => null,
                };

                return;
            }

            if ($subject->status === 'pending') {
                match ($instance->outcome) {
                    'approved' => app(GeoLocations::class)->approve($subject, $note, $actor),
                    'rejected' => app(GeoLocations::class)->reject($subject, $note, $actor),
                    default => null,
                };
            }
        } catch (RuntimeException) {
            // Decided by a person meanwhile, or refused by its rules: the workflow log keeps the record.
        }
    }
}
