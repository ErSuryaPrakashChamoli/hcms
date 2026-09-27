<?php

namespace App\Filament\Resources\ExitCases\RelationManagers;

use App\Domain\Exit\Models\ExitClearance;
use App\Filament\Support\ExitActions;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;

class ClearancesRelationManager extends RelationManager
{
    protected static string $relationship = 'clearances';

    protected static ?string $title = 'Clearance';

    public static function canViewForRecord(Model $ownerRecord, string $pageClass): bool
    {
        return auth()->user()?->can('view', $ownerRecord) ?? false;
    }

    public function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn ($query) => $query->with(['owner', 'ownerRole', 'clearer', 'exitCase']))
            ->columns([
                TextColumn::make('sort_order')->label('#'),
                TextColumn::make('name')->label('Stage'),
                TextColumn::make('owner')->label('Owner')->state(fn (ExitClearance $record) => $record->owner?->name ?? $record->ownerRole?->name)->placeholder('Unassigned'),
                TextColumn::make('items')->label('Checklist')->state(fn (ExitClearance $record) => collect($record->items ?? [])->map(fn ($i) => ($i['done'] ? '✓ ' : '○ ').$i['item'])->implode("\n"))->wrap()->listWithLineBreaks(),
                TextColumn::make('status')->badge()->color(fn (string $state) => match ($state) {
                    'cleared' => 'success', 'blocked' => 'danger', 'na' => 'gray', default => 'warning'
                })->formatStateUsing(fn (string $state) => config("peopleos.exit.clearance_statuses.{$state}", $state)),
                TextColumn::make('recoverable_amount')->label('Recover')->numeric(2)->placeholder('—'),
                TextColumn::make('remarks')->placeholder('—')->wrap(),
                TextColumn::make('clearer.name')->label('By')->placeholder('—'),
                TextColumn::make('cleared_at')->dateTime()->placeholder('—'),
            ])
            ->defaultSort('sort_order')
            ->paginated(false)
            ->recordActions(ExitActions::forClearance());
    }
}
