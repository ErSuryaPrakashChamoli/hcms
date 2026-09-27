<?php

namespace App\Filament\Resources\Payslips;

use App\Domain\Payroll\Models\Payslip;
use App\Filament\Resources\Payslips\Pages\ListPayslips;
use App\Filament\Resources\Payslips\Pages\ViewPayslip;
use BackedEnum;
use Filament\Actions\ViewAction;
use Filament\Infolists\Components\RepeatableEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use UnitEnum;

/** Payslips: payroll staff see all, employees see their own (§31). */
class PayslipResource extends Resource
{
    protected static ?string $model = Payslip::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedDocumentText;

    protected static string|UnitEnum|null $navigationGroup = 'Payroll';

    protected static ?string $navigationLabel = 'Payslips';

    protected static ?int $navigationSort = 30;

    public static function canCreate(): bool
    {
        return false;
    }

    public static function getEloquentQuery(): Builder
    {
        $query = parent::getEloquentQuery()->with(['employee.person']);

        if (! auth()->user()->can('payroll.view')) {
            $query->whereHas('employee', fn (Builder $q) => $q->where('user_id', auth()->id()));
        }

        return $query;
    }

    public static function infolist(Schema $schema): Schema
    {
        $lines = fn (string $key, string $label) => RepeatableEntry::make("snapshot.{$key}")->label($label)->columns(2)->schema([
            TextEntry::make('name')->hiddenLabel(),
            TextEntry::make('amount')->hiddenLabel()->numeric(2)->alignEnd(),
        ]);

        return $schema->components([
            Section::make(fn (Payslip $record) => $record->get('company.name').' · Payslip for '.$record->get('period.label'))->columns(4)->schema([
                TextEntry::make('number'),
                TextEntry::make('snapshot.employee.name')->label('Employee'),
                TextEntry::make('snapshot.employee.code')->label('Code'),
                TextEntry::make('snapshot.employee.designation')->label('Designation')->placeholder('—'),
                TextEntry::make('snapshot.employee.department')->label('Department')->placeholder('—'),
                TextEntry::make('snapshot.employee.joining_date')->label('Joined')->placeholder('—'),
                TextEntry::make('snapshot.employee.pan')->label('PAN')->placeholder('—'),
                TextEntry::make('snapshot.employee.uan')->label('UAN')->placeholder('—'),
                TextEntry::make('snapshot.employee.bank.name')->label('Bank')->placeholder('—'),
                TextEntry::make('snapshot.employee.bank.account')->label('Account')->placeholder('—'),
                TextEntry::make('snapshot.days.paid')->label('Paid days'),
                TextEntry::make('snapshot.days.lop')->label('LOP days'),
            ]),
            Grid::make(2)->schema([
                Section::make('Earnings')->schema([$lines('earnings', 'Earnings'), TextEntry::make('snapshot.totals.earnings')->label('Total earnings')->numeric(2)->weight('bold')]),
                Section::make('Deductions')->schema([$lines('deductions', 'Deductions'), TextEntry::make('snapshot.totals.deductions')->label('Total deductions')->numeric(2)->weight('bold')]),
            ]),
            Section::make('Net pay')->columns(2)->schema([
                TextEntry::make('snapshot.totals.net')->label('Net pay')->numeric(2)->size('lg')->weight('bold'),
                TextEntry::make('snapshot.net_in_words')->label('In words'),
            ]),
            Section::make('Employer contributions')->collapsed()->schema([$lines('employer_contributions', 'Employer contributions')]),
            Section::make('Tax computation')->collapsed()->schema([
                TextEntry::make('snapshot.tax')->hiddenLabel()->state(fn (Payslip $record) => collect($record->get('tax') ?? [])->map(fn ($v, $k) => str_replace('_', ' ', $k).': '.(is_scalar($v) ? $v : json_encode($v)))->all())->listWithLineBreaks()->placeholder('Not applicable'),
            ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('number')->searchable(),
                TextColumn::make('snapshot.period.label')->label('Period'),
                TextColumn::make('employee.employee_code')->label('Code')->searchable(),
                TextColumn::make('employee.person.full_name')->label('Employee'),
                TextColumn::make('snapshot.totals.gross')->label('Gross')->numeric(2),
                TextColumn::make('snapshot.totals.net')->label('Net pay')->numeric(2),
                TextColumn::make('generated_at')->dateTime()->sortable(),
            ])
            ->defaultSort('generated_at', 'desc')
            ->recordActions([ViewAction::make()]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListPayslips::route('/'),
            'view' => ViewPayslip::route('/{record}'),
        ];
    }
}
