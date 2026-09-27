<?php

namespace App\Filament\Support;

use App\Domain\Workflow\Enums\TaskStatus;
use App\Domain\Workflow\Exceptions\WorkflowException;
use App\Domain\Workflow\Models\WorkflowTask;
use App\Domain\Workflow\Services\WorkflowEngine;
use Filament\Actions\Action;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;

/** Approve / reject / complete actions shared by the inbox and the run viewer. */
final class TaskActions
{
    /** @return array<int, Action> */
    public static function forTable(): array
    {
        $can = fn (WorkflowTask $record) => $record->status === TaskStatus::Pending && auth()->user()->can('act', $record);

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
        ];
    }

    private static function decide(WorkflowTask $task, string $decision, ?string $note): void
    {
        try {
            $instance = app(WorkflowEngine::class)->completeTask($task, $decision, $note);
            Notification::make()->success()->title(ucfirst($decision))->body("Run #{$instance->id} is now {$instance->status->getLabel()}.")->send();
        } catch (WorkflowException $e) {
            Notification::make()->danger()->title('Not allowed')->body($e->getMessage())->send();
        }
    }
}
