<?php

namespace App\Filament\Resources\Employees\Pages;

use App\Domain\Attendance\Models\WorkSchedule;
use App\Domain\Attendance\Models\WorkScheduleAssignment;
use App\Domain\Bgv\Services\Bgv;
use App\Domain\Employment\Actions\AssignPositionAction;
use App\Domain\Employment\Actions\ChangeManagerAction;
use App\Domain\Employment\Models\Employee;
use App\Domain\Employment\Models\EmployeeStatutoryDetail;
use App\Domain\Employment\Services\SensitiveAccessAuditor;
use App\Domain\Lifecycle\Enums\LifecycleState;
use App\Domain\Lifecycle\Exceptions\InvalidLifecycleTransitionException;
use App\Domain\Lifecycle\Services\LifecycleEngine;
use App\Domain\Onboarding\Models\OnboardingTemplate;
use App\Domain\Onboarding\Services\Onboarding;
use App\Domain\Workflow\Exceptions\WorkflowException;
use App\Domain\Workflow\Models\Workflow;
use App\Domain\Workflow\Services\WorkflowEngine;
use App\Filament\Resources\Employees\EmployeeResource;
use App\Filament\Resources\WorkflowInstances\WorkflowInstanceResource;
use App\Filament\Support\AuditReasonField;
use App\Filament\Support\SavesCustomFields;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Actions\EditAction;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Infolists\Components\TextEntry;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ViewRecord;
use Filament\Schemas\Components\Grid;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Carbon;
use InvalidArgumentException;

/** Employee 360. Header actions are the life-event entry points (blueprint §20). */
class ViewEmployee extends ViewRecord
{
    use SavesCustomFields;

    protected static string $resource = EmployeeResource::class;

    public function getTitle(): string
    {
        return $this->getRecord()->person->display_name.' · '.$this->getRecord()->employee_code;
    }

    protected function getHeaderActions(): array
    {
        return [
            EditAction::make(),
            ActionGroup::make([
                $this->assignPositionAction(),
                $this->changeManagerAction(),
                $this->lifecycleAction(),
                $this->markJoinedAction(),
                $this->startOnboardingAction(),
                $this->initiateBgvAction(),
                $this->assignScheduleAction(),
                $this->startWorkflowAction(),
            ])->label('Life events')->icon(Heroicon::OutlinedBolt)->button(),
            ActionGroup::make([
                $this->viewStatutoryAction(),
                $this->editStatutoryAction(),
            ])->label('Statutory')->icon(Heroicon::OutlinedLockClosed)->button()->color('gray'),
        ];
    }

    private function assignPositionAction(): Action
    {
        return Action::make('assignPosition')
            ->label('Transfer / promote')
            ->icon(Heroicon::OutlinedArrowTrendingUp)
            ->authorize(fn () => auth()->user()->can('assignPosition', $this->getRecord()))
            ->modalHeading('Assign new position')
            ->modalDescription('Only fill what changes; everything else carries forward. The current position closes the day before.')
            ->fillForm(fn (Employee $record) => ['effective_from' => now()->toDateString(), 'change_type' => 'transfer'])
            ->schema([
                Grid::make(3)->schema([
                    Select::make('change_type')->options(config('peopleos.people.position_change_types'))->required(),
                    DatePicker::make('effective_from')->native(false)->required(),
                ]),
                Grid::make(3)->schema(CreateEmployee::positionFields()),
                AuditReasonField::make()->required(),
            ])
            ->action(function (Employee $record, array $data) {
                $reason = AuditReasonField::extract($data);

                try {
                    app(AssignPositionAction::class)->handle($record, $data, $data['change_type'], $data['effective_from'], $reason);
                    Notification::make()->success()->title('Position assigned')->send();
                } catch (InvalidArgumentException $e) {
                    Notification::make()->danger()->title('Not allowed')->body($e->getMessage())->send();
                }
            });
    }

