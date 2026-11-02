<?php

namespace App\Domain\Attendance\Imports;

use App\Domain\Attendance\Contracts\AttendanceCycle;
use App\Domain\Attendance\Models\AttendanceArrear;
use App\Domain\Attendance\Models\AttendanceRecord;
use App\Domain\Attendance\Services\AttendanceMonthCloses;
use App\Domain\Employment\Imports\EmployeeImport;
use App\Domain\Employment\Imports\EmployeeImportRow;
use App\Domain\Employment\Models\Employee;
use App\Domain\Employment\Models\EmployeePosition;
use App\Domain\Identity\Models\User;
use App\Domain\Identity\Scopes\AccessScope;
use Carbon\CarbonImmutable;
use Throwable;

/**
 * Data import DI-35 ("Override previous month overtime", HROne Import → Time Office; matrix 24 §24.9 #15): approved overtime
 * for days of an earlier attendance month, on the overtime upload's path — each row becomes an overtime claim recorded as
 * approved in the uploader's name (TimeOfficeRequests::recordApproved), with the same checks (one claim per day, minutes
 * from 1 to 960, the day already worked).
 *
 * - A day of an earlier month that is still open: the day is recalculated and its approved overtime counts for its own
 *   month, as with the overtime upload.
 * - A day already locked downstream or in a closed attendance month (decision D35-2): refused unless
 *   attendance.allow_corrections_after_lock is on (the error names the switch). With it on, the claim is recorded and the
 *   locked day is never rewritten; the calculation keeps the approved minutes as an overtime arrear of that day
 *   (attendance_arrears.overtime_minutes), which the next open month takes with its own overtime (AttendanceOutput::
 *   overtimeArrears) — HROne's "previous month overtime".
 * - A day of the current attendance month belongs on the overtime upload ("Override overtime").
 */
final class OvertimeArrearImports extends OvertimeImports
{
    public const TYPE = 'overtime_arrears';

    public function label(): string
    {
        return 'Previous month overtime upload';
    }

    protected function check(array $r, array &$context, EmployeeImportRow $row, EmployeeImport $import): array
    {
        $decision = parent::check($r, $context, $row, $import);
        $employeeId = $decision['employee_id'] ?? null;
        $date = $this->date($r['date'] ?? null);
        if ($employeeId === null || $date === null) {
            return $decision;
        }
        $errors = $decision['errors'] ?? [];
        // The parent refuses locked and closed days while corrections after lock are off: say which switch allows them.
        foreach ($errors as $i => $error) {
            if (str_starts_with($error, 'The day is locked') || $error === 'The day is in a closed attendance month.') {
                $errors[$i] = $error.' Overtime of a closed month is taken only with corrections after lock switched on (Setup → Time office, attendance.allow_corrections_after_lock).';
            }
        }
        $start = $this->currentMonthStart((int) $employeeId);
        if ($start !== null && $date >= $start) {
            $errors[] = 'The day is in the current attendance month: use the Override overtime upload.';
        }

        return $errors === [] ? $decision : ['action' => 'error', 'errors' => array_values(array_unique($errors)), 'employee_id' => $employeeId];
    }

    protected function apply(array $r, EmployeeImportRow $row, EmployeeImport $import, User $user): string
    {
        $text = parent::apply($r, $row, $import, $user);
        $date = $this->date($r['date']);
        $kept = AttendanceRecord::query()->where('employee_id', $row->employee_id)->whereDate('date', $date)->where('is_locked', true)->exists()
            || app(AttendanceMonthCloses::class)->isClosedDay((int) $row->employee_id, $date);

        $arrear = $kept ? AttendanceArrear::query()->where('employee_id', $row->employee_id)->whereDate('date', $date)->whereIn('status', ['pending', 'held'])->value('overtime_minutes') : null;

        return $text.' for '.CarbonImmutable::parse($date)->format('d M Y').($kept ? ' (a closed day: '.(int) $arrear.' minute(s) wait as previous month overtime for the next month)' : '');
    }

    /** The first day of the attendance month holding today, for the employee's company now (null when it has none). */
    private function currentMonthStart(int $employeeId): ?string
    {
        $companyId = EmployeePosition::query()->withoutGlobalScope(AccessScope::class)->where('employee_id', $employeeId)->effectiveOn()
            ->orderByDesc('effective_from')->orderByDesc('id')->value('company_id');
        if ($companyId === null) {
            return CarbonImmutable::today()->startOfMonth()->toDateString();
        }
        try {
            $cycle = app(AttendanceCycle::class);
            [$year, $month] = $cycle->monthOf((int) $companyId, CarbonImmutable::today());

            return $cycle->window((int) $companyId, $year, $month)[0]->toDateString();
        } catch (Throwable) {
            return CarbonImmutable::today()->startOfMonth()->toDateString();
        }
    }

    public function format(): array
    {
        $format = parent::format();
        $format['columns'][1] = ['Date', 'Yes', 'The day worked, YYYY-MM-DD, in an earlier attendance month (days of this month go on the Override overtime upload).', CarbonImmutable::today()->subMonthNoOverflow()->startOfMonth()->addDays(4)->toDateString()];
        $format['notes'] = [
            'Each row becomes an approved overtime claim for a day of an earlier month; cancelling the claim takes the overtime back.',
            'A day of an earlier month that is still open is recalculated, so the overtime counts for that month.',
            'Days locked downstream or in a closed month are refused unless corrections after lock are switched on (Setup → Time office). With it on, the claim is recorded, the locked day is not rewritten, and the minutes wait as previous month overtime that the next open month takes with its own overtime.',
            'Days that already have an overtime claim are skipped.',
        ];

        return $format;
    }

    protected function examples(): array
    {
        $code = Employee::query()->orderBy('id')->value('employee_code') ?? 'EMP0001';
        $month = CarbonImmutable::today()->subMonthNoOverflow()->startOfMonth();

        return [[$code, $month->addDays(4)->toDateString(), '90', '', 'Month-end close of the previous month'], [$code, $month->addDays(11)->toDateString(), '', '02:00', 'Audit support']];
    }
}
