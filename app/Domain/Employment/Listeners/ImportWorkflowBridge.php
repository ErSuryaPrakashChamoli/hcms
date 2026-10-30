<?php

namespace App\Domain\Employment\Listeners;

use App\Domain\Employment\Events\ImportEvent;
use App\Domain\Employment\Imports\EmployeeImport;
use App\Domain\Employment\Imports\ImportTypes;
use App\Domain\Identity\Models\User;
use App\Domain\Workflow\Events\WorkflowCompleted;
use App\Domain\Workflow\Models\WorkflowTask;
use RuntimeException;

/**
 * HROne setup parity P2 (Import approval → workflow): when a workflow on an upload (`import.validated`, subject the
 * upload) ends, an approved run approves the upload in the name of the person who completed its last task, through the
 * upload type's own service (its rules apply again: counts, permissions for bank and salary uploads, never the
 * uploader); a rejected run discards it (the uploader uploads a corrected file). With nobody to decide in, or a refusal,
 * the upload stays validated and another person approves it by hand.
 */
final class ImportWorkflowBridge
{
    public function handle(WorkflowCompleted $event): void
    {
        $instance = $event->instance;
        $import = $instance->subject;
        if (! $import instanceof EmployeeImport || $import->status !== 'validated') {
            return;
        }
        $actorId = WorkflowTask::query()->where('workflow_instance_id', $instance->id)->whereNotNull('completed_by')->orderByDesc('completed_at')->orderByDesc('id')->value('completed_by');
        $actor = $actorId ? User::query()->find($actorId) : null;
        if ($actor === null || (int) $actor->getKey() === (int) $import->uploaded_by) {
            return;
        }
        $note = 'Workflow "'.$instance->workflow->name.'" run #'.$instance->id.($instance->contextValue('last_note') ? ': '.$instance->contextValue('last_note') : '');
        $service = ImportTypes::service((string) $import->type);

        try {
            if ($instance->outcome === 'approved') {
                $service->approve($import, $actor, $note);
            } elseif ($instance->outcome === 'rejected') {
                $service->discard($import, $note);
                ImportEvent::dispatch('import.rejected', $import->refresh(), ['type' => $import->type, 'type_label' => ImportTypes::label((string) $import->type), 'file' => $import->original_name, 'note' => $instance->contextValue('last_note')], [(int) $import->uploaded_by]);
            }
        } catch (RuntimeException) {
            // Refused by the upload's own rules (e.g. the approver may not work on bank details): it stays validated and
            // waits for a person; the workflow log keeps the run.
        }
    }
}
