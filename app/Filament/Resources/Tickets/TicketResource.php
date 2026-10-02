<?php

namespace App\Filament\Resources\Tickets;

use App\Domain\Identity\Models\Role;
use App\Domain\Identity\Models\User;
use App\Domain\ServiceDesk\Models\ServiceDefinition;
use App\Domain\ServiceDesk\Models\Ticket;
use App\Domain\ServiceDesk\Services\CaseAccess;
use App\Domain\ServiceDesk\Services\CaseAssignment;
use App\Domain\ServiceDesk\Services\DomainActions;
use App\Domain\ServiceDesk\Services\ServiceDeskBulk;
use App\Domain\ServiceDesk\Services\SlaClock;
use App\Filament\RelationManagers\AuditHistoryRelationManager;
use App\Filament\Resources\Articles\ArticleResource;
use App\Filament\Resources\Tickets\Pages\ListTickets;
use App\Filament\Resources\Tickets\Pages\ViewTicket;
use App\Filament\Resources\Tickets\RelationManagers\CommentsRelationManager;
use App\Filament\Resources\Tickets\RelationManagers\TransitionsRelationManager;
use App\Filament\Support\ServiceDeskActions;
use BackedEnum;
use Filament\Actions\BulkAction;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Infolists\Components\TextEntry;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use UnitEnum;

/**
 * HR service desk (§48, Phase 12): agents see the HR queue, employees "My requests". Every query is
 * restricted in SQL by CaseAccess:
 * - organisation scope;
 * - own requests;
 * - explicit access to restricted cases;
 * - the manager's team for manager-visible services.
 *
 * Search and filters run on that restricted query. Nothing is filtered in PHP.
 */
class TicketResource extends Resource
{
    protected static ?string $model = Ticket::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedLifebuoy;

    protected static ?string $recordTitleAttribute = 'number';

    protected static ?int $navigationSort = 1;

    public static function getNavigationGroup(): string|UnitEnum|null
    {
        return ServiceDeskActions::isAgent() ? 'Service Desk' : 'Me';
    }

    public static function getNavigationLabel(): string
    {
        return ServiceDeskActions::isAgent() ? 'HR queue' : 'My requests';
    }

