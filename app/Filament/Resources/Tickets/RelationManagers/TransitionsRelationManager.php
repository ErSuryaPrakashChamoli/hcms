<?php

namespace App\Filament\Resources\Tickets\RelationManagers;

use App\Domain\ServiceDesk\Models\TicketTransition;
use App\Domain\ServiceDesk\Services\CaseAccess;
use App\Filament\Resources\Tickets\TicketResource;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;

/** Phase 12: the request's status history. Employees see statuses and times; reasons and actors are for HR. */
class TransitionsRelationManager extends RelationManager
{
    protected static string $relationship = 'transitions';

    protected static ?string $title = 'Status history';

    public static function canViewForRecord(Model $ownerRecord, string $pageClass): bool
    {
        return auth()->user()?->can('view', $ownerRecord) ?? false;
    }

    public function table(Table $table): Table
    {
        $hr = app(CaseAccess::class)->commentVisibilities(auth()->user(), $this->getOwnerRecord()) !== ['employee'] && app(CaseAccess::class)->commentVisibilities(auth()->user(), $this->getOwnerRecord()) !== [];

        return $table
            ->modifyQueryUsing(fn ($query) => $query->with('actor'))
            ->columns([
                TextColumn::make('created_at')->label('When')->dateTime(),
                TextColumn::make('from_status')->label('From')->formatStateUsing(fn (?string $state) => TicketResource::statusLabel($state))->placeholder('—'),
                TextColumn::make('to_status')->label('To')->badge()->color(fn (string $state) => TicketResource::statusColor($state))->formatStateUsing(fn (string $state) => TicketResource::statusLabel($state)),
                TextColumn::make('actor.name')->label('By')->placeholder(fn (TicketTransition $record) => $record->via === 'system' ? 'System' : ($record->via === 'workflow' ? 'Approval workflow' : '—'))->visible($hr),
                TextColumn::make('reason')->wrap()->placeholder('—')->visible($hr),
            ])
            ->defaultSort('id')
            ->paginated(false);
    }
}
