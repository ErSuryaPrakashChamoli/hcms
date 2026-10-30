<?php

namespace App\Filament\Resources\AttendancePenalties;

use App\Domain\Attendance\Models\AttendancePenalty;
use App\Domain\Attendance\Services\LatePenalties;
use App\Domain\Employment\Models\Employee;
use App\Filament\Resources\AttendancePenalties\Pages\ListAttendancePenalties;
use App\Filament\Support\EmployeeFilters;
use App\Filament\Support\TimeOfficeRegisters;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use RuntimeException;
use UnitEnum;

/** HROne parity HP-1: late-mark penalties from the attendance policy; HR may waive one with a reason. */
class AttendancePenaltyResource extends Resource
{
    protected static ?string $model = AttendancePenalty::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedExclamationTriangle;

    protected static string|UnitEnum|null $navigationGroup = 'Attendance';

    protected static ?string $navigationLabel = 'Late-mark penalties';

    protected static ?int $navigationSort = 63;

    public static function canCreate(): bool
    {
        return false;
    }

    public static function getEloquentQuery(): Builder
    {
        $query = parent::getEloquentQuery()->with(['employee.person', 'waiver']);

        return auth()->user()?->can('attendance.view')
            ? $query
            : $query->whereIn('employee_id', Employee::query()->select('id')->where('user_id', auth()->id()));
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                // HROne parity TX-1: the WO-2 employee search (code, names, work e-mail, designation).
                TextColumn::make('employee.person.display_name')->label('Employee')->searchable(query: EmployeeFilters::search('employee'))->description(fn (AttendancePenalty $r) => $r->employee?->employee_code),
                TextColumn::make('date')->date('D, d M Y')->sortable(),
                TextColumn::make('late_count')->label('Late marks')->suffix(fn (AttendancePenalty $r) => ' in '.$r->month),
                TextColumn::make('action')->badge()->formatStateUsing(fn (string $state) => AttendancePenalty::ACTIONS[$state] ?? $state),
                TextColumn::make('days')->label('Days')->numeric(1),
                TextColumn::make('status')->badge()->color(fn (string $state) => $state === 'waived' ? 'gray' : 'danger'),
                TextColumn::make('waive_reason')->label('Waived because')->placeholder('—')->toggleable(),
            ])
            ->filters([
                SelectFilter::make('status')->options(['applied' => 'Applied', 'waived' => 'Waived']),
                SelectFilter::make('month')->options(fn () => AttendancePenalty::query()->distinct()->orderByDesc('month')->pluck('month', 'month')->all()),
                // HROne parity TX-1: the WO-2 employee filters (HR side only; an employee's own list is unchanged).
                ...TimeOfficeRegisters::filters('attendance.view'),
            ])
            ->defaultSort('date', 'desc')
            ->recordActions([
                Action::make('waive')->icon('heroicon-m-hand-raised')->color('gray')
                    ->visible(fn (AttendancePenalty $r) => $r->status === 'applied' && auth()->user()->can('update', $r))
                    ->schema([Textarea::make('reason')->required()->maxLength(500)])
                    ->action(function (AttendancePenalty $record, array $data) {
                        try {
                            app(LatePenalties::class)->waive($record, $data['reason']);
                            Notification::make()->success()->title('Penalty waived')->send();
                        } catch (RuntimeException $e) {
                            Notification::make()->danger()->title('Not allowed')->body($e->getMessage())->send();
                        }
                    }),
            ]);
    }

    public static function getPages(): array
    {
        return ['index' => ListAttendancePenalties::route('/')];
    }
}
