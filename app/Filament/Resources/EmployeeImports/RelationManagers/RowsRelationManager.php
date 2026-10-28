<?php

namespace App\Filament\Resources\EmployeeImports\RelationManagers;

use App\Domain\Employment\Enums\ImportMode;
use App\Domain\Employment\Imports\BankAccountImports;
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
                TextColumn::make('data')->label('Row')->state(fn (EmployeeImportRow $record) => $this->summary($record))->limit(60),
                TextColumn::make('action')->badge()->color(fn (string $state) => match ($state) {
                    'create' => 'success', 'update' => 'info', 'review' => 'warning', 'error' => 'danger', default => 'gray'
                }),
                TextColumn::make('status')->badge()->color(fn (string $state) => match ($state) {
                    'imported' => 'success', 'failed' => 'danger', default => 'gray'
                }),
                // Errors for a refused row; otherwise why a row is skipped, or what it will do.
                TextColumn::make('errors')->label('Errors / notes')->state(fn (EmployeeImportRow $record) => $record->errors ? implode(' ', $record->errors) : ($record->match['reason'] ?? null))->wrap()->placeholder('—'),
                TextColumn::make('match')->label('Matched')->state(fn (EmployeeImportRow $record) => filled($record->match['name'] ?? null) ? $record->match['name'].' ('.implode(', ', $record->match['matched_on'] ?? []).')' : null)->placeholder('—')->wrap(),
                TextColumn::make('result')->wrap()->placeholder('—')->toggleable(),
            ])
            ->filters([SelectFilter::make('action')->options(['create' => 'Create', 'update' => 'Update', 'review' => 'Review', 'skip' => 'Skip', 'error' => 'Error'])])
            ->recordActions([
                Action::make('resolve')->label('Resolve')->icon('heroicon-m-scale')
                    ->visible(fn (EmployeeImportRow $record) => $record->action === 'review')
                    ->schema(fn () => [Radio::make('decision')->options($this->decisions())->required()])
                    ->action(fn (EmployeeImportRow $record, array $data) => ImportActions::run(fn () => app(EmployeeImports::class)->resolveReview($record, $data['decision']), 'Row resolved')),
            ])
            ->defaultSort('row_number')
            ->paginated([25, 50, 100]);
    }

    /** How a row reads in the list: its first cells, or for bank details the employee, bank and last four digits only. */
    private function summary(EmployeeImportRow $row): string
    {
        $import = $this->getOwnerRecord();

        return $import->type === BankAccountImports::TYPE ? app(BankAccountImports::class)->summary($row, $import) : collect($row->data)->take(3)->implode(' · ');
    }

    /** @return array<string, string> the decisions the file's mode allows for a row under review */
    private function decisions(): array
    {
        $mode = ImportMode::of($this->getOwnerRecord());

        return array_filter(['create' => $mode->adds() ? 'Create as a new person' : null, 'update' => $mode->updates() ? 'Update the matched employee' : null, 'skip' => 'Skip this row']);
    }
}
