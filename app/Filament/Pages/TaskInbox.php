<?php

namespace App\Filament\Pages;

use App\Domain\Experience\Services\ApprovalCenter;
use App\Domain\Experience\Support\InboxActions;
use App\Domain\Identity\Models\User;
use App\Domain\Onboarding\Models\OnboardingTask;
use App\Domain\Workflow\Enums\TaskStatus;
use App\Domain\Workflow\Models\RequestReturn;
use App\Domain\Workflow\Models\WorkflowTask;
use App\Domain\Workflow\Services\InboxArchives;
use App\Domain\Workflow\Services\RequestReturns;
use App\Domain\Workflow\Services\TaskDelegations;
use App\Filament\Resources\WorkflowInstances\WorkflowInstanceResource;
use App\Filament\Support\OnboardingTaskActions;
use App\Filament\Support\TaskActions;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Tabs\Tab;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Str;
use RuntimeException;
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

    /** HROne setup parity P3: a notification about a request sent back opens the "Sent back to me" tab (?tab=returned). */
    public function mount(): void
    {
        abort_unless(static::canAccess(), 403);
        if (request()->query('tab') === 'returned') {
            $this->activeTab = 'returned';
        }
    }

    public static function getNavigationBadge(): ?string
    {
        $user = auth()->user();

        if ($user === null) {
            return null;
        }

        $count = self::waitingFor(WorkflowTask::query(), $user)->count()
            + OnboardingTask::query()->where('status', 'pending')->actionableBy($user)->count();

        return $count > 0 ? (string) $count : null;
    }

    public function table(Table $table): Table
    {
        if ($this->activeTab === 'onboarding') {
            return $this->onboardingTable($table);
        }
        if ($this->activeTab === 'returned') {
            return $this->returnedTable($table);
        }
        if ($this->activeTab === 'archived') {
            return $this->archivedTable($table);
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
                // HROne setup parity P4 (Move to archive): hide a waiting task from this inbox (it stays pending).
                Action::make('archive')->label('Archive')->icon('heroicon-m-archive-box')->color('gray')
                    ->visible(fn (WorkflowTask $record) => $this->activeTab === 'mine' && $record->status === TaskStatus::Pending && in_array('workflow_task', app(InboxArchives::class)->manualKinds(), true))
                    ->action(function (WorkflowTask $record) {
                        $item = app(ApprovalCenter::class)->find(auth()->user(), 'workflow_task:'.$record->id);
                        try {
                            $item !== null ? app(InboxArchives::class)->archive(auth()->user(), $item) : throw new RuntimeException('This task is no longer waiting for you.');
                            Notification::make()->success()->title('Archived')->send();
                        } catch (AuthorizationException|RuntimeException $e) {
                            Notification::make()->danger()->title('Not archived')->body($e->getMessage())->send();
                        }
                    }),
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

    /**
     * HROne setup parity P3 (Send back): requests an approver returned to this person — workflow runs they started and
     * their own leave, time office, loan and claim requests — to answer and resubmit, or to discuss first.
     */
    private function returnedTable(Table $table): Table
    {
        $user = auth()->user();
        $kinds = ApprovalCenter::kinds();

        return $table
            ->query(fn (): Builder => RequestReturn::query()->with('returner')->whereKey(app(RequestReturns::class)->waitingFor($user)->modelKeys()))
            ->columns([
                TextColumn::make('kind')->label('Request')->formatStateUsing(fn (string $state) => $kinds[$state] ?? $state)->description(fn (RequestReturn $record) => $record->subject_label)->wrap(),
                TextColumn::make('returner.name')->label('Sent back by'),
                TextColumn::make('note')->label('What to change')->wrap(),
                TextColumn::make('returned_at')->label('Sent back')->since()->sortable(),
            ])
            ->defaultSort('returned_at', 'desc')
            ->recordActions([
                Action::make('resubmit')->label('Resubmit')->icon('heroicon-m-arrow-uturn-right')->color('success')
                    ->schema([Textarea::make('response')->label('Your answer (the approver sees it)')->maxLength(2000)])
                    ->action(function (RequestReturn $record, array $data) {
                        try {
                            app(RequestReturns::class)->resubmit($record, auth()->user(), $data['response'] ?? null);
                            Notification::make()->success()->title('Resubmitted')->send();
                        } catch (AuthorizationException|RuntimeException $e) {
                            Notification::make()->danger()->title('Not resubmitted')->body($e->getMessage())->send();
                        }
                    }),
                Action::make('discussion')->label('Discussion')->icon('heroicon-m-chat-bubble-left-right')->color('gray')
                    ->visible(fn (RequestReturn $record) => InboxActions::enabled('discussion', $record->kind))
                    ->url(fn (RequestReturn $record) => RequestDiscussion::getUrl(['return' => $record->id])),
            ])
            ->emptyStateHeading('Nothing was sent back to you')
            ->emptyStateDescription('When an approver sends a request of yours back for changes, it waits here until you answer and resubmit it.');
    }

    /** HROne setup parity P4: workflow tasks the person archived that still wait, with Restore. */
    private function archivedTable(Table $table): Table
    {
        $ids = self::archivedTaskIds(auth()->user());

        return $table
            ->query(fn (): Builder => WorkflowTask::query()->with(['instance.workflow'])->where('status', TaskStatus::Pending)->whereIn('id', $ids === [] ? [0] : $ids))
            ->columns([
                TextColumn::make('title')->weight('medium')->wrap(),
                TextColumn::make('instance.workflow.name')->label('Workflow'),
                TextColumn::make('instance.subject_label')->label('About')->placeholder('—'),
                TextColumn::make('due_at')->dateTime()->placeholder('—')->sortable(),
                TextColumn::make('created_at')->label('Raised')->since()->sortable(),
            ])
            ->defaultSort('due_at')
            ->recordActions([
                Action::make('restore')->label('Restore')->icon('heroicon-m-arrow-uturn-left')->color('gray')
                    ->action(function (WorkflowTask $record) {
                        try {
                            app(InboxArchives::class)->restore(auth()->user(), 'workflow_task:'.$record->id, 'workflow_task');
                            Notification::make()->success()->title('Restored')->send();
                        } catch (RuntimeException $e) {
                            Notification::make()->danger()->title('Not restored')->body($e->getMessage())->send();
                        }
                    }),
            ])
            ->emptyStateHeading('Nothing archived')
            ->emptyStateDescription('Tasks you archive stay pending; they wait here until you restore them.');
    }

    /**
     * Pending workflow tasks waiting for the person: theirs (or their roles'), those a colleague out of office delegated
     * to them (HROne setup parity P4), less those they archived. With delegation and archiving off, exactly as before.
     */
    public static function waitingFor(Builder $query, User $user): Builder
    {
        $delegated = app(TaskDelegations::class)->delegatedTasks($user);
        $query->where('status', TaskStatus::Pending);
        $query = $delegated === null ? $query->actionableBy($user) : $query->where(function (Builder $q) use ($user, $delegated) {
            $q->actionableBy($user)->orWhere(fn (Builder $d) => $delegated($d));
        });
        $archived = self::archivedTaskIds($user);

        return $archived === [] ? $query : $query->whereNotIn('id', $archived);
    }

    /** @return list<int> ids of the workflow tasks the person archived (and has not restored) */
    private static function archivedTaskIds(User $user): array
    {
        $archives = app(InboxArchives::class);
        if (! $archives->active()) {
            return [];
        }

        return collect($archives->rowsFor($user))->filter(fn ($row) => $row->item_type === 'workflow_task' && $row->restored_at === null)
            ->map(fn ($row) => (int) Str::after($row->item_id, 'workflow_task:'))->filter()->values()->all();
    }

    public function getTabs(): array
    {
        $tabs = [
            'mine' => Tab::make('Waiting for me'),
            'onboarding' => Tab::make('Onboarding tasks'),
            'done' => Tab::make('Handled by me'),
        ];
        // HROne setup parity P3: shown while sending back is switched on, or while something is still sent back to the person.
        if (InboxActions::kinds('send_back') !== [] || RequestReturn::query()->where('status', 'open')->where('requester_id', auth()->id())->exists()) {
            $tabs['returned'] = Tab::make('Sent back to me');
        }

        // HROne setup parity P4: tasks the person archived.
        if (self::archivedTaskIds(auth()->user()) !== []) {
            $tabs['archived'] = Tab::make('Archived');
        }

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
            'done' => $query->where(fn (Builder $q) => $q->where('completed_by', $user->id)->orWhere('delegate_id', $user->id)),
            'all' => $user->can('task.view_all') ? $query->where('status', TaskStatus::Pending) : $query->whereRaw('1 = 0'),
            default => self::waitingFor($query, $user),
        };
    }
}
