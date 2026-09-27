<?php

namespace App\Filament\Resources\AttendanceRecords\Pages;

use App\Domain\Attendance\Services\AttendanceProcessor;
use App\Domain\Attendance\Services\PunchIngestion;
use App\Domain\Employment\Models\Employee;
use App\Filament\Resources\AttendanceRecords\AttendanceRecordResource;
use App\Filament\Resources\Employees\Pages\CreateEmployee;
use Filament\Actions\Action;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ListRecords;
use Filament\Support\Icons\Heroicon;

class ListAttendanceRecords extends ListRecords
{
    protected static string $resource = AttendanceRecordResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('manualPunch')->label('Manual punch')->icon(Heroicon::OutlinedFingerPrint)
                ->authorize(fn () => auth()->user()->can('attendance.manage'))
                ->schema([
                    Select::make('employee_id')->label('Employee')->options(fn () => CreateEmployee::managerOptions())->searchable()->required(),
                    DateTimePicker::make('punched_at')->seconds(false)->native(false)->required()->default(now()),
                    Select::make('direction')->options(['auto' => 'Auto', 'in' => 'In', 'out' => 'Out'])->default('auto'),
                    Textarea::make('note')->required()->maxLength(255)->helperText('Why this punch is being recorded by hand.'),
                ])
                ->action(function (array $data) {
                    $employee = Employee::query()->findOrFail($data['employee_id']);
                    $punch = app(PunchIngestion::class)->record($employee, $data['punched_at'], $data['direction'], 'manual', null, null, [], $data['note']);
                    app(AttendanceProcessor::class)->process($employee, $data['punched_at']);
                    Notification::make()->success()->title($punch ? 'Punch recorded and day reprocessed' : 'Duplicate punch ignored')->send();
                }),
            Action::make('process')->label('Process day')->icon(Heroicon::OutlinedArrowPath)
                ->authorize(fn () => auth()->user()->can('attendance.manage'))
                ->schema([DatePicker::make('date')->native(false)->required()->default(now()->subDay())])
                ->action(function (array $data) {
                    $count = app(AttendanceProcessor::class)->processAll($data['date']);
                    Notification::make()->success()->title("{$count} employee(s) processed for {$data['date']}")->send();
                }),
        ];
    }
}
