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

    /** HROne parity TX-3: hold and release ("Unprocess" / "Process attendance arrear"). */
    public const BULK_OPERATIONS = ['attendance_arrears.hold', 'attendance_arrears.release'];

    public static function registerKey(): string
    {
        return 'attendance_arrears';
    }

    public static function registerBulkOperations(): array
    {
        return self::BULK_OPERATIONS;
    }

    /** HROne parity TX-3: views of the arrears HR holds or still has to take. */
    public static function presetViews(): array
    {
        return [
            'pending' => ['label' => 'Pending arrears', 'filters' => ['status' => ['value' => 'pending']]],
            'held' => ['label' => 'Held arrears', 'filters' => ['status' => ['value' => 'held']]],
        ];
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
