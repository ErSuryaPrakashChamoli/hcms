<?php

namespace App\Filament\Resources\Workflows\Pages;

use App\Domain\Employment\Models\Employee;
use App\Domain\Workflow\Exceptions\WorkflowException;
use App\Domain\Workflow\Models\Workflow;
use App\Domain\Workflow\Services\WorkflowEngine;
use App\Domain\Workflow\Services\Workflows;
use App\Domain\Workflow\Services\WorkflowWebhookSigning;
use App\Filament\Resources\Employees\Pages\CreateEmployee;
use App\Filament\Resources\WorkflowInstances\WorkflowInstanceResource;
use App\Filament\Resources\Workflows\WorkflowResource;
use App\Filament\Support\AuditReasonField;
use App\Filament\Support\GovernedEdit;
use App\Filament\Support\Pages\PeopleEditRecord;
use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Forms\Components\Select;
use Filament\Notifications\Notification;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\HtmlString;

class EditWorkflow extends PeopleEditRecord
{
    use GovernedEdit;

    protected static string $resource = WorkflowResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('publish')
                ->label('Publish draft')
                ->icon(Heroicon::OutlinedRocketLaunch)
                ->color('success')
                ->authorize(fn () => auth()->user()->can('workflow.publish'))
                ->visible(fn (Workflow $record) => $record->draft()->exists())
                ->requiresConfirmation()
                ->modalDescription('The draft is validated and becomes the live version. Running instances keep the version they started with.')
                ->schema([AuditReasonField::make()])
                ->action(function (Workflow $record, array $data) {
                    try {
                        $version = app(Workflows::class)->publish($record, $data[AuditReasonField::NAME] ?? null);
                        Notification::make()->success()->title("Published v{$version->version}")->send();
                    } catch (WorkflowException $e) {
                        Notification::make()->danger()->title('Cannot publish')->body($e->getMessage())->persistent()->send();
                    }
                }),
            Action::make('newDraft')
                ->label('New draft')
                ->icon(Heroicon::OutlinedDocumentPlus)
                ->visible(fn (Workflow $record) => $record->draft()->doesntExist())
                ->action(function (Workflow $record) {
                    $draft = app(Workflows::class)->draft($record);
                    Notification::make()->success()->title("Draft v{$draft->version} created")->send();
                }),
            Action::make('run')
                ->label('Start a run')
                ->icon(Heroicon::OutlinedPlay)
                ->authorize(fn () => auth()->user()->can('workflow.run'))
                ->visible(fn (Workflow $record) => $record->published()->exists())
                ->schema(fn (Workflow $record) => $record->subject_type === Employee::class || $record->subject_type === null ? [
                    Select::make('employee_id')->label('Employee')->options(fn () => CreateEmployee::managerOptions())->searchable()->required($record->subject_type !== null),
                ] : [])
                ->action(function (Workflow $record, array $data) {
                    try {
                        $subject = isset($data['employee_id']) ? Employee::query()->findOrFail($data['employee_id']) : null;
                        $instance = app(WorkflowEngine::class)->start($record, $subject, ['trigger' => 'manual']);
                        Notification::make()->success()->title("Run #{$instance->id} started")->body("Status: {$instance->status->getLabel()}")->send();

                        return redirect()->to(WorkflowInstanceResource::getUrl('view', ['record' => $instance]));
                    } catch (WorkflowException $e) {
                        Notification::make()->danger()->title('Could not start')->body($e->getMessage())->send();
                    }
                }),
            // SaaS.2: the secret webhook receivers use to verify X-PeopleOS-Signature. Opening it is audited.
            Action::make('webhookSigningSecret')
                ->label('Webhook signing secret')
                ->icon(Heroicon::OutlinedKey)
                ->color('gray')
                ->authorize(fn () => auth()->user()->can('workflow.update'))
                ->modalHeading('Webhook signing secret')
                ->modalDescription('Receivers verify X-PeopleOS-Signature: sha256 HMAC of "<X-PeopleOS-Timestamp>.<raw body>" with this secret. Refuse timestamps older than 5 minutes and drop repeated X-PeopleOS-Delivery ids.')
                ->modalContent(fn (Workflow $record) => new HtmlString('<code class="pos-secret" style="word-break:break-all">'.e(app(WorkflowWebhookSigning::class)->reveal($record, auth()->user())).'</code>'))
                ->modalSubmitAction(false)
                ->modalCancelActionLabel('Close'),
            Action::make('rotateWebhookSigningSecret')
                ->label('Rotate signing secret')
                ->icon(Heroicon::OutlinedArrowPath)
                ->color('danger')
                ->authorize(fn () => auth()->user()->can('workflow.update'))
                ->requiresConfirmation()
                ->modalDescription('The old secret stops working at once. Update every receiver with the new secret.')
                ->schema([AuditReasonField::make()->required()])
                ->action(function (Workflow $record, array $data) {
                    app(WorkflowWebhookSigning::class)->rotate($record, auth()->user(), (string) ($data[AuditReasonField::NAME] ?? ''));
                    Notification::make()->success()->title('Signing secret rotated')->body('Open "Webhook signing secret" to copy the new one.')->send();
                }),
            DeleteAction::make(),
        ];
    }
}
