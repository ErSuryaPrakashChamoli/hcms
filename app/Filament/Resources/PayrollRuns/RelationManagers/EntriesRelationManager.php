<?php

namespace App\Filament\Resources\PayrollRuns\RelationManagers;

use App\Domain\Payroll\Models\PayrollEntry;
use Filament\Actions\Action;
use Filament\Infolists\Components\RepeatableEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Components\Section;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;

class EntriesRelationManager extends RelationManager
{
    protected static string $relationship = 'entries';

    protected static ?string $title = 'Employees';

    public static function canViewForRecord(Model $ownerRecord, string $pageClass): bool
    {
        return auth()->user()?->can('payroll.view') ?? false;
    }

    public function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn ($query) => $query->with(['employee.person', 'lines']))
            ->columns([
                TextColumn::make('employee.employee_code')->label('Code')->searchable(),
                TextColumn::make('employee.person.full_name')->label('Employee')->searchable(['first_name', 'last_name']),
                TextColumn::make('paid_days')->label('Paid days')->numeric(1),
                TextColumn::make('lop_days')->label('LOP')->numeric(1),
                TextColumn::make('gross')->numeric(2),
                TextColumn::make('total_deductions')->label('Deductions')->numeric(2),
                TextColumn::make('net_pay')->label('Net')->numeric(2)->weight('bold'),
                TextColumn::make('status')->badge()->color(fn (string $state) => match ($state) {
                    'ok' => 'success', 'warning' => 'warning', default => 'danger'
                }),
                TextColumn::make('exceptions')->label('Issues')->state(fn (PayrollEntry $record) => collect($record->exceptions ?? [])->pluck('type')->map(fn ($t) => config("peopleos.payroll.exception_types.{$t}", $t))->implode(', '))->placeholder('—')->wrap(),
            ])
            ->defaultSort('employee_id')
            ->filters([SelectFilter::make('status')->options(['ok' => 'OK', 'warning' => 'Warning', 'exception' => 'Exception'])])
            ->recordActions([
                Action::make('detail')->label('Breakdown')->icon('heroicon-m-eye')->modalSubmitAction(false)->modalCancelActionLabel('Close')
                    ->schema([
                        Section::make('Summary')->columns(4)->schema([
                            TextEntry::make('days_in_period')->label('Days'),
                            TextEntry::make('paid_days')->label('Paid days'),
                            TextEntry::make('lop_days')->label('LOP days'),
                            TextEntry::make('net_pay')->label('Net pay')->numeric(2),
                        ]),
                        RepeatableEntry::make('lines')->hiddenLabel()->columns(4)->schema([
                            TextEntry::make('name'),
                            TextEntry::make('type')->badge()->color(fn (string $state) => match ($state) {
                                'earning', 'reimbursement' => 'success', 'deduction' => 'danger', default => 'info'
                            }),
                            TextEntry::make('amount')->numeric(2),
                            TextEntry::make('basis')->state(fn ($record) => collect($record->basis ?? [])->map(fn ($v, $k) => "{$k}: ".(is_scalar($v) ? $v : json_encode($v)))->implode(' · '))->placeholder('—')->wrap(),
                        ]),
                        Section::make('Exceptions')->schema([
                            TextEntry::make('exceptions')->hiddenLabel()->state(fn (PayrollEntry $record) => collect($record->exceptions ?? [])->map(fn ($e) => $e['message'])->all())->listWithLineBreaks()->placeholder('None'),
                        ])->visible(fn (PayrollEntry $record) => ! empty($record->exceptions)),
                    ]),
            ]);
    }
}
