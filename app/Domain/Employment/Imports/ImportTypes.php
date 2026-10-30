<?php

namespace App\Domain\Employment\Imports;

use App\Domain\Attendance\Imports\AttendanceSheetImports;
use App\Domain\Attendance\Imports\PunchImports;
use App\Domain\Compensation\Imports\SalaryImports;
use App\Domain\Identity\Models\User;

/**
 * HROne setup parity P2: every upload type on the staged pipeline (employee_imports.type) in one list — the five
 * uploads with their own screens and the one-row-one-change uploads of RowImportTypes — with its name, its module
 * (HROne's Workforce / Time office / Payroll) and who may work on it. Read by import approval (imports.approval_types)
 * and the Recent uploads page.
 */
final class ImportTypes
{
    /** type => [label, module] for the uploads with a screen of their own. */
    public const BASE = [
        'employees' => ['Employee import', 'workforce'],
        BankAccountImports::TYPE => ['Bank details upload', 'workforce'],
        PunchImports::TYPE => ['Punch import', 'time_office'],
        AttendanceSheetImports::TYPE => ['Monthly attendance upload', 'time_office'],
        SalaryImports::TYPE => ['Salary upload', 'payroll'],
    ];

    public const MODULES = ['workforce' => 'Workforce', 'time_office' => 'Time office', 'payroll' => 'Payroll'];

    /** type => the service that runs uploads of that type (the one-row-one-change uploads come from RowImportTypes). */
    private const SERVICES = [
        'employees' => EmployeeImports::class,
        BankAccountImports::TYPE => BankAccountImports::class,
        PunchImports::TYPE => PunchImports::class,
        AttendanceSheetImports::TYPE => AttendanceSheetImports::class,
        SalaryImports::TYPE => SalaryImports::class,
    ];

    /** The service that approves, runs and discards uploads of this type. */
    public static function service(string $type): EmployeeImports|BankAccountImports|PunchImports|AttendanceSheetImports|SalaryImports|RowImports
    {
        return app(self::SERVICES[$type] ?? RowImportTypes::TYPES[$type][0] ?? EmployeeImports::class);
    }

    /** @return array<string, string> type => label, every upload type */
    public static function labels(): array
    {
        $labels = array_map(fn (array $def) => $def[0], self::BASE);
        foreach (RowImportTypes::TYPES as $type => $def) {
            $labels[$type] = $def[1];
        }

        return $labels;
    }

    public static function label(string $type): string
    {
        return self::labels()[$type] ?? ucfirst(str_replace('_', ' ', $type));
    }

    /** The module key (workforce | time_office | payroll) an upload type belongs to. */
    public static function module(string $type): ?string
    {
        return self::BASE[$type][1] ?? RowImportTypes::TYPES[$type][3] ?? null;
    }

    public static function moduleLabel(string $type): ?string
    {
        $module = self::module($type);

        return $module === null ? null : (self::MODULES[$module] ?? $module);
    }

    /** Whether the user may work on uploads of this type (the checks of each upload screen). */
    public static function allowed(?User $user, string $type): bool
    {
        if ($user === null) {
            return false;
        }

        return match ($type) {
            'employees' => EmployeeImports::enabled() && $user->hasPermission('employee.import'),
            BankAccountImports::TYPE => BankAccountImports::allowed($user),
            SalaryImports::TYPE => SalaryImports::allowed($user),
            AttendanceSheetImports::TYPE => AttendanceSheetImports::allowed($user),
            PunchImports::TYPE => $user->hasPermission('attendance.punch') && $user->hasPermission('employee.import'),
            default => RowImportTypes::allowed($user, $type),
        };
    }

    /** @return list<string> the upload types this user may work on */
    public static function allowedTypes(?User $user): array
    {
        return array_values(array_filter(array_keys(self::labels()), fn (string $type) => self::allowed($user, $type)));
    }
}
