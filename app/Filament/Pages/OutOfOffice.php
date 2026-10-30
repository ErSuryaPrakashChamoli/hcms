<?php

namespace App\Filament\Pages;

use App\Domain\Experience\Services\ApprovalCenter;
use App\Domain\Identity\Enums\UserStatus;
use App\Domain\Identity\Models\User;
use App\Domain\Workflow\Models\TaskDelegation;
use App\Domain\Workflow\Services\TaskDelegations;
use App\Support\Tenancy\TenantContext;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Builder;
use RuntimeException;
use UnitEnum;

/**
 * HROne setup parity P4 (Inbox → Delegate): "Out of office". A person hands their approvals of the request types switched
 * on for delegation (Setup → Automation → Inbox actions) to a colleague for some days; the colleague decides them in
 * their stead from the Approval Center and the task inbox. People see their own periods and those handed to them;
 * task.delegate_any sets them up and ends them for anyone.
 */
class OutOfOffice extends Page implements HasTable
{
    use InteractsWithTable;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedCalendarDays;

    protected static string|UnitEnum|null $navigationGroup = 'Workflows';

    protected static ?string $navigationLabel = 'Out of office';

    protected static ?string $title = 'Out of office';

    protected static ?int $navigationSort = 6;

    protected string $view = 'filament.pages.out-of-office';

    public static function canAccess(): bool
    {
        $user = auth()->user();

        return $user !== null && app(TenantContext::class)->has() && ($user->can('task.delegate') || $user->can('task.delegate_any'));
    }

    public static function shouldRegisterNavigation(): bool
    {
        return static::canAccess() && TaskDelegations::switchedOn();
    }

    public function mount(): void
    {
        abort_unless(static::canAccess(), 403);
    }

    public function switchedOn(): bool
    {
        return TaskDelegations::switchedOn();
    }

    protected function getHeaderActions(): array
    {
        $kinds = fn () => array_intersect_key(ApprovalCenter::kinds(), array_flip(TaskDelegations::kinds()));
        $people = fn () => User::forCurrentTenant()->where('status', UserStatus::Active->value)->where('is_platform_admin', false)->orderBy('name')->pluck('name', 'id')->all();

        return [
            Action::make('delegate')->label('Set up out of office')->icon(Heroicon::OutlinedPaperAirplane)
                ->visible(fn () => TaskDelegations::switchedOn())
                ->schema([
                    Select::make('delegator_id')->label('Out of office')->options($people)->searchable()->native(false)->default(auth()->id())->required()
                        ->visible(fn () => auth()->user()->can('task.delegate_any'))->helperText('Whose approvals are handed over.'),
                    Select::make('delegate_id')->label('Who decides in the meantime')->options($people)->searchable()->native(false)->required(),
                    DatePicker::make('starts_on')->label('From')->native(false)->default(today())->minDate(today())->required(),
                    DatePicker::make('ends_on')->label('Until')->native(false)->minDate(today())->required(),
                    CheckboxList::make('types')->label('Requests handed over (none ticked = all of these)')->options($kinds)->columns(2),
                    Textarea::make('reason')->label('Reason')->required()->maxLength(500),
                ])
                ->action(function (array $data) {
                    $actor = auth()->user();
                    $delegator = $actor->can('task.delegate_any') && filled($data['delegator_id'] ?? null) ? User::forCurrentTenant()->find($data['delegator_id']) : $actor;
                    $delegate = User::forCurrentTenant()->find($data['delegate_id'] ?? 0);
                    try {
                        if ($delegator === null || $delegate === null) {
                            throw new RuntimeException('Choose active people of this organisation.');
                        }
                        app(TaskDelegations::class)->create($actor, $delegator, $delegate, (string) $data['starts_on'], (string) $data['ends_on'], array_values((array) ($data['types'] ?? [])), (string) $data['reason']);
                        Notification::make()->success()->title('Out of office set up')->body($delegate->name.' decides for '.$delegator->name.' from '.$data['starts_on'].' to '.$data['ends_on'].'.')->send();
                    } catch (AuthorizationException|RuntimeException $e) {
                        Notification::make()->danger()->title('Not set up')->body($e->getMessage())->send();
                    }
                }),
        ];
    }

    public function table(Table $table): Table
    {
        $delegations = app(TaskDelegations::class);

        return $table
            ->query(fn (): Builder => $delegations->visibleTo(auth()->user()))
            ->columns([
                TextColumn::make('delegator.name')->label('Out of office'),
                TextColumn::make('delegate.name')->label('Decided by'),
                TextColumn::make('starts_on')->label('From')->date()->sortable(),
                TextColumn::make('ends_on')->label('Until')->date()->sortable(),
                TextColumn::make('types')->label('Requests')->wrap()->state(fn (TaskDelegation $record) => $delegations->typesLabel($record)),
                TextColumn::make('state')->label('Status')->badge()->state(fn (TaskDelegation $record) => $record->stateLabel())
                    ->color(fn (string $state) => match ($state) {
                        'Running' => 'success', 'Upcoming' => 'info', default => 'gray'
                    }),
                TextColumn::make('reason')->wrap()->toggleable(),
            ])
            ->defaultSort('starts_on', 'desc')
            ->filters([
                SelectFilter::make('status')->options(TaskDelegation::STATUSES),
            ])
            ->recordActions([
                Action::make('end')->label('End now')->icon(Heroicon::OutlinedStopCircle)->color('danger')
                    ->visible(fn (TaskDelegation $record) => $record->status === 'active' && $record->ends_on->toDateString() >= today()->toDateString() && auth()->user()->can('update', $record))
                    ->schema([Textarea::make('reason')->label('Reason')->required()->maxLength(500)])
                    ->action(function (TaskDelegation $record, array $data) {
                        try {
                            app(TaskDelegations::class)->revoke($record, auth()->user(), (string) $data['reason']);
                            Notification::make()->success()->title('Out of office ended')->send();
                        } catch (AuthorizationException|RuntimeException $e) {
                            Notification::make()->danger()->title('Not ended')->body($e->getMessage())->send();
                        }
                    }),
            ])
            ->emptyStateHeading('No out-of-office periods')
            ->emptyStateDescription('Going away? Hand your approvals to a colleague for those days; they decide in your place and the record shows it.');
    }
}
