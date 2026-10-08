<?php

namespace App\Filament\Resources\Employees\RelationManagers;

use App\Domain\ServiceDesk\Models\Ticket;
use App\Domain\ServiceDesk\Services\CaseAccess;
use App\Filament\Resources\Tickets\TicketResource;
use Filament\Actions\Action;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;

/**
 * Employee 360 → Requests (Phase 12: service history). The employee's HR requests through CaseAccess.
 * Restricted cases appear only to people with explicit access, and drafts never. Rows show the service
 * and status, not the free-text subject. The case itself stays in the service desk; nothing is copied
 * here.
 */
class RequestsRelationManager extends RelationManager
{
    protected static string $relationship = 'tickets';

    protected static ?string $title = 'Requests';

    public static function canViewForRecord(Model $ownerRecord, string $pageClass): bool
    {
        $user = auth()->user();

        return $user !== null && (app(CaseAccess::class)->isAgent($user) || (int) $ownerRecord->user_id === (int) $user->id);
    }

    public function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn ($query) => app(CaseAccess::class)->visible($query->with(['category', 'service', 'assignee']), auth()->user()))
            ->columns([
                TextColumn::make('number'),
                TextColumn::make('service')->label('Service')->state(fn (Ticket $record) => $record->serviceName())->description(fn (Ticket $record) => $record->confidentiality !== 'standard' ? config("peopleos.servicedesk.confidentiality.{$record->confidentiality}") : null),
                TextColumn::make('status')->badge()->color(fn (string $state) => TicketResource::statusColor($state))->formatStateUsing(fn (string $state) => TicketResource::statusLabel($state)),
                TextColumn::make('domain_action_status')->label('Change')->badge()->placeholder('—')->formatStateUsing(fn (?string $state) => config("peopleos.servicedesk.domain_action_statuses.{$state}", $state)),
                TextColumn::make('assignee.name')->label('Agent')->placeholder('—'),
                TextColumn::make('created_at')->since(),
                TextColumn::make('resolved_at')->label('Resolved')->date()->placeholder('—'),
            ])
            ->emptyStateHeading('No HR requests')
            ->defaultSort('id', 'desc')
            ->recordActions([Action::make('open')->label('Open')->url(fn (Ticket $record) => TicketResource::getUrl('view', ['record' => $record]))]);
    }
}
