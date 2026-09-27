<?php

namespace App\Filament\Pages;

use App\Domain\Attendance\Models\AttendancePunch;
use App\Domain\Attendance\Models\AttendanceRecord;
use App\Domain\Attendance\Models\AttendanceRegularisation;
use App\Filament\Support\AttendanceActions;
use BackedEnum;
use Filament\Forms\Components\DatePicker;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use UnitEnum;

/** Attendance Exception Centre (§23): everything that needs a human decision. */
class AttendanceExceptionCentre extends Page implements HasTable
{
    use InteractsWithTable;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedExclamationTriangle;

    protected static string|UnitEnum|null $navigationGroup = 'Attendance';

    protected static ?string $navigationLabel = 'Exception centre';

    protected static ?string $title = 'Attendance exception centre';

    protected static ?int $navigationSort = 5;

    protected string $view = 'filament.pages.attendance-exception-centre';

    public static function canAccess(): bool
    {
        return auth()->user()?->can('attendance.view') ?? false;
    }

    public static function getNavigationBadge(): ?string
    {
        $count = AttendanceRecord::query()->whereNotNull('exceptions')->where('is_locked', false)->whereDate('date', '>=', now()->subDays(30))->count();

        return $count > 0 ? (string) $count : null;
    }

    /** Operational counts for the last 30 days (unlocked days only). @return array<string, int> */
    public function getSummary(): array
    {
        $base = fn () => AttendanceRecord::query()->where('is_locked', false)->whereDate('date', '>=', now()->subDays(30));

        return [
            'exceptions' => (clone $base())->whereNotNull('exceptions')->count(),
            'missing_punch' => (clone $base())->where('status', 'incomplete')->count(),
            'late' => (clone $base())->where('late_minutes', '>', 0)->count(),
            'absent' => (clone $base())->whereJsonContains('exceptions', 'absent')->count(),
            'overtime_pending' => (clone $base())->where('overtime_status', 'pending')->count(),
            'regularisations_pending' => AttendanceRegularisation::query()->where('status', 'pending')->count(),
            'failed_punches' => AttendancePunch::query()->where('processing_status', 'failed')->count(),
        ];
    }

    public function table(Table $table): Table
    {
        return $table
            ->query(fn (): Builder => AttendanceRecord::query()->with(['employee.person', 'shift'])->whereNotNull('exceptions')->where('is_locked', false))
            ->columns(AttendanceActions::columns())
            ->filters([
                Filter::make('date')
                    ->schema([DatePicker::make('from')->native(false)->default(now()->subDays(30)), DatePicker::make('until')->native(false)])
                    ->query(fn (Builder $query, array $data) => $query
                        ->when($data['from'] ?? null, fn (Builder $q, $d) => $q->whereDate('date', '>=', $d))
                        ->when($data['until'] ?? null, fn (Builder $q, $d) => $q->whereDate('date', '<=', $d))),
                SelectFilter::make('exception')
                    ->options(config('peopleos.attendance.exception_types'))
                    ->query(fn (Builder $query, array $data) => $query->when($data['value'] ?? null, fn (Builder $q, $v) => $q->whereJsonContains('exceptions', $v))),
            ])
            ->defaultSort('date', 'desc')
            ->recordActions(AttendanceActions::forRecords())
            ->emptyStateHeading('No attendance exceptions')
            ->emptyStateDescription('Late marks, missed punches, short hours, unexplained absences and overtime waiting for approval appear here.');
    }
}
