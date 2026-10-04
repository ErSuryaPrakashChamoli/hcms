<?php

namespace App\Filament\Resources\WorkflowInstances\Pages;

use App\Domain\Workflow\Exceptions\WorkflowException;
use App\Domain\Workflow\Models\WorkflowInstance;
use App\Domain\Workflow\Services\WorkflowEngine;
use App\Filament\Resources\WorkflowInstances\WorkflowInstanceResource;
use App\Filament\Support\Pages\PeopleViewRecord;
use Filament\Actions\Action;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Support\Icons\Heroicon;

class ViewWorkflowInstance extends PeopleViewRecord
{
    protected static string $resource = WorkflowInstanceResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('cancel')
                ->label('Cancel run')
                ->icon(Heroicon::OutlinedXCircle)
                ->color('danger')
                ->authorize(fn () => auth()->user()->can('workflow.cancel'))
                ->visible(fn (WorkflowInstance $record) => $record->status->isOpen())
                ->requiresConfirmation()
                ->schema([Textarea::make('reason')->required()->maxLength(255)])
                ->action(function (WorkflowInstance $record, array $data) {
                    try {
                        app(WorkflowEngine::class)->cancel($record, $data['reason']);
                        Notification::make()->success()->title('Run cancelled')->send();
                        $this->refreshFormData([]);
                    } catch (WorkflowException $e) {
                        Notification::make()->danger()->title('Not allowed')->body($e->getMessage())->send();
                    }
                }),
        ];
    }
}
