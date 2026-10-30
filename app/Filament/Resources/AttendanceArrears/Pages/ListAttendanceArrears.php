<?php

namespace App\Filament\Resources\AttendanceArrears\Pages;

use App\Filament\Resources\AttendanceArrears\AttendanceArrearResource;
use App\Filament\Support\Pages\Concerns\HasRegisterTools;
use App\Filament\Support\Pages\PeopleListRecords;
use App\Filament\Support\TimeOfficeRegisters;

class ListAttendanceArrears extends PeopleListRecords
{
    use HasRegisterTools;

    protected static string $resource = AttendanceArrearResource::class;

    public static function registerKey(): string
    {
        return 'attendance_arrears';
    }

    public static function registerReports(): array
    {
        return ['attendance_arrear_days'];
    }

    /** HROne parity TX-1: views, report for the HR side. */
    protected function getHeaderActions(): array
    {
        return TimeOfficeRegisters::hrSide('attendance.view') ? [...$this->registerToolActions()] : [];
    }
}
