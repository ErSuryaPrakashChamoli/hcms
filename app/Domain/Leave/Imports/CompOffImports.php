<?php

namespace App\Domain\Leave\Imports;

use App\Domain\Attendance\Models\TimeOfficeRequest;
use App\Domain\Attendance\Services\TimeOfficeRequests;
use App\Domain\Employment\Imports\EmployeeImport;
use App\Domain\Employment\Imports\EmployeeImportRow;
use App\Domain\Employment\Imports\RowImports;
use App\Domain\Employment\Models\Employee;
use App\Domain\Identity\Models\User;
use App\Domain\Leave\Models\LeaveType;

/**
 * Data import DI-35 ("Compensatory off", HROne Import → Time Office; matrix 24 §24.9 #13): comp-off earned for days worked,
 * with the date each one expires. Each row becomes a comp-off claim recorded as approved in the uploader's name
 * (TimeOfficeRequests::recordApproved), so the credit is posted by CompOffCredits exactly as for an approved claim from the
 * portal: one ledger entry keyed to the claim, dated the day worked, lapsing first in, first out when it expires, and taken
 * back if the claim is cancelled. Rows only ever add credits (no "update existing"); a day that already has a comp-off
 * claim is skipped.
 */
final class CompOffImports extends RowImports
{
    public const TYPE = 'comp_off_credits';

    public static function fields(): array
    {
        return ['employee_code' => ['Employee code', true], 'worked_on' => ['Date worked', true], 'days' => ['Days (1 or 0.5)', false],
            'expires_on' => ['Expiry date', false], 'reason' => ['Reason', false]];
    }

    public static function intents(): bool
    {
        return false;
    }

    public function label(): string
    {
        return 'Comp-off upload';
    }

    protected function module(): string
    {
        return 'leave';
    }

    protected function entityType(): string
    {
        return TimeOfficeRequest::class;
    }

    protected function aliases(): array
    {
        return ['date' => 'worked_on', 'worked_date' => 'worked_on', 'work_date' => 'worked_on', 'comp_off_date' => 'worked_on',
            'comp_off_days' => 'days', 'expiry' => 'expires_on', 'expires' => 'expires_on', 'valid_till' => 'expires_on', 'valid_until' => 'expires_on', 'remarks' => 'reason'];
    }

    protected function prepare(EmployeeImport $import): array
    {
        return parent::prepare($import) + ['comp_off_type' => LeaveType::query()->where('category', 'comp_off')->where('status', 'active')->value('code')];
    }

    protected function check(array $r, array &$context, EmployeeImportRow $row, EmployeeImport $import): array
    {
        [$employeeId, $errors] = $this->employeeOf($r, $context);
        if ($context['comp_off_type'] === null) {
            $errors[] = 'No compensatory-off leave type is active (Leave → Leave types, category Compensatory off).';
        }
        $worked = $this->date($r['worked_on'] ?? null);
        if ($worked === null || $worked > now()->toDateString()) {
            $errors[] = 'Date worked must be a day already worked (YYYY-MM-DD).';
        }
        $days = $r['days'] ?? '1';
        if (! in_array($days, ['1', '1.0', '1.00', '0.5', '0.50', '.5'], true)) {
            $errors[] = 'Days must be 1 (a full day) or 0.5 (a half day).';
        }
        $expires = null;
        if (isset($r['expires_on'])) {
            $expires = $this->date($r['expires_on']);
            if ($expires === null) {
                $errors[] = 'Expiry date must be a date (YYYY-MM-DD).';
            } elseif ($worked !== null && $expires <= $worked) {
                $errors[] = 'Expiry date must come after the date worked.';
            } elseif ($expires < now()->toDateString()) {
                $errors[] = 'This comp-off has already expired; only credits still valid are loaded.';
            }
        }
        if ($errors !== []) {
            return ['action' => 'error', 'errors' => $errors, 'employee_id' => $employeeId];
        }
        $key = "{$employeeId}|{$worked}";
        if (isset($context['seen'][$key])) {
            return ['action' => 'error', 'errors' => ['The same day worked is in row '.$context['seen'][$key].'.'], 'employee_id' => $employeeId];
        }
        $context['seen'][$key] = $row->row_number;
        if (TimeOfficeRequest::query()->where('employee_id', $employeeId)->where('type', 'comp_off')->whereIn('status', ['pending', ...TimeOfficeRequest::EFFECTIVE])->whereDate('from_date', $worked)->exists()) {
            return ['action' => 'skip', 'match' => ['reason' => 'A comp-off claim for this day is already on file.'], 'employee_id' => $employeeId];
        }
        if (TimeOfficeRequest::query()->where('employee_id', $employeeId)->where('type', 'overtime')->whereIn('status', TimeOfficeRequest::EFFECTIVE)->whereDate('from_date', $worked)->exists()) {
            return ['action' => 'error', 'errors' => ['Overtime was already approved for this day; record one or the other.'], 'employee_id' => $employeeId];
        }

        return ['action' => 'create', 'employee_id' => $employeeId];
    }

    protected function apply(array $r, EmployeeImportRow $row, EmployeeImport $import, User $user): string
    {
        $claim = app(TimeOfficeRequests::class)->recordApproved(Employee::query()->findOrFail($row->employee_id), 'comp_off',
            ['from_date' => $this->date($r['worked_on']), 'days' => (float) ($r['days'] ?? 1), 'expires_on' => isset($r['expires_on']) ? $this->date($r['expires_on']) : null],
            $r['reason'] ?? $this->label()." #{$import->getKey()}", $user);

        return sprintf('%s day(s) of comp-off credited for %s%s', rtrim(rtrim(number_format((float) $claim->days, 2), '0'), '.'), $claim->from_date->format('d M Y'),
            $claim->expires_on ? ', expires '.$claim->expires_on->format('d M Y') : ', never expires');
    }

    public function format(): array
    {
        return [
            'columns' => [
                ['Employee code', 'Yes', "The employee's code.", 'EMP0001'],
                ['Date worked', 'Yes', 'The holiday or weekly off worked, YYYY-MM-DD (not in the future).', now()->subDays(3)->toDateString()],
                ['Days (1 or 0.5)', 'No', '1 for a full day (default), 0.5 for a half day.', '1'],
                ['Expiry date', 'No', 'The last day the comp-off can be taken, after the date worked and not past; blank uses the time-office policy\'s validity (90 days when none is set).', now()->addDays(60)->toDateString()],
                ['Reason', 'No', 'Shown on the claim.', 'Worked the Diwali weekend'],
            ],
            'notes' => [
                'Each row becomes an approved comp-off claim in your name and is credited to the compensatory-off leave type; the leave balance screens show it.',
                'Unused comp-off lapses on its expiry date (oldest first). Cancelling the claim takes the credit back, unless the days were used.',
                'A day that already has a comp-off claim is skipped; a day with approved overtime is refused (one or the other).',
            ],
        ];
    }

    protected function examples(): array
    {
        $codes = Employee::query()->orderBy('id')->limit(2)->pluck('employee_code')->all() ?: ['EMP0001'];
        $saturday = now()->subWeek()->startOfWeek()->addDays(5);

        return [
            [$codes[0], $saturday->toDateString(), '1', $saturday->copy()->addDays(90)->toDateString(), 'Worked the month-end weekend'],
            [$codes[1] ?? $codes[0], $saturday->copy()->addDay()->toDateString(), '0.5', '', 'Half day on Sunday for the release'],
        ];
    }
}
