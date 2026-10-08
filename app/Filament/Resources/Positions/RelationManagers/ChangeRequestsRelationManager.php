<?php

namespace App\Filament\Resources\Positions\RelationManagers;

use App\Domain\Workforce\Models\PositionChangeRequest;
use App\Domain\Workforce\Services\Positions;
use App\Filament\Support\WorkforceActions;
use Filament\Actions\Action;
use Filament\Forms\Components\Textarea;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

/** Position → Change requests: changes configured for approval, decided by someone other than the requester. */
class ChangeRequestsRelationManager extends RelationManager
{
    protected static string $relationship = 'changeRequests';

    protected static ?string $title = 'Change requests';

    public function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn ($query) => $query->with('requester'))
            ->columns([
                TextColumn::make('categories')->label('Changes')->state(fn (PositionChangeRequest $record) => implode(', ', $record->categories ?? [])),
                TextColumn::make('changes')->label('New values')->state(fn (PositionChangeRequest $record) => collect($record->changes)->map(fn ($v, $k) => "{$k}: {$v}")->implode('; '))->wrap(),
                TextColumn::make('effective_from')->date(),
                TextColumn::make('requester.name')->label('Requested by'),
                TextColumn::make('reason')->wrap(),
                TextColumn::make('status')->badge(),
            ])
            ->defaultSort('id', 'desc')
            ->recordActions([
                Action::make('approve')->label('Approve')->icon(Heroicon::OutlinedCheck)->color('success')->requiresConfirmation()
                    ->visible(fn (PositionChangeRequest $record) => $record->status === 'pending' && auth()->user()->can('workforce.approve') && (int) $record->requested_by !== (int) auth()->id())
                    ->action(fn (PositionChangeRequest $record) => WorkforceActions::run(fn () => app(Positions::class)->decide($record, true, null, auth()->user()), 'Change approved and applied')),
                Action::make('reject')->label('Reject')->icon(Heroicon::OutlinedXMark)->color('danger')
                    ->visible(fn (PositionChangeRequest $record) => $record->status === 'pending' && auth()->user()->can('workforce.approve') && (int) $record->requested_by !== (int) auth()->id())
                    ->schema([Textarea::make('note')->required()])
                    ->action(fn (PositionChangeRequest $record, array $data) => WorkforceActions::run(fn () => app(Positions::class)->decide($record, false, $data['note'], auth()->user()), 'Change rejected')),
                Action::make('cancel')->label('Cancel')->color('gray')
                    ->visible(fn (PositionChangeRequest $record) => $record->status === 'pending' && (int) $record->requested_by === (int) auth()->id())
                    ->action(fn (PositionChangeRequest $record) => WorkforceActions::run(fn () => app(Positions::class)->cancelRequest($record, auth()->user()), 'Request cancelled')),
            ]);
    }
}
