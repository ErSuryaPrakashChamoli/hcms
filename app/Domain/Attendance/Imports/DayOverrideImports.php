<?php

namespace App\Domain\Attendance\Imports;

use App\Domain\Attendance\Models\AttendanceOverride;
use App\Domain\Attendance\Models\AttendanceRecord;
use App\Domain\Attendance\Services\AttendanceOverrides;
use App\Domain\Employment\Imports\EmployeeImport;
use App\Domain\Employment\Imports\EmployeeImportRow;
use App\Domain\Employment\Imports\RowImports;
use App\Domain\Employment\Models\Employee;
use App\Domain\Identity\Models\User;
use App\Domain\Platform\Services\SettingsRepository;

/** HROne parity HP-1b: HR day overrides (status and/or working hours) from a file. */
final class DayOverrideImports extends RowImports
{
    public const TYPE = 'day_overrides';

    public const MODES = [
        'both' => ['Add and change', 'Override days, replacing earlier overrides of the same day.'],
        'new' => ['Only days not overridden yet', 'Leave earlier overrides as they are.'],
        'update' => ['Only days already overridden', 'Change earlier overrides only.'],
    ];

    public static function fields(): array
    {
        return ['employee_code' => ['Employee code', true], 'date' => ['Date', true], 'status' => ['Status', false], 'worked_minutes' => ['Worked minutes', false], 'reason' => ['Reason', true]];
    }

    public function label(): string
    {
        return 'Day override upload';
    }

    protected function module(): string
    {
        return 'attendance';
    }

    protected function entityType(): string
    {
        return AttendanceOverride::class;
    }

    protected function aliases(): array
    {
        return ['working_minutes' => 'worked_minutes', 'minutes' => 'worked_minutes', 'remarks' => 'reason'];
    }

    protected function check(array $r, array &$context, EmployeeImportRow $row, EmployeeImport $import): array
    {
        [$employeeId, $errors] = $this->employeeOf($r, $context);
        $date = $this->date($r['date'] ?? null);
        if ($date === null || $date > now()->toDateString()) {
            $errors[] = 'Date must be a day that has started (YYYY-MM-DD).';
        }
        $status = isset($r['status']) ? strtolower(str_replace(' ', '_', $r['status'])) : null;
        if ($status !== null && ! in_array($status, AttendanceOverride::STATUSES, true)) {
            $errors[] = "Status '{$r['status']}' is not one of ".implode(', ', AttendanceOverride::STATUSES).'.';
        }
        if (isset($r['worked_minutes']) && (! ctype_digit($r['worked_minutes']) || (int) $r['worked_minutes'] > 1440)) {
            $errors[] = 'Worked minutes must be a whole number up to 1440.';
        }
        if ($status === null && ! isset($r['worked_minutes'])) {
            $errors[] = 'Give a status, worked minutes, or both.';
        }
        if ($errors !== []) {
            return ['action' => 'error', 'errors' => $errors, 'employee_id' => $employeeId];
        }
        if (! (bool) app(SettingsRepository::class)->get('attendance.allow_corrections_after_lock', false)
            && AttendanceRecord::query()->where('employee_id', $employeeId)->whereDate('date', $date)->where('is_locked', true)->exists()) {
            return ['action' => 'error', 'errors' => ['The day is locked for payroll.'], 'employee_id' => $employeeId];
        }
        $exists = AttendanceOverride::query()->where('employee_id', $employeeId)->whereDate('date', $date)->exists();

        return ['action' => $exists ? 'update' : 'create', 'employee_id' => $employeeId];
    }

    protected function apply(array $r, EmployeeImportRow $row, EmployeeImport $import, User $user): string
    {
        $status = isset($r['status']) ? strtolower(str_replace(' ', '_', $r['status'])) : null;
        $record = app(AttendanceOverrides::class)->set(Employee::query()->findOrFail($row->employee_id), $this->date($r['date']), $status, isset($r['worked_minutes']) ? (int) $r['worked_minutes'] : null, $r['reason'], $user);

        return 'Day now '.$record->statusLabel();
    }

    public function format(): array
    {
        return [
            'columns' => [
                ['Employee code', 'Yes', "The employee's code.", 'EMP0001'],
                ['Date', 'Yes', 'The day, YYYY-MM-DD (today or earlier).', now()->subDay()->toDateString()],
                ['Status', 'No', 'One of '.implode(', ', AttendanceOverride::STATUSES).'; blank keeps the calculated status.', 'present'],
                ['Worked minutes', 'No', 'Working time to use for the day; blank keeps the punched time.', '480'],
                ['Reason', 'Yes', 'Why HR overrides the day.', 'Biometric fault'],
            ],
            'notes' => [
                'Approved full-day leave still wins over an override.',
                'Each override is audited with its reason and the day is recalculated.',
            ],
        ];
    }

    protected function examples(): array
    {
        $code = Employee::query()->orderBy('id')->value('employee_code') ?? 'EMP0001';

        return [[$code, now()->subDay()->toDateString(), 'present', '480', 'Biometric fault']];
    }
}
