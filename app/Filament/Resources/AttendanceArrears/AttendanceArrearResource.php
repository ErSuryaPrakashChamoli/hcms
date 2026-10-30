<?php

namespace App\Filament\Resources\AttendanceArrears;

use App\Domain\Attendance\Models\AttendanceArrear;
use App\Filament\Resources\AttendanceArrears\Pages\ListAttendanceArrears;
use App\Filament\Support\EmployeeFilters;
use App\Filament\Support\PayrollRegisters;
use App\Filament\Support\TimeOfficeRegisters;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use UnitEnum;

/** HROne parity HP-1: days corrected after the payroll lock, as paid-day differences for the next payroll. */
class AttendanceArrearResource extends Resource
{
    protected static ?string $model = AttendanceArrear::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedArrowPathRoundedSquare;

    protected static string|UnitEnum|null $navigationGroup = 'Attendance';

    protected static ?string $navigationLabel = 'Attendance arrears';

    protected static ?int $navigationSort = 64;

    public static function canViewAny(): bool
    {
        return auth()->user()?->can('attendance.view') ?? false;
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn ($query) => $query->with('employee.person'))
            ->columns([
                // HROne parity TX-1: the WO-2 employee search (code, names, work e-mail, designation).
                TextColumn::make('employee.person.display_name')->label('Employee')->searchable(query: EmployeeFilters::search('employee'))->description(fn (AttendanceArrear $r) => $r->employee?->employee_code),
                TextColumn::make('date')->date('D, d M Y')->sortable(),
                TextColumn::make('status_before')->label('Paid as')->formatStateUsing(fn (string $state) => config("peopleos.attendance.statuses.{$state}", $state)),
                TextColumn::make('status_after')->label('Now')->formatStateUsing(fn (string $state) => config("peopleos.attendance.statuses.{$state}", $state)),
                TextColumn::make('delta_days')->label('Paid days')->numeric(2)->color(fn (AttendanceArrear $r) => (float) $r->delta_days >= 0 ? 'success' : 'danger')
                    ->formatStateUsing(fn ($state) => sprintf('%+.2f', (float) $state)),
                TextColumn::make('status')->badge()->formatStateUsing(fn (string $state) => AttendanceArrear::STATUSES[$state] ?? $state)
                    ->color(fn (string $state) => match ($state) {
                        'settled' => 'success', 'void' => 'gray', default => 'warning'
                    }),
            ])
            ->filters([
                SelectFilter::make('status')->options(AttendanceArrear::STATUSES)->default('pending'),
                // HROne parity TX-1: corrected days and the WO-2 employee filters.
                ...TimeOfficeRegisters::filters('attendance.view', ['effective_dates' => ['Corrected day', 'date']]),
                // HROne parity PO-1 (matrix 25 §25.1 #5): "Pay Arrear (For) Month" and the "Arrear Days" bands.
                PayrollRegisters::arrears('attendance.view'),
            ])
            ->defaultSort('date', 'desc');
    }

    public static function getPages(): array
    {
        return ['index' => ListAttendanceArrears::route('/')];
    }
}
