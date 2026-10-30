<?php

namespace App\Filament\Resources\AttendancePenalties\Pages;

use App\Filament\Resources\AttendancePenalties\AttendancePenaltyResource;
use App\Filament\Support\Pages\Concerns\HasRegisterTools;
use App\Filament\Support\Pages\PeopleListRecords;
use App\Filament\Support\TimeOfficeRegisters;

class ListAttendancePenalties extends PeopleListRecords
{
    use HasRegisterTools;

    protected static string $resource = AttendancePenaltyResource::class;

    public static function registerKey(): string
    {
        return 'attendance_penalties';
    }

    public static function registerReports(): array
    {
        return ['late_penalties_month', 'late_penalty_days_by_department'];
    }

    /** HROne parity TX-1: views, report for the HR side. */
    protected function getHeaderActions(): array
    {
        return TimeOfficeRegisters::hrSide('attendance.view') ? [...$this->registerToolActions()] : [];
    }
}
