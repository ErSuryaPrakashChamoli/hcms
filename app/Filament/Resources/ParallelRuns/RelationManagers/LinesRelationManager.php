<?php

namespace App\Filament\Resources\ParallelRuns\RelationManagers;

use App\Domain\Compliance\Models\ParallelPayrollLine;
use App\Domain\Compliance\Services\ParallelPayroll;
use App\Filament\Support\StatutoryReturnActions;
use Filament\Actions\Action;
use Filament\Forms\Components\Textarea;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

/** Compared values of a parallel run; each open difference is reviewed with a reason and resolution. */
class LinesRelationManager extends RelationManager
{
    protected static string $relationship = 'lines';

    protected static ?string $title = 'Compared values';

    public function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('level')->badge(),
                TextColumn::make('employee_code')->label('Employee')->placeholder('—')->searchable(),
                TextColumn::make('scope')->placeholder('—'),
                TextColumn::make('component'),
                TextColumn::make('peopleos_value')->label('PeopleOS')->numeric(2)->placeholder('—'),
                TextColumn::make('reference_value')->label('Reference')->numeric(2)->placeholder('—'),
                TextColumn::make('difference')->numeric(2)->placeholder('—'),
                TextColumn::make('status')->badge()->color(fn (string $state) => match ($state) {
                    'matched' => 'success', 'resolved' => 'info', default => 'danger'
                }),
                TextColumn::make('reason')->limit(30)->placeholder('—')->toggleable(),
            ])
            ->filters([SelectFilter::make('status')->options(['matched' => 'Matched', 'difference' => 'Difference', 'missing_reference' => 'Missing reference', 'missing_peopleos' => 'Missing in PeopleOS', 'resolved' => 'Resolved'])])
            ->recordActions([
                Action::make('review')->icon('heroicon-m-chat-bubble-left-ellipsis')
                    ->visible(fn (ParallelPayrollLine $record) => in_array($record->status, ParallelPayrollLine::OPEN, true) && StatutoryReturnActions::user()->hasPermission('compliance.parallel.manage'))
                    ->schema([
                        Textarea::make('reason')->label('Why the values differ')->required()->rows(2),
                        Textarea::make('resolution')->label('Resolution (never "changed the rule to match")')->required()->rows(2),
                    ])
                    ->action(fn (ParallelPayrollLine $record, array $data) => StatutoryReturnActions::run(fn () => app(ParallelPayroll::class)->review($record, StatutoryReturnActions::user(), $data['reason'], $data['resolution']), 'Difference reviewed')),
            ]);
    }
}
