<?php

namespace App\Filament\Resources\AttendanceOverrides\Pages;

use App\Domain\Attendance\Models\AttendanceOverride;
use App\Domain\Attendance\Services\AttendanceOverrides;
use App\Domain\Employment\Models\Employee;
use App\Filament\Resources\AttendanceOverrides\AttendanceOverrideResource;
use App\Filament\Resources\Employees\Pages\CreateEmployee;
use App\Filament\Support\Pages\Concerns\HasRegisterTools;
use App\Filament\Support\Pages\PeopleListRecords;
use App\Filament\Support\TimeOfficeRegisters;
use Filament\Actions\Action;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
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
            Action::make('override')
                ->label('Override a day')
                ->icon(Heroicon::OutlinedPencilSquare)
                ->schema([
                    Select::make('employee_id')->label('Employee')->options(fn () => CreateEmployee::managerOptions())->searchable()->required(),
                    DatePicker::make('date')->native(false)->required()->maxDate(today()),
                    Select::make('status')->label('Status')->options(collect(AttendanceOverride::STATUSES)->mapWithKeys(fn ($s) => [$s => config("peopleos.attendance.statuses.{$s}", $s)]))->placeholder('Keep the calculated status')->native(false),
                    TextInput::make('worked_minutes')->label('Worked minutes')->numeric()->minValue(0)->maxValue(1440)->placeholder('Keep the punched hours'),
                    Textarea::make('reason')->required()->maxLength(500),
                ])
                ->action(function (array $data) {
                    try {
                        $record = app(AttendanceOverrides::class)->set(Employee::query()->findOrFail($data['employee_id']), $data['date'], $data['status'] ?: null, filled($data['worked_minutes'] ?? null) ? (int) $data['worked_minutes'] : null, $data['reason']);
                        Notification::make()->success()->title('Day recalculated: '.$record->statusLabel())->send();
                    } catch (RuntimeException $e) {
                        Notification::make()->danger()->title('Not saved')->body($e->getMessage())->send();
                    }
                }),
        ];
    }
}
