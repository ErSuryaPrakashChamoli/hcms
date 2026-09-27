<?php

namespace App\Filament\Resources\LeaveTransactions;

use App\Domain\Employment\Models\Employee;
use App\Domain\Leave\Models\LeaveLedgerEntry;
use App\Domain\Leave\Models\LeaveType;
use App\Filament\Resources\LeaveTransactions\Pages\ListLeaveTransactions;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use UnitEnum;

/** The leave ledger, read-only (Phase 3 §15–§18): every balance movement with its source. */
class LeaveTransactionResource extends Resource
{
    protected static ?string $model = LeaveLedgerEntry::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedQueueList;

    protected static string|UnitEnum|null $navigationGroup = 'Leave';

    protected static ?string $navigationLabel = 'Transactions';

    protected static ?int $navigationSort = 25;

    public static function getEloquentQuery(): Builder
    {
        $query = parent::getEloquentQuery()->with(['employee.person', 'leaveType']);

        return auth()->user()?->can('leave.view')
            ? $query
            : $query->whereIn('employee_id', Employee::query()->select('id')->where('user_id', auth()->id()));
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('entry_date')->date()->sortable(),
                TextColumn::make('employee.employee_code')->label('Code')->searchable(),
                TextColumn::make('employee.person.display_name')->label('Employee'),
                TextColumn::make('leaveType.code')->label('Type')->badge()->color('gray'),
                TextColumn::make('period_year')->label('Period'),
                TextColumn::make('type')->label('Movement')->badge()->color(fn (string $state) => in_array($state, ['usage', 'lapse', 'expiry', 'encashment'], true) ? 'danger' : 'success'),
                TextColumn::make('days')->numeric(2),
                TextColumn::make('note')->wrap()->placeholder('—'),
                TextColumn::make('operation_id')->label('Operation')->fontFamily('mono')->placeholder('—')->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('type')->label('Movement')->options(array_combine(LeaveLedgerEntry::TYPES, array_map(fn ($t) => ucfirst(str_replace('_', ' ', $t)), LeaveLedgerEntry::TYPES))),
                SelectFilter::make('leave_type_id')->label('Type')->options(fn () => LeaveType::query()->orderBy('name')->pluck('name', 'id')),
                SelectFilter::make('period_year')->label('Period')->options(fn () => LeaveLedgerEntry::query()->distinct()->orderByDesc('period_year')->pluck('period_year', 'period_year')),
            ])
            ->defaultSort('entry_date', 'desc');
    }

    public static function getPages(): array
    {
        return ['index' => ListLeaveTransactions::route('/')];
    }
}
