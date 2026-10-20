<?php

namespace App\Filament\Resources\CompensationCycles\RelationManagers;

use App\Domain\Compensation\Models\CompensationChange;
use App\Domain\Compensation\Services\CompensationChanges;
use App\Filament\Support\CompensationActions;
use Filament\Actions\Action;
use Filament\Forms\Components\TextInput;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

/** Phase 11: a cycle's lines — one compensation change per employee, adjustable only while the cycle is a draft. */
class LinesRelationManager extends RelationManager
{
    protected static string $relationship = 'lines';

    protected static ?string $title = 'Employees and proposed compensation';

    public function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn ($query) => $query->with('employee.person'))
            ->columns([
                TextColumn::make('employee.employee_code')->label('Employee')->description(fn (CompensationChange $record) => $record->employee?->person?->full_name)->searchable(),
                TextColumn::make('previous_ctc_annual')->label('Current CTC')->numeric(2)->placeholder('—'),
                TextColumn::make('increase_percent')->label('%')->numeric(2)->placeholder('—'),
                TextColumn::make('ctc_annual')->label('Proposed CTC')->numeric(2)->description(fn (CompensationChange $record) => $record->currency),
                TextColumn::make('performance_label')->label('Finalized rating')->placeholder('—')->toggleable(),
                TextColumn::make('status')->badge()->formatStateUsing(fn (string $state) => CompensationChange::STATUSES[$state] ?? $state),
            ])
            ->defaultSort('id')
            ->recordActions([
                Action::make('adjust')->label('Adjust')->icon('heroicon-m-pencil-square')
                    ->visible(fn (CompensationChange $record) => $record->status === 'draft' && (int) $record->proposed_by === (int) auth()->id())
                    ->fillForm(fn (CompensationChange $record) => ['ctc_annual' => $record->ctc_annual])
                    ->schema([TextInput::make('ctc_annual')->label('Proposed annual CTC')->numeric()->minValue(1)->required()])
                    ->action(fn (CompensationChange $record, array $data) => CompensationActions::run(fn () => app(CompensationChanges::class)->update($record, ['ctc_annual' => $data['ctc_annual']], auth()->user()), 'Line adjusted')),
            ]);
    }
}
