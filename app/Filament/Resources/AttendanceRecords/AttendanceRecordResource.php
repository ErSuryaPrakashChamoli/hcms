<?php

namespace App\Filament\Resources\AttendanceRecords;

use App\Domain\Attendance\Models\AttendanceRecord;
use App\Filament\Resources\AttendanceRecords\Pages\ListAttendanceRecords;
use App\Filament\Support\AttendanceActions;
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

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->with(['employee.person', 'shift']);
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
