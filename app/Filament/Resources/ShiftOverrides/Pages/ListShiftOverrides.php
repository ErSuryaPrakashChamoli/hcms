<?php

namespace App\Filament\Resources\ShiftOverrides\Pages;

use App\Domain\Attendance\Models\Shift;
use App\Domain\Attendance\Services\ShiftRoster;
use App\Domain\Employment\Models\Employee;
use App\Filament\Resources\Employees\Pages\CreateEmployee;
use App\Filament\Resources\ShiftOverrides\ShiftOverrideResource;
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

class ListShiftOverrides extends PeopleListRecords
{
    use HasRegisterTools;

    protected static string $resource = ShiftOverrideResource::class;

    public static function registerKey(): string
    {
        return 'shift_roster';
    }

    /** HROne parity TX-1: views of the day roster (HR rosters, approved shift requests, weekly offs). */
    public static function presetViews(): array
    {
        return [
            'rostered' => ['label' => 'Rostered by HR', 'filters' => ['source' => ['value' => 'roster']]],
            'requests' => ['label' => 'From approved shift requests', 'filters' => ['source' => ['value' => 'request']]],
            'uploaded' => ['label' => 'From roster uploads', 'filters' => ['source' => ['value' => 'import']]],
        ];
    }

    public static function registerReports(): array
    {
        return ['shift_roster', 'shift_change_requests'];
    }

    protected function getHeaderActions(): array
    {
        return [
            ...$this->registerToolActions(),
            TimeOfficeRegisters::uploads(['shift_roster']),
            Action::make('roster')
                ->label('Roster employees')
                ->icon(Heroicon::OutlinedCalendarDays)
                ->authorize(fn () => auth()->user()->can('attendance.roster'))
                ->schema([
                    Select::make('employee_ids')->label('Employees')->multiple()->options(fn () => CreateEmployee::managerOptions())->searchable()->required(),
                    DatePicker::make('from')->native(false)->required(),
                    DatePicker::make('to')->native(false)->required()->afterOrEqual('from'),
                    Select::make('shift_id')->label('Shift')->options(fn () => Shift::query()->orderBy('name')->pluck('name', 'id'))->placeholder('Weekly off')->native(false)
                        ->helperText('Leave empty to make the days weekly offs.'),
                    Textarea::make('reason')->required()->maxLength(255),
                ])
                ->action(function (array $data) {
                    try {
                        $count = app(ShiftRoster::class)->assign(Employee::query()->whereIn('id', $data['employee_ids'])->get(), $data['from'], $data['to'], $data['shift_id'] ? Shift::query()->find($data['shift_id']) : null, $data['reason']);
                        Notification::make()->success()->title("{$count} day(s) rostered")->send();
                    } catch (RuntimeException $e) {
                        Notification::make()->danger()->title('Not rostered')->body($e->getMessage())->send();
                    }
                }),
        ];
    }
}
