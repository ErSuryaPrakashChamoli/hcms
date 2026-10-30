<?php

namespace App\Filament\Support;

use App\Domain\Experience\Support\InboxActions;
use App\Domain\Identity\Enums\UserStatus;
use App\Domain\Identity\Models\Role;
use App\Domain\Identity\Models\User;
use App\Domain\Workflow\Enums\TaskStatus;
use App\Domain\Workflow\Exceptions\WorkflowException;
use App\Domain\Workflow\Models\WorkflowTask;
use App\Domain\Workflow\Services\RequestReturns;
use App\Domain\Workflow\Services\TaskComments;
use App\Domain\Workflow\Services\TaskDelegations;
use App\Domain\Workflow\Services\WorkflowEngine;
use Filament\Actions\Action;
use Filament\Forms\Components\Radio;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Utilities\Get;
use Illuminate\Auth\Access\AuthorizationException;
use InvalidArgumentException;
use RuntimeException;

/** Approve / reject / complete actions shared by the inbox and the run viewer (HROne setup parity P3: send back, reassign, discussion). */
final class TaskActions
{
    /** @return array<int, Action> */
    public static function forTable(): array
    {
        $own = fn (WorkflowTask $record) => $record->status === TaskStatus::Pending && auth()->user()->can('act', $record);
        // HROne setup parity P4: also the tasks of a colleague who is out of office and delegated them to this person.
        $can = fn (WorkflowTask $record) => $own($record) || ($record->status === TaskStatus::Pending && app(TaskDelegations::class)->forTask($record, auth()->user()) !== null);
        $starter = fn (WorkflowTask $record) => $record->instance()->value('started_by');

        return [
            Action::make('approve')->label('Approve')->icon('heroicon-m-check')->color('success')
                ->visible(fn (WorkflowTask $record) => $record->type === 'approval' && $can($record))
                ->schema([Textarea::make('note')->maxLength(255)])
                ->action(fn (WorkflowTask $record, array $data) => self::decide($record, 'approved', $data['note'] ?? null)),
            Action::make('reject')->label('Reject')->icon('heroicon-m-x-mark')->color('danger')
                ->visible(fn (WorkflowTask $record) => $record->type === 'approval' && $can($record))
                ->schema([Textarea::make('note')->required()->maxLength(255)])
                ->action(fn (WorkflowTask $record, array $data) => self::decide($record, 'rejected', $data['note'])),
            Action::make('complete')->label('Mark done')->icon('heroicon-m-check-circle')->color('success')
                ->visible(fn (WorkflowTask $record) => $record->type === 'task' && $can($record))
                ->schema([Textarea::make('note')->maxLength(255)])
                ->action(fn (WorkflowTask $record, array $data) => self::decide($record, 'completed', $data['note'] ?? null)),
            // HROne setup parity P3 (Send back): back to the person who started the run, when switched on for workflow steps.
            Action::make('sendBack')->label('Send back')->icon('heroicon-m-arrow-uturn-left')->color('warning')
                ->visible(fn (WorkflowTask $record) => $record->type === 'approval' && InboxActions::enabled('send_back', 'workflow_task') && $own($record)
                    && ($by = $starter($record)) !== null && (int) $by !== (int) auth()->id())
                ->schema([Textarea::make('note')->label('What should the requester change?')->required()->maxLength(2000)])
                ->action(fn (WorkflowTask $record, array $data) => self::guard(fn () => app(RequestReturns::class)->sendBackTask($record, auth()->user(), (string) $data['note']), 'Sent back to the requester')),
            // HROne setup parity P3 (Reassign): holders of task.reassign move a pending task to a person or a role.
            Action::make('reassign')->label('Reassign')->icon('heroicon-m-arrow-path-rounded-square')->color('gray')
                ->visible(fn (WorkflowTask $record) => $record->status === TaskStatus::Pending && auth()->user()->can('update', $record))
                ->schema([
                    Radio::make('to')->label('Reassign to')->options(['user' => 'A person', 'role' => 'Everyone holding a role'])->default('user')->required()->live(),
                    Select::make('user_id')->label('Person')->searchable()->native(false)
                        ->options(fn () => User::forCurrentTenant()->where('status', UserStatus::Active->value)->where('is_platform_admin', false)->orderBy('name')->pluck('name', 'id')->all())
                        ->visible(fn (Get $get) => $get('to') === 'user')->required(fn (Get $get) => $get('to') === 'user'),
                    Select::make('role_id')->label('Role')->native(false)->options(fn () => Role::query()->orderBy('name')->pluck('name', 'id')->all())
                        ->visible(fn (Get $get) => $get('to') === 'role')->required(fn (Get $get) => $get('to') === 'role'),
                    Textarea::make('reason')->label('Reason')->required()->maxLength(255),
                ])
                ->action(fn (WorkflowTask $record, array $data) => self::guard(fn () => app(WorkflowEngine::class)->reassign(
                    $record,
                    ($data['to'] ?? 'user') === 'user' ? User::forCurrentTenant()->find($data['user_id'] ?? 0) : null,
                    ($data['to'] ?? 'user') === 'role' ? Role::query()->find($data['role_id'] ?? 0) : null,
                    (string) $data['reason'],
                    auth()->user(),
                ), 'Task reassigned')),
            // HROne setup parity P3 (Discussion): the run's conversation, for its requester, approvers and task.view_all.
            Action::make('discussion')->label('Discussion')->icon('heroicon-m-chat-bubble-left-right')->color('gray')
                ->visible(fn (WorkflowTask $record) => InboxActions::enabled('discussion', 'workflow_task'))
                ->modalHeading(fn (WorkflowTask $record) => 'Discussion: '.$record->title)
                ->modalContent(fn (WorkflowTask $record) => view('filament.workflow.discussion-thread', ['comments' => ($run = $record->instance()->first()) && app(TaskComments::class)->mayView(auth()->user(), $run, 'workflow_task')
                    ? app(TaskComments::class)->thread($run) : collect()]))
                ->schema([Textarea::make('body')->label('Message')->required()->maxLength(2000)])
                ->modalSubmitActionLabel('Post message')
                ->action(fn (WorkflowTask $record, array $data) => self::guard(fn () => app(TaskComments::class)->reply(auth()->user(), $record->instance()->firstOrFail(), 'workflow_task', (string) $data['body']), 'Message posted')),
        ];
    }

