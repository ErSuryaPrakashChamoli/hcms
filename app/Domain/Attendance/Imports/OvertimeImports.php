<?php

namespace App\Domain\Attendance\Imports;

use App\Domain\Attendance\Models\AttendanceRecord;
use App\Domain\Attendance\Models\TimeOfficeRequest;
use App\Domain\Attendance\Services\TimeOfficeRequests;
use App\Domain\Employment\Imports\EmployeeImport;
use App\Domain\Employment\Imports\EmployeeImportRow;
use App\Domain\Employment\Imports\RowImports;
use App\Domain\Employment\Models\Employee;
use App\Domain\Identity\Models\User;
use App\Domain\Platform\Services\SettingsRepository;

/**
 * HROne parity HP-1b: approved overtime from a file. Each row becomes an overtime claim approved in the importer's
 * name (the day's approved overtime), so the minutes stay traceable to a request and reverse with it.
 */
final class OvertimeImports extends RowImports
{
    public const TYPE = 'overtime_hours';

    public static function fields(): array
    {
        return ['employee_code' => ['Employee code', true], 'date' => ['Date', true], 'minutes' => ['Overtime minutes', false], 'hours' => ['Overtime hours', false], 'reason' => ['Reason', false]];
    }

    public function label(): string
    {
        return 'Overtime upload';
    }

    protected function module(): string
    {
        return 'attendance';
    }

    protected function entityType(): string
    {
        return TimeOfficeRequest::class;
    }

    protected function aliases(): array
    {
        return ['ot_minutes' => 'minutes', 'ot_hours' => 'hours', 'overtime' => 'hours'];
    }

    protected function check(array $r, array &$context, EmployeeImportRow $row, EmployeeImport $import): array
    {
        [$employeeId, $errors] = $this->employeeOf($r, $context);
        $date = $this->date($r['date'] ?? null);
        if ($date === null || $date > now()->toDateString()) {
            $errors[] = 'Date must be a past date (YYYY-MM-DD).';
        }
        $minutes = $this->minutes($r);
        if ($minutes === null || $minutes <= 0 || $minutes > 960) {
            $errors[] = 'Give overtime minutes or hours between 1 minute and 16 hours.';
        }
        if ($errors !== []) {
            return ['action' => 'error', 'errors' => $errors, 'employee_id' => $employeeId];
        }
        $key = "{$employeeId}|{$date}";
        if (isset($context['seen'][$key])) {
            return ['action' => 'error', 'errors' => ['The same day is in row '.$context['seen'][$key].'.'], 'employee_id' => $employeeId];
        }
        $context['seen'][$key] = $row->row_number;
        if (! (bool) app(SettingsRepository::class)->get('attendance.allow_corrections_after_lock', false)
            && AttendanceRecord::query()->where('employee_id', $employeeId)->whereDate('date', $date)->where('is_locked', true)->exists()) {
            return ['action' => 'error', 'errors' => ['The day is locked for payroll.'], 'employee_id' => $employeeId];
        }
        if (TimeOfficeRequest::query()->where('employee_id', $employeeId)->where('type', 'overtime')->whereIn('status', ['pending', ...TimeOfficeRequest::EFFECTIVE])->whereDate('from_date', $date)->exists()) {
            return ['action' => 'skip', 'match' => ['reason' => 'An overtime claim for this day is already on file.'], 'employee_id' => $employeeId];
        }

        return ['action' => 'create', 'employee_id' => $employeeId];
    }

    protected function apply(array $r, EmployeeImportRow $row, EmployeeImport $import, User $user): string
    {
        $claim = app(TimeOfficeRequests::class)->recordApproved(Employee::query()->findOrFail($row->employee_id), 'overtime', ['from_date' => $this->date($r['date']), 'minutes' => $this->minutes($r)], $r['reason'] ?? $this->label()." #{$import->getKey()}", $user);

        return "{$claim->minutes} minute(s) approved";
    }

    private function minutes(array $r): ?int
    {
        if (isset($r['minutes']) && is_numeric($r['minutes'])) {
            return (int) round((float) $r['minutes']);
        }
        if (isset($r['hours']) && preg_match('/^(\d+):(\d{2})$/', $r['hours'], $m)) {
            return (int) $m[1] * 60 + (int) $m[2];
        }

        return isset($r['hours']) && is_numeric($r['hours']) ? (int) round((float) $r['hours'] * 60) : null;
    }

    public function format(): array
    {
        return [
            'columns' => [
                ['Employee code', 'Yes', "The employee's code.", 'EMP0001'],
                ['Date', 'Yes', 'The day worked, YYYY-MM-DD (not in the future).', now()->subDay()->toDateString()],
                ['Overtime minutes', 'No', 'Approved overtime in minutes (or give hours).', '90'],
                ['Overtime hours', 'No', 'Approved overtime in hours (1.5) or hours:minutes (01:30).', ''],
                ['Reason', 'No', 'Shown on the claim.', 'Month-end close'],
            ],
            'notes' => [
                'Each row becomes an approved overtime claim for the day; cancelling the claim takes the overtime back.',
                'Days that already have an overtime claim are skipped; days locked by payroll are refused.',
            ],
        ];
    }

    protected function examples(): array
    {
        $code = Employee::query()->orderBy('id')->value('employee_code') ?? 'EMP0001';

        return [[$code, now()->subDay()->toDateString(), '90', '', 'Month-end close']];
    }
}
