<?php

namespace App\Filament\Resources\Employees\RelationManagers;

use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;

/** The People Timeline (blueprint §18). Read-only; entries are written by domain services. */
class TimelineRelationManager extends RelationManager
{
    protected static string $relationship = 'timelineEntries';

    protected static ?string $title = 'Timeline';

    public static function canViewForRecord(Model $ownerRecord, string $pageClass): bool
    {
        return auth()->user()?->can('view', $ownerRecord) ?? false;
    }

    public function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('occurred_on')->label('Date')->date('d M Y')->sortable(),
                TextColumn::make('category')->badge()->color(fn (string $state) => match ($state) {
                    'lifecycle' => 'success',
                    'position' => 'warning',
                    'reporting' => 'info',
                    default => 'gray',
                }),
                TextColumn::make('title')->weight('medium')->description(fn ($record) => $record->description)->wrap(),
                TextColumn::make('actor.name')->label('By')->placeholder('System')->toggleable(),
            ])
            ->filters([
                SelectFilter::make('category')->options(['lifecycle' => 'Lifecycle', 'position' => 'Position', 'reporting' => 'Reporting']),
            ])
            ->defaultSort('occurred_on', 'desc')
            ->paginated([10, 25, 50]);
    }
}
