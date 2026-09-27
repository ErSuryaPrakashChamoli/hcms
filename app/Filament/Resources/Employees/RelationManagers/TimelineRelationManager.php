<?php

namespace App\Filament\Resources\Employees\RelationManagers;

use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
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

    /** Timeline categories whose entries describe classified data (contract §6): shown only with the sensitive permission. */
    public const SENSITIVE_CATEGORIES = ['compensation', 'bank', 'statutory'];

    public function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query->when(
                ! (auth()->user()?->can('employee.sensitive.view') ?? false),
                fn (Builder $q) => $q->whereNotIn('category', self::SENSITIVE_CATEGORIES),
            ))
            ->columns([
                TextColumn::make('occurred_on')->label('Date')->date('d M Y')->sortable(),
                TextColumn::make('category')->badge()->color(fn (string $state) => match ($state) {
                    'lifecycle' => 'success',
                    'position' => 'warning',
                    'reporting' => 'info',
                    'compensation' => 'danger',
                    'documents' => 'primary',
                    default => 'gray',
                }),
                TextColumn::make('title')->weight('medium')->description(fn ($record) => $record->description)->wrap(),
                TextColumn::make('actor.name')->label('By')->placeholder('System')->toggleable(),
            ])
            ->filters([
                SelectFilter::make('category')->options(['lifecycle' => 'Lifecycle', 'position' => 'Position', 'reporting' => 'Reporting', 'compensation' => 'Compensation', 'documents' => 'Documents', 'onboarding' => 'Onboarding', 'exit' => 'Exit']),
            ])
            ->defaultSort('occurred_on', 'desc')
            ->paginated([10, 25, 50]);
    }
}