    private static function decide(WorkflowTask $task, string $decision, ?string $note): void
    {
        $user = auth()->user();
        try {
            // HROne setup parity P4: a delegated task is decided in the delegator's stead (their authority, audited as the delegate).
            $delegation = $user->can('act', $task) ? null : app(TaskDelegations::class)->forTask($task, $user);
            $instance = $delegation !== null
                ? app(TaskDelegations::class)->actFor($delegation, $user, fn (User $delegator) => app(WorkflowEngine::class)->completeTask($task, $decision, $note, $delegator))
                : app(WorkflowEngine::class)->completeTask($task, $decision, $note);
            Notification::make()->success()->title(ucfirst($decision))->body("Run #{$instance->id} is now {$instance->status->getLabel()}.".($delegation !== null ? ' Decided on behalf of '.$delegation->delegator->name.'.' : ''))->send();
        } catch (WorkflowException $e) {
            Notification::make()->danger()->title('Not allowed')->body($e->getMessage())->send();
        }
    }

    private static function guard(callable $callback, string $success): void
    {
        try {
            $callback();
            Notification::make()->success()->title($success)->send();
        } catch (WorkflowException|AuthorizationException|InvalidArgumentException|RuntimeException $e) {
            Notification::make()->danger()->title('Not allowed')->body($e->getMessage())->send();
        }
    }
}
