<?php

namespace App\Filament\Resources\Employees\RelationManagers;

use App\Domain\ServiceDesk\Models\Ticket;
use App\Filament\Resources\Tickets\TicketResource;
use Filament\Actions\Action;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;

/** Employee 360 → Requests (service desk tickets). */
class RequestsRelationManager extends RelationManager
{
    protected static string $relationship = 'tickets';

    protected static ?string $title = 'Requests';

    public static function canViewForRecord(Model $ownerRecord, string $pageClass): bool
    {
        $user = auth()->user();

        return $user !== null && ($user->can('servicedesk.view') || $ownerRecord->user_id === $user->id);
    }

    public function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn ($query) => $query->with(['category', 'assignee']))
            ->columns([
                TextColumn::make('number'),
                TextColumn::make('subject')->limit(50)->description(fn (Ticket $record) => $record->category->name),
                TextColumn::make('status')->badge()->color(fn (string $state) => TicketResource::statusColor($state))->formatStateUsing(fn (string $state) => config("peopleos.servicedesk.statuses.{$state}", $state)),
                TextColumn::make('assignee.name')->label('Agent')->placeholder('—'),
                TextColumn::make('created_at')->since(),
            ])
            ->defaultSort('id', 'desc')
            ->recordActions([Action::make('open')->label('Open')->url(fn (Ticket $record) => TicketResource::getUrl('view', ['record' => $record]))]);
    }
}