    private function changeManagerAction(): Action
    {
        return Action::make('changeManager')
            ->label('Change manager')
            ->icon(Heroicon::OutlinedUserCircle)
            ->authorize(fn () => auth()->user()->can('assignPosition', $this->getRecord()))
            ->fillForm(fn () => ['effective_from' => now()->toDateString(), 'type' => 'line'])
            ->schema([
                Select::make('type')->options(config('peopleos.people.reporting_types'))->required(),
                Select::make('manager_id')->label('Manager')->options(fn (Employee $record) => CreateEmployee::managerOptions($record->id))->searchable()->required(),
                DatePicker::make('effective_from')->native(false)->required(),
                AuditReasonField::make(),
            ])
            ->action(function (Employee $record, array $data) {
                $reason = AuditReasonField::extract($data);

                try {
                    app(ChangeManagerAction::class)->handle($record, Employee::query()->findOrFail($data['manager_id']), $data['type'], $data['effective_from'], $reason);
                    Notification::make()->success()->title('Reporting line updated')->send();
                } catch (InvalidArgumentException $e) {
                    Notification::make()->danger()->title('Not allowed')->body($e->getMessage())->send();
                }
            });
    }

    private function lifecycleAction(): Action
    {
        return Action::make('lifecycle')
            ->label('Change lifecycle state')
            ->icon(Heroicon::OutlinedArrowPath)
            ->authorize(fn () => auth()->user()->can('transition', $this->getRecord()))
            ->fillForm(fn () => ['effective_date' => now()->toDateString()])
            ->schema([
                Select::make('to_state')
                    ->label('New state')
                    ->options(fn (Employee $record) => collect($record->lifecycle_state->allowedNext())->mapWithKeys(fn (LifecycleState $s) => [$s->value => $s->getLabel()])->all())
                    ->required(),
                DatePicker::make('effective_date')->native(false)->required(),
                AuditReasonField::make()->required(),
            ])
            ->action(function (Employee $record, array $data) {
                $reason = AuditReasonField::extract($data);

                try {
                    app(LifecycleEngine::class)->transition($record, LifecycleState::from($data['to_state']), $data['effective_date'], $reason);
                    Notification::make()->success()->title('Lifecycle updated')->send();
                } catch (InvalidLifecycleTransitionException $e) {
                    Notification::make()->danger()->title('Not allowed')->body($e->getMessage())->send();
                }
            });
    }

    private function markJoinedAction(): Action
    {
        return Action::make('markJoined')
            ->label('Mark as joined')
            ->icon(Heroicon::OutlinedCheckBadge)
            ->authorize(fn () => auth()->user()->can('transition', $this->getRecord()))
            ->visible(fn (Employee $record) => in_array($record->lifecycle_state, [LifecycleState::PreEmployee, LifecycleState::Preboarding, LifecycleState::Onboarding], true))
            ->fillForm(fn (Employee $record) => ['joining_date' => ($record->expected_joining_date ?? now())->toDateString()])
            ->schema([
                DatePicker::make('joining_date')->native(false)->required(),
                AuditReasonField::make(),
            ])
            ->action(function (Employee $record, array $data) {
                $reason = AuditReasonField::extract($data);

                try {
                    $engine = app(LifecycleEngine::class);
                    $record->withAuditReason($reason)->update(['joining_date' => $data['joining_date']]);
                    $engine->transition($record, LifecycleState::Joined, $data['joining_date'], $reason);
                    $engine->transition($record, LifecycleState::Probation, $data['joining_date'], $reason);
                    Notification::make()->success()->title('Joined')->body('Now in probation.')->send();
                } catch (InvalidLifecycleTransitionException $e) {
                    Notification::make()->danger()->title('Not allowed')->body($e->getMessage())->send();
                }
            });
    }

    private function startOnboardingAction(): Action
    {
        return Action::make('startOnboarding')
            ->label('Start onboarding')
            ->icon(Heroicon::OutlinedClipboardDocumentCheck)
            ->authorize(fn () => auth()->user()->can('onboarding.manage'))
            ->visible(fn (Employee $record) => $record->onboardingPlan()->where('status', 'in_progress')->doesntExist())
            ->schema([
                Select::make('template_id')->label('Template')
                    ->options(fn () => OnboardingTemplate::query()->where('status', 'active')->orderBy('name')->pluck('name', 'id')->all())
                    ->placeholder('Best match by rules')
                    ->helperText('Leave empty to pick the highest-priority template whose conditions match.'),
                AuditReasonField::make(),
            ])
            ->action(function (Employee $record, array $data) {
                try {
                    $template = isset($data['template_id']) ? OnboardingTemplate::query()->find($data['template_id']) : null;
                    $plan = app(Onboarding::class)->start($record, $template, $data[AuditReasonField::NAME] ?? null);
                    Notification::make()->success()->title('Onboarding started')->body($plan->tasks()->count().' tasks created.')->send();
                } catch (\RuntimeException $e) {
                    Notification::make()->danger()->title('Could not start')->body($e->getMessage())->send();
                }
            });
    }

