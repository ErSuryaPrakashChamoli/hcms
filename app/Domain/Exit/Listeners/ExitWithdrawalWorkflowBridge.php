<?php

namespace App\Domain\Exit\Listeners;

use App\Domain\Exit\Models\ExitWithdrawalRequest;
use App\Domain\Exit\Services\ExitWithdrawals;
use App\Domain\Identity\Scopes\AccessScope;
use App\Domain\Workflow\Events\WorkflowCompleted;
use App\Domain\Workflow\Support\RequestWorkflows;
use RuntimeException;

/**
 * HROne parity WO-11 (§23.6.2 #3, D-WO8): a workflow that decides a resignation withdrawal request — one running on
 * "Resignation withdrawal request", or on any record and started by `exit.withdrawal_requested` — approves it (the
 * resignation is withdrawn) or rejects it (the exit goes on), in the name of the person who completed its last task.
 */
final class ExitWithdrawalWorkflowBridge
{
    public function handle(WorkflowCompleted $event): void
    {
        $instance = $event->instance;
        if (! in_array($instance->subject_type, [ExitWithdrawalRequest::class, (new ExitWithdrawalRequest)->getMorphClass()], true)) {
            return;
        }
        $subject = ExitWithdrawalRequest::query()->withoutGlobalScope(AccessScope::class)->find($instance->subject_id);
        if (! $subject instanceof ExitWithdrawalRequest || ! $subject->isPending() || ! RequestWorkflows::decides($instance, $subject)) {
            return;
        }
        $actor = RequestWorkflows::lastCompleter($instance);
        if ($actor === null) {
            return;
        }

        try {
            match ($instance->outcome) {
                'approved' => app(ExitWithdrawals::class)->approve($subject, $actor, RequestWorkflows::note($instance), viaWorkflow: true),
                'rejected' => app(ExitWithdrawals::class)->reject($subject, $actor, RequestWorkflows::note($instance) ?: 'Not approved', viaWorkflow: true),
                default => null,
            };
        } catch (RuntimeException) {
            // Decided or closed meanwhile (the exit completed or was withdrawn): the run's log keeps the record.
        }
    }
}
