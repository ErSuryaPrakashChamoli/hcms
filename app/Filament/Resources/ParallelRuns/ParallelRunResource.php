<?php

namespace App\Filament\Resources\ParallelRuns;

use App\Domain\Compliance\Models\ParallelPayrollRun;
use App\Domain\Compliance\Services\ParallelPayroll;
use App\Filament\Resources\ParallelRuns\Pages\ListParallelRuns;
use App\Filament\Resources\ParallelRuns\Pages\ViewParallelRun;
use App\Filament\Resources\ParallelRuns\RelationManagers\LinesRelationManager;
use App\Filament\Support\StatutoryReturnActions;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use UnitEnum;

/** Phase 6.5: controlled parallel payroll cycles and their reconciliation. */
class ParallelRunResource extends Resource
{
    protected static ?string $model = ParallelPayrollRun::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedArrowsRightLeft;

    protected static string|UnitEnum|null $navigationGroup = 'Compliance';

    protected static ?string $navigationLabel = 'Parallel payroll';

    protected static ?string $slug = 'compliance/parallel-runs';

    protected static ?int $navigationSort = 40;

    public static function canCreate(): bool
    {
        return false;
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->with('payrollRun.period');
    }

    public static function infolist(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Parallel run')->columns(4)->schema([
                TextEntry::make('payrollRun.period.start_date')->label('Payroll period')->date('M Y'),
                TextEntry::make('reference_source'),
                TextEntry::make('status')->badge(),
                TextEntry::make('reference_file_sha256')->label('Reference file SHA-256')->limit(16),
                TextEntry::make('summary')->label('Summary')->columnSpanFull()->state(fn (ParallelPayrollRun $record) => collect($record->summary ?? [])->map(fn ($v, $k) => str_replace('_', ' ', $k).": {$v}")->implode(' · ')),
                TextEntry::make('reference_description')->placeholder('—')->columnSpanFull(),
            ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('payrollRun.period.start_date')->label('Period')->date('M Y'),
                TextColumn::make('reference_source'),
                TextColumn::make('status')->badge()->color(fn (string $state) => match ($state) {
                    'reconciled' => 'success', 'compared' => 'warning', 'abandoned' => 'gray', default => 'info'
                }),
                TextColumn::make('summary.difference')->label('Open differences')->placeholder('—'),
                TextColumn::make('summary.resolved')->label('Resolved')->placeholder('—'),
                TextColumn::make('reconciled_at')->dateTime()->placeholder('—'),
            ])
            ->recordActions([
                Action::make('compare')->icon('heroicon-m-scale')
                    ->visible(fn (ParallelPayrollRun $record) => in_array($record->status, ['imported', 'compared'], true) && StatutoryReturnActions::user()->hasPermission('compliance.parallel.manage'))
                    ->action(fn (ParallelPayrollRun $record) => StatutoryReturnActions::run(fn () => app(ParallelPayroll::class)->compare($record, StatutoryReturnActions::user()), 'Compared')),
                Action::make('reconcile')->icon('heroicon-m-check-badge')->color('success')->requiresConfirmation()
                    ->modalDescription('Sign off only when every difference is explained and resolved. Matching totals alone are not a reconciliation. You cannot sign off reference values you imported.')
                    ->visible(fn (ParallelPayrollRun $record) => $record->status === 'compared' && StatutoryReturnActions::user()->hasPermission('compliance.parallel.manage'))
                    ->action(fn (ParallelPayrollRun $record) => StatutoryReturnActions::run(fn () => app(ParallelPayroll::class)->reconcile($record, StatutoryReturnActions::user()), 'Parallel run reconciled')),
            ])
            ->defaultSort('id', 'desc');
    }

    public static function getRelations(): array
    {
        return [LinesRelationManager::class];
    }

    public static function getPages(): array
    {
        return ['index' => ListParallelRuns::route('/'), 'view' => ViewParallelRun::route('/{record}')];
    }
}
