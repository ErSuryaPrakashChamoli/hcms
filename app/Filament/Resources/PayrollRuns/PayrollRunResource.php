<?php

namespace App\Filament\Resources\PayrollRuns;

use App\Domain\Payroll\Models\PayrollRun;
use App\Filament\RelationManagers\AuditHistoryRelationManager;
use App\Filament\Resources\PayrollRuns\Pages\ListPayrollRuns;
use App\Filament\Resources\PayrollRuns\Pages\ViewPayrollRun;
use App\Filament\Resources\PayrollRuns\RelationManagers\EntriesRelationManager;
use BackedEnum;
use Filament\Actions\ViewAction;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use UnitEnum;

/** Payroll runs (§31). */
class PayrollRunResource extends Resource
{
    protected static ?string $model = PayrollRun::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedCalculator;

    protected static string|UnitEnum|null $navigationGroup = 'Payroll';

    protected static ?string $navigationLabel = 'Payroll runs';

    protected static ?int $navigationSort = 10;

    public static function canCreate(): bool
    {
        return false;
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->with(['period', 'company', 'creator', 'approver']);
    }

    public static function statusColor(string $status): string
    {
        return match ($status) {
            'draft' => 'gray', 'calculated' => 'info', 'validated' => 'warning', 'approved' => 'primary', 'finalized', 'paid' => 'success', default => 'gray',
        };
    }

    public static function infolist(Schema $schema): Schema
    {
        $money = fn (string $key, string $label) => TextEntry::make("totals.{$key}")->label($label)->numeric(2)->placeholder('—');

        return $schema->components([
            Section::make('Run')->columns(4)->schema([
                TextEntry::make('period.label')->label('Period')->state(fn (PayrollRun $record) => $record->period->label()),
                TextEntry::make('company.name')->label('Company'),
                TextEntry::make('status')->badge()->color(fn (string $state) => self::statusColor($state))->formatStateUsing(fn (string $state) => config("peopleos.payroll.run_statuses.{$state}", $state)),
                TextEntry::make('exception_count')->label('Employees with exceptions')->badge()->color(fn ($state) => $state > 0 ? 'warning' : 'success'),
                TextEntry::make('creator.name')->label('Prepared by')->placeholder('—'),
                TextEntry::make('calculated_at')->dateTime()->placeholder('—'),
                TextEntry::make('approver.name')->label('Approved by')->placeholder('—'),
                TextEntry::make('finalized_at')->dateTime()->placeholder('—'),
                TextEntry::make('paid_at')->dateTime()->placeholder('—'),
                TextEntry::make('notes')->placeholder('—')->columnSpan(3),
            ]),
            Section::make('Totals')->columns(6)->schema([
                TextEntry::make('totals.employees')->label('Employees')->placeholder('—'),
                $money('gross', 'Gross'), $money('earnings', 'Total earnings'), $money('deductions', 'Total deductions'), $money('net', 'Net pay'), $money('employer_cost', 'Employer cost'),
                $money('pf_employee', 'PF (employee)'), $money('pf_employer', 'PF (employer incl. charges)'), $money('esi', 'ESI (both)'), $money('pt', 'Professional tax'), $money('tds', 'TDS'),
                TextEntry::make('totals.lop_days')->label('LOP days')->placeholder('—'),
            ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('period.start_date')->label('Period')->formatStateUsing(fn ($state, PayrollRun $record) => $record->period->label())->sortable(),
                TextColumn::make('company.name')->label('Company')->sortable(),
                TextColumn::make('status')->badge()->color(fn (string $state) => self::statusColor($state))->formatStateUsing(fn (string $state) => config("peopleos.payroll.run_statuses.{$state}", $state)),
                TextColumn::make('totals.employees')->label('Employees')->placeholder('—'),
                TextColumn::make('totals.net')->label('Net pay')->numeric(2)->placeholder('—'),
                TextColumn::make('exception_count')->label('Exceptions')->badge()->color(fn ($state) => $state > 0 ? 'warning' : 'gray'),
                TextColumn::make('creator.name')->label('Prepared by')->placeholder('—')->toggleable(),
                TextColumn::make('finalized_at')->dateTime()->placeholder('—')->toggleable(),
            ])
            ->defaultSort('id', 'desc')
            ->filters([SelectFilter::make('status')->options(config('peopleos.payroll.run_statuses'))])
            ->recordActions([ViewAction::make()]);
    }

    public static function getRelations(): array
    {
        return [EntriesRelationManager::class, AuditHistoryRelationManager::class];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListPayrollRuns::route('/'),
            'view' => ViewPayrollRun::route('/{record}'),
        ];
    }
}
