<?php

namespace App\Filament\Resources\Employees\RelationManagers;

use App\Filament\Support\AttendanceActions;
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
            ->emptyStateHeading('No attendance yet')
            ->emptyStateDescription('Records appear once punches are processed. Assign a work schedule from the Life events menu.');
    }
}
