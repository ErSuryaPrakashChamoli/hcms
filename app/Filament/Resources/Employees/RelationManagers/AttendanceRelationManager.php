<?php

namespace App\Filament\Resources\Employees\RelationManagers;

use App\Filament\Pages\AttendanceCalendar;
use App\Filament\Support\AttendanceActions;
use App\Filament\Support\TimeOfficeRegisters;
use Filament\Actions\Action;
use Filament\Forms\Components\DatePicker;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

class AttendanceRelationManager extends RelationManager
{
    protected static string $relationship = 'attendanceRecords';

    protected static ?string $title = 'Attendance';

    public static function canViewForRecord(Model $ownerRecord, string $pageClass): bool
    {
        $user = auth()->user();

        return $user !== null && ($user->can('attendance.view') || ($user->can('attendance.regularise') && $ownerRecord->user_id === $user->id));
    }

    public function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query->with(['shift', 'employee']))
            ->columns(AttendanceActions::columns(withEmployee: false))
            ->filters([
                Filter::make('date')
                    ->schema([DatePicker::make('from')->native(false)->default(now()->startOfMonth()), DatePicker::make('until')->native(false)])
                    ->query(fn (Builder $query, array $data) => $query
                        ->when($data['from'] ?? null, fn (Builder $q, $d) => $q->whereDate('date', '>=', $d))
                        ->when($data['until'] ?? null, fn (Builder $q, $d) => $q->whereDate('date', '<=', $d))),
            ])
            ->defaultSort('date', 'desc')
            ->recordActions(AttendanceActions::forRecords())
            // HROne parity TX-7 (§24.5.2 #1): the employee's attendance calendar from Employee 360, for the HR side.
            ->headerActions([
                Action::make('calendar')->label('Attendance calendar')->icon('heroicon-m-calendar-days')->color('gray')
                    ->visible(fn () => TimeOfficeRegisters::hrSide('attendance.view') && AttendanceCalendar::canAccess())
                    ->url(fn () => AttendanceCalendar::getUrl(['employee' => $this->getOwnerRecord()->getKey(), 'month' => now()->format('Y-m')])),
            ])
            ->emptyStateHeading('No attendance yet')
            ->emptyStateDescription('Records appear once punches are processed. Assign a work schedule from the Life events menu.');
    }
}