    private function initiateBgvAction(): Action
    {
        return Action::make('initiateBgv')
            ->label('Initiate background check')
            ->icon(Heroicon::OutlinedShieldCheck)
            ->authorize(fn () => auth()->user()->can('bgv.manage'))
            ->visible(fn (Employee $record) => $record->bgvCases()->whereIn('status', ['initiated', 'in_progress'])->doesntExist())
            ->fillForm(['checks' => config('peopleos.bgv.default_checks'), 'provider' => 'manual'])
            ->schema([
                Select::make('provider')->options(collect(config('peopleos.bgv.providers'))->map(fn ($p) => $p['label'])->all())->required(),
                CheckboxList::make('checks')->options(config('peopleos.bgv.check_types'))->columns(2)->required(),
                Toggle::make('consent')->label('The employee has given written consent')->required()->accepted(),
                Select::make('consent_document_id')->label('Consent document')->options(fn (Employee $record) => $record->documents()->pluck('title', 'id')->all())->placeholder('Optional'),
                Textarea::make('notes')->maxLength(1000),
            ])
            ->action(function (Employee $record, array $data) {
                try {
                    $consentDoc = isset($data['consent_document_id']) ? $record->documents()->find($data['consent_document_id']) : null;
                    $case = app(Bgv::class)->initiate($record, $data['checks'], $data['provider'], (bool) $data['consent'], $consentDoc, $data['notes'] ?? null);
                    Notification::make()->success()->title("BGV case #{$case->id} initiated")->send();
                } catch (\RuntimeException $e) {
                    Notification::make()->danger()->title('Could not initiate')->body($e->getMessage())->send();
                }
            });
    }

    private function assignScheduleAction(): Action
    {
        return Action::make('assignSchedule')
            ->label('Assign work schedule')
            ->icon(Heroicon::OutlinedCalendarDays)
            ->authorize(fn () => auth()->user()->can('attendance.manage'))
            ->fillForm(['effective_from' => now()->toDateString()])
            ->schema([
                Select::make('work_schedule_id')->label('Schedule')->options(fn () => WorkSchedule::query()->where('status', 'active')->orderBy('name')->pluck('name', 'id')->all())->required(),
                DatePicker::make('effective_from')->native(false)->required(),
                AuditReasonField::make(),
            ])
            ->action(function (Employee $record, array $data) {
                $reason = AuditReasonField::extract($data);
                $from = Carbon::parse($data['effective_from']);

                $record->scheduleAssignments()->whereNull('effective_to')->where('effective_from', '<', $from->toDateString())->get()
                    ->each(fn (WorkScheduleAssignment $a) => $a->withAuditReason($reason)->update(['effective_to' => $from->copy()->subDay()]));

                $assignment = new WorkScheduleAssignment(['employee_id' => $record->id, 'work_schedule_id' => $data['work_schedule_id'], 'effective_from' => $from, 'reason' => $reason]);
                $assignment->withAuditReason($reason)->save();

                Notification::make()->success()->title('Schedule assigned')->send();
            });
    }

    private function startWorkflowAction(): Action
    {
        return Action::make('startWorkflow')
            ->label('Start workflow')
            ->icon(Heroicon::OutlinedArrowPathRoundedSquare)
            ->authorize(fn () => auth()->user()->can('workflow.run'))
            ->schema([
                Select::make('workflow_id')
                    ->label('Workflow')
                    ->options(fn () => Workflow::query()->where('trigger_event', 'manual')->where('status', 'active')->whereHas('published')
                        ->where(fn ($q) => $q->whereNull('subject_type')->orWhere('subject_type', Employee::class))
                        ->orderBy('name')->pluck('name', 'id')->all())
                    ->required(),
                AuditReasonField::make(),
            ])
            ->action(function (Employee $record, array $data) {
                try {
                    $instance = app(WorkflowEngine::class)->start(Workflow::query()->findOrFail($data['workflow_id']), $record, ['trigger' => 'manual', 'reason' => $data[AuditReasonField::NAME] ?? null]);
                    Notification::make()->success()->title("Run #{$instance->id} started")->send();

                    return redirect()->to(WorkflowInstanceResource::getUrl('view', ['record' => $instance]));
                } catch (WorkflowException $e) {
                    Notification::make()->danger()->title('Could not start')->body($e->getMessage())->send();
                }
            });
    }

