<?php

namespace App\Filament\Resources\AttendanceOverrides\Pages;

use App\Domain\Attendance\Services\AttendanceOverrides;
use App\Domain\Employment\Models\Employee;
use App\Domain\Experience\BulkOperations\ChangeAttendanceStatusOperation;
use App\Filament\Pages\AttendanceStatusChange;
use App\Filament\Resources\AttendanceOverrides\AttendanceOverrideResource;
use App\Filament\Resources\Employees\Pages\CreateEmployee;
use App\Filament\Support\Pages\Concerns\HasRegisterTools;
use App\Filament\Support\Pages\PeopleListRecords;
use App\Filament\Support\TimeOfficeRegisters;
use Filament\Actions\Action;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Support\Icons\Heroicon;
use RuntimeException;

class ListAttendanceOverrides extends PeopleListRecords
{
    use HasRegisterTools;

    protected static string $resource = AttendanceOverrideResource::class;

    public static function registerKey(): string
    {
        return 'day_overrides';
    }

    protected function getHeaderActions(): array
    {
        return [
            ...$this->registerToolActions(),
            TimeOfficeRegisters::uploads(['day_overrides', 'attendance_sheet']),
            // HROne parity TX-6: a range of days for chosen employees, the whole day or each half.
            Action::make('changeStatus')->label('Change attendance status')->icon(Heroicon::OutlinedAdjustmentsHorizontal)->color('gray')
                ->visible(fn () => AttendanceStatusChange::canAccess())
                ->url(fn () => AttendanceStatusChange::getUrl()),
            Action::make('override')
                ->label('Override a day')
                ->icon(Heroicon::OutlinedPencilSquare)
                ->schema([
                    Select::make('employee_id')->label('Employee')->options(fn () => CreateEmployee::managerOptions())->searchable()->required(),
                    DatePicker::make('date')->native(false)->required()->maxDate(today()),
                    // HROne parity TX-6: the whole day (as before, the default) or each half.
                    ...ChangeAttendanceStatusOperation::statusFields(true),
                    Textarea::make('reason')->required()->maxLength(500),
                ])
                ->action(function (array $data) {
                    try {
                        [$status, $first, $second] = ChangeAttendanceStatusOperation::statusesFrom($data);
                        $record = app(AttendanceOverrides::class)->set(Employee::query()->findOrFail($data['employee_id']), $data['date'], $status, filled($data['worked_minutes'] ?? null) ? (int) $data['worked_minutes'] : null, $data['reason'], null, $first, $second);
                        Notification::make()->success()->title('Day recalculated: '.$record->statusLabel())->send();
                    } catch (RuntimeException $e) {
                        Notification::make()->danger()->title('Not saved')->body($e->getMessage())->send();
                    }
                }),
        ];
    }
}
