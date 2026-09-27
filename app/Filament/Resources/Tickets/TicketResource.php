<?php

namespace App\Filament\Resources\Tickets;

use App\Domain\ServiceDesk\Models\Ticket;
use App\Filament\RelationManagers\AuditHistoryRelationManager;
use App\Filament\Resources\Articles\ArticleResource;
use App\Filament\Resources\Tickets\Pages\ListTickets;
use App\Filament\Resources\Tickets\Pages\ViewTicket;
use App\Filament\Resources\Tickets\RelationManagers\CommentsRelationManager;
use App\Filament\Support\ServiceDeskActions;
use BackedEnum;
use Filament\Actions\ViewAction;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use UnitEnum;

/** HR service desk (§48): agents see the queue; employees see "My requests". */
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
        return ServiceDeskActions::isAgent() ? 'Tickets' : 'My requests';
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function getGloballySearchableAttributes(): array
    {
        return ['number', 'subject'];
    }

    public static function getNavigationBadge(): ?string
    {
        $user = auth()->user();
        $count = ServiceDeskActions::isAgent()
            ? Ticket::query()->whereIn('status', ['new', 'open'])->where(fn ($q) => $q->whereNull('assignee_id')->orWhere('assignee_id', $user->id))->count()
            : Ticket::query()->whereHas('employee', fn ($q) => $q->where('user_id', $user->id))->whereIn('status', ['pending', 'resolved'])->count();

        return $count > 0 ? (string) $count : null;
    }

    public static function getEloquentQuery(): Builder
    {
        $query = parent::getEloquentQuery()->with(['category', 'employee.person', 'assignee']);
        $user = auth()->user();
        if (ServiceDeskActions::isAgent()) {
            return $query;
        }

        return $query->where(fn (Builder $q) => $q->whereHas('employee', fn (Builder $e) => $e->where('user_id', $user->id))->orWhere('assignee_id', $user->id));
    }

    public static function statusColor(string $status): string
    {
        return match ($status) {
            'new' => 'info', 'open' => 'primary', 'pending' => 'warning', 'resolved' => 'success', 'closed' => 'gray', default => 'gray'
        };
    }

    public static function infolist(Schema $schema): Schema
    {
        return $schema->components([
            Section::make(fn (Ticket $record) => "{$record->number} · {$record->subject}")->columns(4)->schema([
                TextEntry::make('status')->badge()->color(fn (string $state) => self::statusColor($state))->formatStateUsing(fn (string $state) => config("peopleos.servicedesk.statuses.{$state}", $state)),
                TextEntry::make('priority')->badge()->color(fn (string $state) => match ($state) {
                    'urgent' => 'danger', 'high' => 'warning', default => 'gray'
                }),
                TextEntry::make('category.name')->label('Category'),
                TextEntry::make('employee.person.full_name')->label('Employee'),
                TextEntry::make('assignee.name')->label('Assigned to')->placeholder('Unassigned'),
                TextEntry::make('created_at')->label('Raised')->since(),
                TextEntry::make('due_at')->label('SLA due')->dateTime()->placeholder('—')->color(fn (Ticket $record) => $record->isBreached() ? 'danger' : null),
                TextEntry::make('escalated_at')->dateTime()->placeholder('—')->visible(fn () => ServiceDeskActions::isAgent()),
                TextEntry::make('description')->columnSpanFull(),
                TextEntry::make('resolution')->placeholder('—')->columnSpanFull()->visible(fn (Ticket $record) => $record->resolution !== null),
                TextEntry::make('article.title')->label('Related article')->placeholder('—')->url(fn (Ticket $record) => $record->article ? ArticleResource::getUrl('view', ['record' => $record->article]) : null),
                TextEntry::make('satisfaction')->placeholder('—')->formatStateUsing(fn ($state) => $state ? str_repeat('★', (int) $state) : '—'),
                TextEntry::make('satisfaction_comment')->placeholder('—')->columnSpan(2),
            ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('number')->searchable()->sortable(),
                TextColumn::make('subject')->searchable()->limit(50)->description(fn (Ticket $record) => $record->category->name),
                TextColumn::make('employee.person.full_name')->label('Employee')->searchable(['first_name', 'last_name'])->visible(fn () => ServiceDeskActions::isAgent()),
                TextColumn::make('priority')->badge()->color(fn (string $state) => match ($state) {
                    'urgent' => 'danger', 'high' => 'warning', default => 'gray'
                }),
                TextColumn::make('status')->badge()->color(fn (string $state) => self::statusColor($state))->formatStateUsing(fn (string $state) => config("peopleos.servicedesk.statuses.{$state}", $state)),
                TextColumn::make('assignee.name')->label('Agent')->placeholder('Unassigned')->visible(fn () => ServiceDeskActions::isAgent()),
                TextColumn::make('due_at')->label('SLA')->since()->placeholder('—')->color(fn (Ticket $record) => $record->isBreached() ? 'danger' : null),
                TextColumn::make('created_at')->label('Raised')->since()->sortable(),
            ])
            ->defaultSort('id', 'desc')
            ->filters([
                SelectFilter::make('status')->options(config('peopleos.servicedesk.statuses'))->multiple()->default(['new', 'open', 'pending']),
                SelectFilter::make('ticket_category_id')->label('Category')->relationship('category', 'name'),
                Filter::make('mine')->label('Assigned to me')->query(fn (Builder $q) => $q->where('assignee_id', auth()->id()))->visible(fn () => ServiceDeskActions::isAgent()),
                Filter::make('breached')->label('Past SLA')->query(fn (Builder $q) => $q->whereIn('status', Ticket::OPEN)->where('due_at', '<', now())),
            ])
            ->recordActions([ViewAction::make()->label('Open')]);
    }

    public static function getRelations(): array
    {
        return [CommentsRelationManager::class, AuditHistoryRelationManager::class];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListTickets::route('/'),
            'view' => ViewTicket::route('/{record}'),
        ];
    }
}