    private function viewStatutoryAction(): Action
    {
        return Action::make('viewStatutory')
            ->label('View statutory details')
            ->icon(Heroicon::OutlinedEye)
            ->authorize(fn () => auth()->user()->can('viewSensitive', $this->getRecord()))
            ->mountUsing(fn (Employee $record) => app(SensitiveAccessAuditor::class)->recordView($record, 'statutory'))
            ->modalHeading('Statutory details (this view is audited)')
            ->modalSubmitAction(false)
            ->modalCancelActionLabel('Close')
            ->schema(function (Employee $record) {
                $detail = $record->statutoryDetail;

                return [
                    Grid::make(2)->schema([
                        TextEntry::make('pan')->label('PAN')->state($detail?->pan)->placeholder('—'),
                        TextEntry::make('aadhaar_reference')->label('Aadhaar reference')->state($detail?->aadhaar_reference)->placeholder('—'),
                        TextEntry::make('uan')->label('UAN')->state($detail?->uan)->placeholder('—'),
                        TextEntry::make('pf_number')->label('PF number')->state($detail?->pf_number)->placeholder('—'),
                        TextEntry::make('esic_number')->label('ESIC number')->state($detail?->esic_number)->placeholder('—'),
                        TextEntry::make('tax_regime')->state($detail?->tax_regime)->placeholder('—'),
                        TextEntry::make('pf_applicable')->state($detail ? ($detail->pf_applicable ? 'Yes' : 'No') : null)->placeholder('—'),
                        TextEntry::make('esic_applicable')->state($detail ? ($detail->esic_applicable ? 'Yes' : 'No') : null)->placeholder('—'),
                        TextEntry::make('pt_applicable')->state($detail ? ($detail->pt_applicable ? 'Yes' : 'No') : null)->placeholder('—'),
                        TextEntry::make('pt_state_code')->label('PT state')->state($detail?->pt_state_code)->placeholder('—'),
                    ]),
                ];
            });
    }

    private function editStatutoryAction(): Action
    {
        return Action::make('editStatutory')
            ->label('Edit statutory details')
            ->icon(Heroicon::OutlinedPencilSquare)
            ->authorize(fn () => auth()->user()->can('updateSensitive', $this->getRecord()))
            ->mountUsing(function (Employee $record, $schema) {
                app(SensitiveAccessAuditor::class)->recordView($record, 'statutory', 'edit');
                $schema->fill($record->statutoryDetail?->only(['pan', 'aadhaar_reference', 'uan', 'pf_number', 'esic_number', 'pf_applicable', 'esic_applicable', 'pt_applicable', 'tax_regime', 'pt_state_code']) ?? ['pf_applicable' => true, 'pt_applicable' => true]);
            })
            ->schema([
                Grid::make(2)->schema([
                    TextInput::make('pan')->label('PAN')->maxLength(10)->regex('/^[A-Z]{5}[0-9]{4}[A-Z]$/')->helperText('Format ABCDE1234F'),
                    TextInput::make('aadhaar_reference')->label('Aadhaar reference')->maxLength(16)->helperText('Store a reference / last digits, not the full number, unless policy allows.'),
                    TextInput::make('uan')->label('UAN')->maxLength(12),
                    TextInput::make('pf_number')->label('PF number')->maxLength(32),
                    TextInput::make('esic_number')->label('ESIC number')->maxLength(32),
                    Select::make('tax_regime')->options(['old' => 'Old regime', 'new' => 'New regime']),
                    TextInput::make('pt_state_code')->label('PT state')->maxLength(16),
                    Toggle::make('pf_applicable')->label('PF applicable'),
                    Toggle::make('esic_applicable')->label('ESIC applicable'),
                    Toggle::make('pt_applicable')->label('PT applicable'),
                ]),
                AuditReasonField::make()->required(),
            ])
            ->action(function (Employee $record, array $data) {
                $reason = AuditReasonField::extract($data);
                $detail = $record->statutoryDetail ?? new EmployeeStatutoryDetail(['employee_id' => $record->id]);
                $detail->fill($data)->withAuditReason($reason)->save();

                Notification::make()->success()->title('Statutory details saved')->send();
            });
    }
}
