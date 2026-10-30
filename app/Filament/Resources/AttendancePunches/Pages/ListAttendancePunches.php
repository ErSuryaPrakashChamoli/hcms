<?php

namespace App\Filament\Resources\AttendancePunches\Pages;

use App\Filament\Resources\AttendancePunches\AttendancePunchResource;
use App\Filament\Support\Pages\Concerns\HasRegisterTools;
use App\Filament\Support\Pages\PeopleListRecords;
use App\Filament\Support\TimeOfficeRegisters;

class ListAttendancePunches extends PeopleListRecords
{
    use HasRegisterTools;

    protected static string $resource = AttendancePunchResource::class;

    public static function registerKey(): string
    {
        return 'attendance_punches';
    }

    public static function registerReports(): array
    {
        return ['punch_log_today', 'punch_failures'];
    }

    /** HROne parity TX-1: views, report and uploads for the HR side. */
    protected function getHeaderActions(): array
    {
        return TimeOfficeRegisters::hrSide('attendance.view') ? [...$this->registerToolActions(), TimeOfficeRegisters::uploads(['punches'])] : [];
    }
}
