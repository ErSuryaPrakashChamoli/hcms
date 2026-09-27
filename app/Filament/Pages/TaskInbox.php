<?php

namespace App\Filament\Pages;

use App\Domain\Onboarding\Models\OnboardingTask;
use App\Domain\Workflow\Enums\TaskStatus;
use App\Domain\Workflow\Models\WorkflowTask;
use App\Filament\Resources\WorkflowInstances\WorkflowInstanceResource;
use App\Filament\Support\OnboardingTaskActions;
use App\Filament\Support\TaskActions;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Pages\Page;
use Filament\Schemas\Components\Tabs\Tab;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use UnitEnum;

/** "Needs attention" for approvals and tasks (§57): what is waiting for me. */
class TaskInbox extends Page implements HasTable
{
    use InteractsWithTable;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedInbox;

    protected static string|UnitEnum|null $navigationGroup = 'Workflows';

    protected static ?string $navigationLabel = 'Task inbox';

    protected static ?string $title = 'My tasks';

    protected static ?int $navigationSort = 5;

    protected string $view = 'filament.pages.task-inbox';

    public ?string $activeTab = 'mine';

    public static function canAccess(): bool
    {
        return auth()->user()?->can('task.view') || auth()->user()?->can('task.view_all');
    }

    public static function getNavigationBadge(): ?string
    {
        $user = auth()->user();

        if ($user === null) {
            return null;
        }

        $count = WorkflowTask::query()->where('status', TaskStatus::Pending)->actionableBy($user)->count()
            + OnboardingTask::query()->where('status', 'pending')->actionableBy($user)->count();

        return $count > 0 ? (string) $count : null;
    }

    public function table(Table $table): Table
    {
        if ($this->activeTab === 'onboarding') {
            return $this->onboardingTable($table);
        }

        return $table
            ->query(fn (): Builder => $this->baseQuery())
            ->columns([
                TextColumn::make('title')->weight('medium')->wrap()->description(fn (WorkflowTask $record) => $record->instructions),
                TextColumn::make('instance.workflow.name')->label('Workflow'),
                TextColumn::make('instance.subject_label')->label('About')->placeholder('—'),
                TextColumn::make('type')->badge()->color('gray'),
                TextColumn::make('assignee')->label('Assigned to')->state(fn (WorkflowTask $record) => $record->assigneeLabel())->visible(fn () => $this->activeTab === 'all'),
                TextColumn::make('due_at')->dateTime()->placeholder('—')->sortable()->color(fn (WorkflowTask $record) => $record->isOverdue() ? 'danger' : null),
                TextColumn::make('status')->badge(),
                TextColumn::make('created_at')->label('Raised')->since()->sortable(),
            ])
            ->defaultSort('due_at')
            ->recordActions([
                ...TaskActions::forTable(),
                Action::make('open')->label('Open run')->icon('heroicon-m-arrow-top-right-on-square')->color('gray')
                    ->url(fn (WorkflowTask $record) => WorkflowInstanceResource::getUrl('view', ['record' => $record->workflow_instance_id]))
                    ->visible(fn () => auth()->user()->can('workflow.view')),
            ])
            ->emptyStateHeading('Nothing needs your attention')
            ->emptyStateDescription('Approvals and tasks assigned to you, or to one of your roles, show up here.');
    }

    private function onboardingTable(Table $table): Table
    {
        $user = auth()->user();

        return $table
            ->query(fn (): Builder => OnboardingTask::query()->with(['plan.employee.person', 'owner', 'ownerRole'])->where('status', 'pending')->actionableBy($user))
            ->columns([
                TextColumn::make('title')->weight('medium')->wrap()->description(fn (OnboardingTask $record) => $record->description),
                TextColumn::make('plan.employee.person.display_name')->label('For'),
                TextColumn::make('phase')->badge()->color('gray')->formatStateUsing(fn (string $state) => config("peopleos.onboarding.phases.{$state}.label", $state)),
                TextColumn::make('type')->badge()->formatStateUsing(fn (string $state) => config("peopleos.onboarding.item_types.{$state}", $state)),
                TextColumn::make('due_on')->date()->placeholder('—')->sortable()->color(fn (OnboardingTask $record) => $record->isOverdue() ? 'danger' : null),
            ])
            ->defaultSort('due_on')
            ->recordActions(OnboardingTaskActions::forTable())
            ->emptyStateHeading('No onboarding tasks for you');
    }

    public function getTabs(): array
    {
        $tabs = [
            'mine' => Tab::make('Waiting for me'),
            'onboarding' => Tab::make('Onboarding tasks'),
            'done' => Tab::make('Handled by me'),
        ];

        if (auth()->user()->can('task.view_all')) {
            $tabs['all'] = Tab::make('All open tasks');
        }

        return $tabs;
    }

    public function setTab(string $tab): void
    {
        $this->activeTab = $tab;
        $this->resetTable();
    }

    private function baseQuery(): Builder
    {
        $user = auth()->user();
        $query = WorkflowTask::query()->with(['instance.workflow', 'assignee', 'assigneeRole']);

        return match ($this->activeTab) {
            'done' => $query->where('completed_by', $user->id),
            'all' => $user->can('task.view_all') ? $query->where('status', TaskStatus::Pending) : $query->whereRaw('1 = 0'),
            default => $query->where('status', TaskStatus::Pending)->actionableBy($user),
        };
    }
}
