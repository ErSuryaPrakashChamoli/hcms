<?php

namespace App\Domain\Employment\Imports;

use App\Domain\Attendance\Imports\AttendanceSheetImports;
use App\Domain\Attendance\Imports\PunchImports;
use App\Domain\Compensation\Imports\SalaryImports;
use App\Domain\Employment\Enums\ImportMode;
use App\Domain\Identity\Models\User;
use RuntimeException;

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

    /** Module key => name: the heads of the Data import page (ImportCatalogue::HEADS), in that order. */
    public const MODULES = [
        'core' => 'Core', 'workforce' => 'Workforce', 'time_office' => 'Time office', 'payroll' => 'Payroll', 'performance' => 'Performance',
        'engage' => 'Engage', 'asset' => 'Asset', 'projects' => 'Project management', 'integration' => 'Integration',
    ];

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
        return app(self::serviceClass($type));
    }

    /** @return class-string the service class of this type */
    public static function serviceClass(string $type): string
    {
        return self::SERVICES[$type] ?? RowImportTypes::TYPES[$type][0] ?? EmployeeImports::class;
    }

    /**
     * Data import: what a file of this type can be for (mode => [label, description]): the service's own choices, the
     * generic insert / update choice of a one-row-one-change upload, or null for an upload that only adds (punches).
     *
     * @return array<string, array{0: string, 1: string}>|null
     */
    public static function modes(string $type): ?array
    {
        $class = self::serviceClass($type);
        if (defined("{$class}::MODES")) {
            return $class::MODES;
        }

        return is_subclass_of($class, RowImports::class) && $class::intents() ? ImportCatalogue::ROW_MODES : null;
    }

    /**
     * Data import: stage an uploaded file (already on the private disk) as an upload of this type, the way its own
     * screen does. $options: mode, and month / blank_days for the monthly attendance sheet; for one-row-one-change
     * uploads any other option (defaults of the chosen process) is kept on the upload.
     */
    public static function register(string $type, string $path, string $originalName, User $user, array $options = []): EmployeeImport
    {
        if (! self::allowed($user, $type)) {
            throw new RuntimeException('You may not run this upload.');
        }
        $mode = ImportMode::tryFrom((string) ($options['mode'] ?? '')) ?? ImportMode::Both;

        return match ($type) {
            'employees' => app(EmployeeImports::class)->register($path, $originalName, $user, mode: $mode),
            BankAccountImports::TYPE => app(BankAccountImports::class)->register($path, $originalName, $user, mode: $mode),
            SalaryImports::TYPE => app(SalaryImports::class)->register($path, $originalName, $user, mode: $mode),
            AttendanceSheetImports::TYPE => app(AttendanceSheetImports::class)->register($path, $originalName, $user, (string) ($options['month'] ?? AttendanceSheetImports::currentMonth()), $mode, (string) ($options['blank_days'] ?? 'calculate')),
            PunchImports::TYPE => app(PunchImports::class)->register($path, $originalName, $user),
            default => app(RowImportTypes::TYPES[$type][0])->register($path, $originalName, $user, null, array_filter(['mode' => $mode->value] + $options, fn ($v) => $v !== null && $v !== [])),
        };
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
