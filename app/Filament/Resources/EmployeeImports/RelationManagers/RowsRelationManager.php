<?php

namespace App\Filament\Resources\EmployeeImports\RelationManagers;

use App\Domain\Employment\Imports\EmployeeImportRow;
use App\Domain\Employment\Imports\EmployeeImports;
use App\Filament\Support\ImportActions;
use Filament\Actions\Action;
use Filament\Forms\Components\Radio;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

/** Staged rows with their validation outcome; reviews are resolved here before approval. */
class RowsRelationManager extends RelationManager
{
    protected static string $relationship = 'rows';

    protected static ?string $title = 'Rows';

    public function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('row_number')->label('#')->sortable(),
                TextColumn::make('data')->label('Name')->state(fn (EmployeeImportRow $record) => collect($record->data)->take(3)->implode(' · '))->limit(60),
                TextColumn::make('action')->badge()->color(fn (string $state) => match ($state) {
                    'create' => 'success', 'update' => 'info', 'review' => 'warning', 'error' => 'danger', default => 'gray'
                }),
                TextColumn::make('status')->badge()->color(fn (string $state) => match ($state) {
                    'imported' => 'success', 'failed' => 'danger', default => 'gray'
                }),
                TextColumn::make('errors')->label('Errors')->state(fn (EmployeeImportRow $record) => implode(' ', $record->errors ?? []))->wrap()->placeholder('—'),
                TextColumn::make('match')->label('Matched')->state(fn (EmployeeImportRow $record) => $record->match ? ($record->match['name'] ?? '').' ('.implode(', ', $record->match['matched_on'] ?? []).')' : null)->placeholder('—')->wrap(),
                TextColumn::make('result')->wrap()->placeholder('—')->toggleable(),
            ])
            ->filters([SelectFilter::make('action')->options(['create' => 'Create', 'update' => 'Update', 'review' => 'Review', 'skip' => 'Skip', 'error' => 'Error'])])
            ->recordActions([
                Action::make('resolve')->label('Resolve')->icon('heroicon-m-scale')
                    ->visible(fn (EmployeeImportRow $record) => $record->action === 'review')
                    ->schema([Radio::make('decision')->options(['create' => 'Create as a new person', 'update' => 'Update the matched employee', 'skip' => 'Skip this row'])->required()])
                    ->action(fn (EmployeeImportRow $record, array $data) => ImportActions::run(fn () => app(EmployeeImports::class)->resolveReview($record, $data['decision']), 'Row resolved')),
            ])
            ->defaultSort('row_number')
            ->paginated([25, 50, 100]);
    }
}
