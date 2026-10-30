<?php

namespace App\Domain\Employment\Imports;

use App\Domain\Attendance\Imports\DayOverrideImports;
use App\Domain\Attendance\Imports\GeoLocationImports;
use App\Domain\Attendance\Imports\OvertimeImports;
use App\Domain\Attendance\Imports\ShiftRosterImports;
use App\Domain\Compliance\Imports\OutsideIncomeImports;
use App\Domain\Compliance\Imports\PerquisiteImports;
use App\Domain\Compliance\Imports\PfOptionImports;
use App\Domain\Compliance\Imports\StatutoryOverrideImports;
use App\Domain\Identity\Models\User;
use App\Domain\Leave\Imports\LeaveBalanceImports;
use App\Domain\Leave\Imports\LeaveHistoryImports;

/**
 * The one-row-one-change uploads of the HROne parity programme (RowImports subclasses): their service and the
 * module permission an uploader needs besides employee.import, and the upload screen that lists them. Read by the
 * import policy, ImportActions and the Time office / Payroll upload screens.
 */
final class RowImportTypes
{
    /** type => [service class, label, module permission, upload screen (time_office | payroll)] */
    public const TYPES = [
        // HROne parity TX-2: the narrower keys (leave.adjust, attendance.roster / override) are also granted by leave.manage /
        // attendance.manage, so every uploader keeps these uploads.
        LeaveBalanceImports::TYPE => [LeaveBalanceImports::class, 'Leave balance upload', 'leave.adjust', 'time_office'],
        LeaveHistoryImports::TYPE => [LeaveHistoryImports::class, 'Leave history upload', 'leave.manage', 'time_office'],
        ShiftRosterImports::TYPE => [ShiftRosterImports::class, 'Shift roster upload', 'attendance.roster', 'time_office'],
        GeoLocationImports::TYPE => [GeoLocationImports::class, 'Geo location upload', 'attendance.manage', 'time_office'],
        OvertimeImports::TYPE => [OvertimeImports::class, 'Overtime upload', 'attendance.manage', 'time_office'],
        DayOverrideImports::TYPE => [DayOverrideImports::class, 'Day override upload', 'attendance.override', 'time_office'],
        // HROne parity HP-3 (PY-30).
        PerquisiteImports::TYPE => [PerquisiteImports::class, 'Perquisite upload', 'payroll.manage', 'payroll'],
        OutsideIncomeImports::TYPE => [OutsideIncomeImports::class, 'Income outside payroll upload', 'payroll.manage', 'payroll'],
        StatutoryOverrideImports::TYPE => [StatutoryOverrideImports::class, 'Statutory override upload', 'payroll.override', 'payroll'],
        PfOptionImports::TYPE => [PfOptionImports::class, 'PF options (VPF) upload', 'payroll.manage', 'payroll'],
    ];

    public static function has(string $type): bool
    {
        return isset(self::TYPES[$type]);
    }

    public static function allowed(?User $user, string $type): bool
    {
        return $user !== null && isset(self::TYPES[$type]) && $user->hasPermission('employee.import') && $user->hasPermission(self::TYPES[$type][2]);
    }

    /** @return list<string> the types this user may upload (on one upload screen, or all) */
    public static function allowedTypes(?User $user, ?string $area = null): array
    {
        return array_values(array_filter(array_keys(self::TYPES), fn (string $type) => ($area === null || self::TYPES[$type][3] === $area) && self::allowed($user, $type)));
    }
}