    public static function getModelLabel(): string
    {
        return 'request';
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function getGloballySearchableAttributes(): array
    {
        return ['number'];
    }

    public static function getNavigationBadge(): ?string
    {
        $user = auth()->user();
        $query = static::getEloquentQuery();
        $count = auth()->user()->can('servicedesk.agent')
            ? $query->whereIn('tickets.status', Ticket::OPEN)->where(fn ($q) => $q->whereNull('tickets.assignee_id')->orWhere('tickets.assignee_id', $user->id))->count()
            : $query->whereIn('tickets.status', ['waiting_employee', 'resolved'])->where('tickets.employee_id', app(CaseAccess::class)->employeeId($user) ?? 0)->count();

        return $count > 0 ? (string) $count : null;
    }

    public static function getEloquentQuery(): Builder
    {
        return app(CaseAccess::class)->visible(parent::getEloquentQuery()->with(['category', 'service', 'employee.person', 'assignee', 'team']), auth()->user());
    }

    public static function statusColor(string $status): string
    {
        return match ($status) {
            'submitted', 'acknowledged' => 'info', 'assigned', 'in_progress' => 'primary', 'awaiting_approval', 'waiting_employee', 'waiting_hr' => 'warning',
            'resolved' => 'success', 'cancelled' => 'danger', default => 'gray'
        };
    }

    public static function statusLabel(?string $state): string
    {
        return (string) config("peopleos.servicedesk.statuses.{$state}", $state);
    }

    public static function infolist(Schema $schema): Schema
    {
        $works = fn (Ticket $record) => app(CaseAccess::class)->canWork(auth()->user(), $record) || (app(CaseAccess::class)->isAgent(auth()->user()) && ! app(CaseAccess::class)->isOwn(auth()->user(), $record));

        return $schema->components([
            Section::make(fn (Ticket $record) => "{$record->number} · {$record->subject}")->columns(4)->schema([
                TextEntry::make('status')->badge()->color(fn (string $state) => self::statusColor($state))->formatStateUsing(fn (string $state) => self::statusLabel($state)),
                TextEntry::make('priority')->badge()->color(fn (string $state) => match ($state) {
                    'urgent' => 'danger', 'high' => 'warning', default => 'gray'
                })->formatStateUsing(fn (string $state) => config("peopleos.servicedesk.priorities.{$state}", $state)),
                TextEntry::make('service')->label('Service')->state(fn (Ticket $record) => $record->serviceName().($record->serviceVersion ? ' (v'.$record->serviceVersion->version.')' : '')),
                TextEntry::make('confidentiality')->badge()->color(fn (string $state) => match ($state) {
                    'restricted' => 'danger', 'sensitive' => 'warning', default => 'gray'
                })->formatStateUsing(fn (string $state) => config("peopleos.servicedesk.confidentiality.{$state}", $state)),
                TextEntry::make('employee.person.full_name')->label('Employee'),
                TextEntry::make('raiser.name')->label('Raised by')->placeholder('—'),
                TextEntry::make('source')->formatStateUsing(fn (string $state) => config("peopleos.servicedesk.sources.{$state}", $state)),
                TextEntry::make('submitted_at')->label('Submitted')->dateTime()->placeholder('Draft'),
                TextEntry::make('description')->label('Details')->columnSpanFull(),
                TextEntry::make('resolution')->placeholder('—')->columnSpanFull()->visible(fn (Ticket $record) => $record->resolution !== null),
                TextEntry::make('article.title')->label('Related article')->placeholder('—')->url(fn (Ticket $record) => $record->article ? ArticleResource::getUrl('view', ['record' => $record->article]) : null),
                TextEntry::make('satisfaction')->placeholder('—')->formatStateUsing(fn ($state) => $state ? str_repeat('★', (int) $state) : '—'),
            ]),
            Section::make('Request details')->description('Protected values are masked unless you hold the owning domain\'s permission; values for an applied change are removed once it is decided.')
                ->visible(fn (Ticket $record) => app(CaseAccess::class)->formDataFor(auth()->user(), $record) !== [] || $record->form_data_purged_at !== null)
                ->schema([
                    TextEntry::make('form')->hiddenLabel()->listWithLineBreaks()->placeholder('Removed after the change was decided')
                        ->state(fn (Ticket $record) => collect(app(CaseAccess::class)->formDataFor(auth()->user(), $record))->map(fn ($f) => $f['label'].': '.(is_bool($f['value']) ? ($f['value'] ? 'Yes' : 'No') : (string) $f['value']).($f['masked'] ? ' (protected)' : ''))->values()->all()),
                ]),
            Section::make('Assignment and SLA')->columns(4)->visible($works)->schema([
                TextEntry::make('team.name')->label('Team')->placeholder('—'),
                TextEntry::make('assignee.name')->label('Agent')->placeholder('Unassigned'),
                TextEntry::make('owner.name')->label('Case owner')->placeholder('—'),
                TextEntry::make('escalation_level')->label('Escalation level'),
                TextEntry::make('first_response_due_at')->label('First response due')->dateTime()->placeholder('—'),
                TextEntry::make('first_responded_at')->label('First response')->dateTime()->placeholder('—'),
                TextEntry::make('due_at')->label('Resolution due')->dateTime()->placeholder('—')->color(fn (Ticket $record) => $record->isBreached() ? 'danger' : null),
                TextEntry::make('sla')->label('SLA')->state(fn (Ticket $record) => self::slaText($record)),
            ]),
            Section::make('Domain change')->columns(4)->visible(fn (Ticket $record) => $record->domain_action !== null)->schema([
                TextEntry::make('domain_action')->label('Hands off to')->formatStateUsing(fn (string $state) => app(DomainActions::class)->find($state)?->label() ?? $state),
                TextEntry::make('domain_action_status')->label('Status')->badge()->formatStateUsing(fn (?string $state) => config("peopleos.servicedesk.domain_action_statuses.{$state}", $state))->placeholder('—'),
                TextEntry::make('domain_reference')->label('Resulting record')->state(fn (Ticket $record) => $record->domain_reference_id ? class_basename((string) $record->domain_reference_type).' #'.$record->domain_reference_id : '—'),
                TextEntry::make('approver.name')->label('Approved by')->placeholder('—'),
                TextEntry::make('executor.name')->label('Executed by')->placeholder('—'),
                TextEntry::make('domain_action_executed_at')->label('Executed')->dateTime()->placeholder('—'),
                TextEntry::make('operation_id')->label('Operation id')->visible($works),
                TextEntry::make('workflowInstance.status')->label('Approval workflow')->placeholder('—')->formatStateUsing(fn ($state) => is_object($state) ? $state->value : (string) $state),
            ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        $agent = fn () => ServiceDeskActions::isAgent();

        return $table
            ->columns([
                TextColumn::make('number')->searchable()->sortable(),
                TextColumn::make('service')->label('Service')->state(fn (Ticket $record) => $record->serviceName())->description(fn (Ticket $record) => $record->confidentiality === 'restricted' ? 'Restricted' : null),
                TextColumn::make('employee.person.full_name')->label('Employee')->searchable(['first_name', 'last_name'])->visible($agent),
                TextColumn::make('priority')->badge()->color(fn (string $state) => match ($state) {
                    'urgent' => 'danger', 'high' => 'warning', default => 'gray'
                })->formatStateUsing(fn (string $state) => config("peopleos.servicedesk.priorities.{$state}", $state)),
                TextColumn::make('status')->badge()->color(fn (string $state) => self::statusColor($state))->formatStateUsing(fn (string $state) => self::statusLabel($state)),
                TextColumn::make('team.name')->label('Team')->placeholder('—')->visible($agent)->toggleable(),
                TextColumn::make('assignee.name')->label('Agent')->placeholder('Unassigned')->visible($agent),
                TextColumn::make('due_at')->label('SLA due')->since()->placeholder('—')->color(fn (Ticket $record) => $record->isBreached() ? 'danger' : null)->sortable(),
                TextColumn::make('created_at')->label('Raised')->since()->sortable(),
            ])
            ->defaultSort('id', 'desc')
            ->filters([
                SelectFilter::make('status')->options(config('peopleos.servicedesk.statuses'))->multiple()->default(Ticket::OPEN),
                SelectFilter::make('service_definition_id')->label('Service')->options(fn () => ServiceDefinition::query()->orderBy('name')->pluck('name', 'id')->all()),
                SelectFilter::make('ticket_category_id')->label('Category')->relationship('category', 'name'),
                SelectFilter::make('priority')->options(config('peopleos.servicedesk.priorities'))->multiple(),
                SelectFilter::make('assigned_role_id')->label('Team')->options(fn () => Role::query()->orderBy('name')->pluck('name', 'id')->all())->visible($agent),
                Filter::make('unassigned')->label('Unassigned')->query(fn (Builder $q) => $q->whereNull('tickets.assignee_id'))->visible($agent),
                Filter::make('mine')->label('Assigned to me')->query(fn (Builder $q) => $q->where('tickets.assignee_id', auth()->id()))->visible($agent),
                Filter::make('sla_risk')->label('SLA at risk')->query(fn (Builder $q) => $q->whereIn('tickets.status', Ticket::OPEN)->whereNull('tickets.sla_paused_at')->whereBetween('tickets.due_at', [now(), now()->addHours((int) config('peopleos.servicedesk.sla_risk_hours', 8))]))->visible($agent),
                Filter::make('breached')->label('Overdue')->query(fn (Builder $q) => $q->whereIn('tickets.status', Ticket::OPEN)->whereNull('tickets.sla_paused_at')->where('tickets.due_at', '<', now())),
                Filter::make('raised')->schema([DatePicker::make('from')->native(false), DatePicker::make('until')->native(false)])
                    ->query(fn (Builder $q, array $data) => $q->when($data['from'] ?? null, fn ($w, $d) => $w->whereDate('tickets.created_at', '>=', $d))->when($data['until'] ?? null, fn ($w, $d) => $w->whereDate('tickets.created_at', '<=', $d))),
            ])
            ->emptyStateHeading('No requests')
            ->emptyStateDescription(fn () => ServiceDeskActions::isAgent() ? 'Nothing matches these filters in your scope.' : 'Use My HR to request a service or ask HR a question.')
            ->recordActions([ViewAction::make()->label('Open')])
            ->toolbarActions([
                BulkActionGroup::make([
                    BulkAction::make('bulkAssign')->label('Assign')->icon(Heroicon::OutlinedUserPlus)
                        ->schema([Select::make('assignee_id')->label('Agent')->required()->searchable()->options(fn () => User::forCurrentTenant()->get()->filter(fn (User $u) => $u->isActive() && $u->hasPermission('servicedesk.agent'))->pluck('name', 'id')->all()), TextInput::make('reason')->maxLength(255)])
                        ->action(fn (Collection $records, array $data) => self::bulkReport(app(ServiceDeskBulk::class)->assign($records->pluck('id')->all(), User::query()->findOrFail($data['assignee_id']), auth()->user(), $data['reason'] ?? null))),
                    BulkAction::make('bulkMove')->label('Move status')->icon(Heroicon::OutlinedArrowsRightLeft)
                        ->schema([Select::make('status')->required()->options(collect(config('peopleos.servicedesk.statuses'))->only(['acknowledged', 'in_progress', 'waiting_employee', 'waiting_hr'])->all()), TextInput::make('reason')->maxLength(255)])
                        ->action(fn (Collection $records, array $data) => self::bulkReport(app(ServiceDeskBulk::class)->move($records->pluck('id')->all(), $data['status'], auth()->user(), $data['reason'] ?? null))),
                    BulkAction::make('bulkEscalate')->label('Escalate')->icon(Heroicon::OutlinedExclamationTriangle)->color('danger')
                        ->schema([TextInput::make('reason')->required()->maxLength(255)])
                        ->action(fn (Collection $records, array $data) => self::bulkReport(app(ServiceDeskBulk::class)->escalate($records->pluck('id')->all(), auth()->user(), $data['reason']))),
                ])->visible(fn () => auth()->user()->can('servicedesk.bulk')),
            ]);
    }

    /** @param  array{operation_id: string, results: array<int, string>}  $result */
    private static function bulkReport(array $result): void
    {
        $done = collect($result['results'])->filter(fn (string $r) => $r === 'done')->count();
        $other = collect($result['results'])->reject(fn (string $r) => $r === 'done');
        Notification::make()->title("{$done} case(s) updated")->body(($other->isEmpty() ? '' : $other->map(fn ($r, $id) => "#{$id}: {$r}")->implode("\n")."\n").'Operation '.$result['operation_id'])
            ->color($other->isEmpty() ? 'success' : 'warning')->persistent($other->isNotEmpty())->send();
    }

    public static function slaText(Ticket $record): string
    {
        if (! $record->isOpen()) {
            return 'Stopped ('.self::statusLabel($record->status).')';
        }
        if ($record->isPaused()) {
            return 'Paused since '.$record->sla_paused_at->diffForHumans();
        }
        $remaining = app(SlaClock::class)->remainingMinutes($record);
        if ($remaining === null) {
            return '—';
        }
        $hours = round(abs($remaining) / 60, 1);

        return $remaining < 0 ? "Breached by {$hours} service hours" : "{$hours} service hours left";
    }

    public static function getRelations(): array
    {
        return [CommentsRelationManager::class, TransitionsRelationManager::class, AuditHistoryRelationManager::class];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListTickets::route('/'),
            'view' => ViewTicket::route('/{record}'),
        ];
    }

    /** Agents eligible for a case (assignment pickers). */
    public static function agentsFor(Ticket $ticket): array
    {
        return app(CaseAssignment::class)->eligibleAgents($ticket)->pluck('name', 'id')->all();
    }
}
