<?php

namespace App\Filament\Resources\AttendanceRecords;

use App\Domain\Attendance\Models\AttendanceRecord;
use App\Domain\Attendance\Models\WorkSchedule;
use App\Domain\Employment\Models\Employee;
use App\Filament\Resources\AttendanceRecords\Pages\ListAttendanceRecords;
use App\Filament\Support\AttendanceActions;
use App\Filament\Support\TimeOfficeRegisters;
use BackedEnum;
use Filament\Forms\Components\DatePicker;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use UnitEnum;

class AttendanceRecordResource extends Resource
{
    protected static ?string $model = AttendanceRecord::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedCalendar;

    protected static string|UnitEnum|null $navigationGroup = 'Attendance';

    protected static ?string $navigationLabel = 'Attendance records';

    protected static ?string $modelLabel = 'attendance record';

    protected static ?int $navigationSort = 50;

    public static function canCreate(): bool
    {
        return false;
    }

    /** Employees without attendance.view only ever see their own rows, whatever the table renders (server-side, not UI hiding). */
    public static function getEloquentQuery(): Builder
    {
        $query = parent::getEloquentQuery()->with(['employee.person', 'shift']);

        return auth()->user()?->can('attendance.view')
            ? $query
            : $query->whereIn('employee_id', Employee::query()->select('id')->where('user_id', auth()->id()));
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns(AttendanceActions::columns())
            ->filters([
                Filter::make('date')
                    ->schema([DatePicker::make('from')->native(false)->default(now()->startOfMonth()), DatePicker::make('until')->native(false)])
                    ->query(fn (Builder $query, array $data) => $query
                        ->when($data['from'] ?? null, fn (Builder $q, $d) => $q->whereDate('date', '>=', $d))
                        ->when($data['until'] ?? null, fn (Builder $q, $d) => $q->whereDate('date', '<=', $d))),
                SelectFilter::make('status')->options(config('peopleos.attendance.statuses'))->multiple(),
                SelectFilter::make('department')->relationship('employee.currentPosition.department', 'name')->preload(),
                // HROne parity TX-1 (§24.5.1 #2): the shift worked and the work schedule the day was processed with, and the
                // WO-2 employee filters (HR side only; an employee's own list is unchanged).
                SelectFilter::make('shift_id')->label('Shift')->relationship('shift', 'name')->preload()
                    ->visible(fn () => TimeOfficeRegisters::hrSide('attendance.view')),
                SelectFilter::make('work_schedule')->label('Work schedule')->multiple()
                    ->options(fn () => WorkSchedule::query()->orderBy('name')->limit(500)->pluck('name', 'id')->all())
                    ->query(function (Builder $query, array $data): Builder {
                        $ids = array_values(array_filter(array_map('intval', (array) ($data['values'] ?? []))));

                        return $ids === [] ? $query : $query->whereIn('calculation_basis->schedule_id', $ids);
                    })
                    ->visible(fn () => TimeOfficeRegisters::hrSide('attendance.view')),
                SelectFilter::make('overtime_status')->label('Overtime')->options(fn () => (array) config('peopleos.attendance.overtime_statuses'))
                    ->visible(fn () => TimeOfficeRegisters::hrSide('attendance.view')),
                ...TimeOfficeRegisters::filters('attendance.view'),
            ])
            ->defaultSort('date', 'desc')
            ->recordActions(AttendanceActions::forRecords())
            ->paginated([25, 50, 100]);
    }

    public static function getPages(): array
    {
        return ['index' => ListAttendanceRecords::route('/')];
    }
}
