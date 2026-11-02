<?php

namespace App\Domain\Attendance\Imports;

use App\Domain\Attendance\Imports\Concerns\ReadsAttendanceMonths;
use App\Domain\Attendance\Models\PaidDayAdjustment;
use App\Domain\Attendance\Services\PaidDayAdjustments;
use App\Domain\Employment\Imports\EmployeeImport;
use App\Domain\Employment\Imports\EmployeeImportRow;
use App\Domain\Employment\Imports\RowImports;
use App\Domain\Employment\Models\Employee;
use App\Domain\Identity\Models\User;
use Carbon\CarbonImmutable;
use RuntimeException;

/**
 * Data import DI-35 ("Override paid days", HROne Import → Time Office; matrix 24 §24.9 #5, TX-13 with TX-4): one row per
 * employee and attendance month becomes a paid-day adjustment through PaidDayAdjustments::save — the same preview, rights
 * (attendance.override, access scope, never one's own), lock and month-close rules and rounding as "Adjust paid days" on
 * the Paid days register. Mode set: the paid days of the month (or the count of chosen categories); mode reduce: per
 * category "full" or a percentage. A month that already has a counting adjustment is an "update" (the new one replaces it).
 *
 * Behind attendance.paid_day_adjustments.enabled (off by default): while it is off every row is an error naming the switch.
 */
final class PaidDayAdjustmentImports extends RowImports
{
    use ReadsAttendanceMonths;

    public const TYPE = 'paid_day_adjustments';

    /** field => the adjustment category it holds */
    private const CATEGORY_FIELDS = ['present' => 'present', 'paid_leave' => 'paid_leave', 'holidays' => 'holiday', 'weekly_offs' => 'weekly_off'];

    public static function fields(): array
    {
        return ['employee_code' => ['Employee code', true], 'month' => ['Month (YYYY-MM)', true], 'mode' => ['Mode (set/reduce)', false],
            'paid_days' => ['Paid days', false], 'present' => ['Present days', false], 'paid_leave' => ['Paid leave', false],
            'holidays' => ['Holidays', false], 'weekly_offs' => ['Weekly offs', false], 'reason' => ['Reason', true], 'company_code' => ['Company code', false]];
    }

    public function label(): string
    {
        return 'Paid days upload';
    }

    protected function module(): string
    {
        return 'attendance';
    }

    protected function entityType(): string
    {
        return PaidDayAdjustment::class;
    }

    protected function aliases(): array
    {
        return ['attendance_month' => 'month', 'paid_day' => 'paid_days', 'present_day' => 'present', 'week_offs' => 'weekly_offs', 'weekly_off' => 'weekly_offs',
            'holiday' => 'holidays', 'remarks' => 'reason', 'company' => 'company_code', 'adjustment' => 'mode'];
    }

    protected function prepare(EmployeeImport $import): array
    {
        return parent::prepare($import) + ['actor' => User::query()->find($import->uploaded_by)];
    }

    protected function check(array $r, array &$context, EmployeeImportRow $row, EmployeeImport $import): array
    {
        $service = app(PaidDayAdjustments::class);
        if (! $service->enabled()) {
            return ['action' => 'error', 'errors' => ['Paid-day adjustments are switched off (Setup → Time office → "Paid-day adjustments", attendance.paid_day_adjustments.enabled).']];
        }
        [$employeeId, $errors] = $this->employeeOf($r, $context);
        $month = $this->monthOf($r['month'] ?? null);
        if ($month === null) {
            $errors[] = 'Month must be a month such as '.CarbonImmutable::today()->format('Y-m').' (YYYY-MM).';
        }
        if (trim((string) ($r['reason'] ?? '')) === '') {
            $errors[] = 'Reason is required.';
        }
        $input = null;
        try {
            $input = $this->input($r);
        } catch (RuntimeException $e) {
            $errors[] = $e->getMessage();
        }
        $companyId = $this->companyOf($r['company_code'] ?? null, $employeeId, $context, $errors);
        if ($errors !== [] || $employeeId === null || $companyId === null || $month === null || $input === null) {
            return ['action' => 'error', 'errors' => $errors ?: ['The row could not be read.'], 'employee_id' => $employeeId];
        }
        [$year, $number] = $month;
        $key = "{$employeeId}|{$companyId}|{$year}|{$number}";
        if (isset($context['seen'][$key])) {
            return ['action' => 'error', 'errors' => ['The same employee and month are in row '.$context['seen'][$key].'.'], 'employee_id' => $employeeId];
        }
        $context['seen'][$key] = $row->row_number;

        $preview = $service->preview(Employee::query()->findOrFail($employeeId), $companyId, $year, $number, $input, $context['actor']);
        if ($preview['refusal'] !== null) {
            return ['action' => 'error', 'errors' => [$preview['refusal']], 'employee_id' => $employeeId];
        }
        $current = $preview['replaces'];
        $match = ['label' => $preview['label'], 'before' => $preview['effect']['before'], 'after' => $preview['effect']['after']];
        if ($current === null) {
            return ['action' => 'create', 'match' => $match, 'employee_id' => $employeeId];
        }
        $same = $current->mode === $preview['input']['mode'] && (array) ($current->categories ?? []) == $preview['input']['categories']
            && ($preview['input']['categories'] !== [] || abs((float) $current->value - (float) $preview['input']['value']) < 0.001);

        return $same
            ? ['action' => 'skip', 'match' => $match + ['id' => $current->getKey(), 'reason' => $preview['label'].' is already adjusted this way.'], 'employee_id' => $employeeId]
            : ['action' => 'update', 'match' => $match + ['id' => $current->getKey()], 'employee_id' => $employeeId];
    }

    protected function apply(array $r, EmployeeImportRow $row, EmployeeImport $import, User $user): string
    {
        $errors = [];
        $context = [];
        $companyId = $this->companyOf($r['company_code'] ?? null, (int) $row->employee_id, $context, $errors);
        [$year, $month] = $this->monthOf($r['month']);
        $adjustment = app(PaidDayAdjustments::class)->save(Employee::query()->findOrFail($row->employee_id), (int) $companyId, $year, $month, $this->input($r), $r['reason'], $user);

        return $this->monthLabel($year, $month).': paid days '.PaidDayAdjustment::number((float) $adjustment->paid_days_before).' → '.PaidDayAdjustment::number((float) $adjustment->paid_days_after);
    }

    /**
     * The row as PaidDayAdjustments takes it (mode, categories, value). @return array<string, mixed>
     *
     * @throws RuntimeException when the row mixes the modes' columns
     */
    private function input(array $r): array
    {
        $mode = mb_strtolower(trim((string) ($r['mode'] ?? 'set')));
        if (! array_key_exists($mode, PaidDayAdjustment::MODES)) {
            throw new RuntimeException('Mode must be set or reduce.');
        }
        $given = array_filter(array_intersect_key($r, self::CATEGORY_FIELDS), fn ($v) => trim((string) $v) !== '');
        if ($mode === 'set') {
            $categories = [];
            foreach ($given as $field => $value) {
                $categories[self::CATEGORY_FIELDS[$field]] = $value;
            }
            if ($categories !== [] && isset($r['paid_days'])) {
                throw new RuntimeException('Give the paid days, or the count of chosen days — not both.');
            }

            return ['mode' => 'set', 'categories' => $categories, 'value' => $r['paid_days'] ?? null];
        }
        if (isset($r['paid_days'])) {
            throw new RuntimeException('A reduction takes "full" or a percentage per kind of day, not the paid days.');
        }
        $categories = [];
        foreach ($given as $field => $value) {
            $value = mb_strtolower(trim((string) $value));
            $categories[self::CATEGORY_FIELDS[$field]] = match (true) {
                in_array($value, ['full', '100', '100%'], true) => ['how' => 'full'],
                in_array($value, ['none', 'no', '0', '0%'], true) => ['how' => 'none'],
                is_numeric(rtrim($value, '%')) => ['how' => 'partial', 'percent' => (float) rtrim($value, '%')],
                default => throw new RuntimeException(self::fields()[$field][0].' must be full, none or a percentage such as 50%.'),
            };
        }

        return ['mode' => 'reduce', 'categories' => $categories];
    }

    public function format(): array
    {
        return [
            'columns' => [
                ['Employee code', 'Yes', "The employee's code.", 'EMP0001'],
                ['Month (YYYY-MM)', 'Yes', 'The attendance month (YYYY-MM; also MM-YYYY or "Aug 2026"). It must not be finalized, locked or closed.', CarbonImmutable::today()->format('Y-m')],
                ['Mode (set/reduce)', 'No', 'set (default): fix the paid days or the count of chosen days. reduce: take away a share of chosen days.', 'set'],
                ['Paid days', 'No', 'Mode set: the paid days of the month (leave the four counts blank).', '26'],
                ['Present days', 'No', 'Mode set: the count of present days. Mode reduce: full, none or a percentage such as 50%. Blank keeps the processed figure.', ''],
                ['Paid leave', 'No', 'As Present days, for paid leave.', ''],
                ['Holidays', 'No', 'As Present days, for holidays.', ''],
                ['Weekly offs', 'No', 'As Present days, for weekly offs.', ''],
                ['Reason', 'Yes', 'Why the paid days change (kept on the adjustment and in the audit trail).', 'Agreed with the department head'],
                ['Company code', 'No', 'The company of the attendance month; blank is the company the employee works for now.', ''],
            ],
            'notes' => [
                'Works only while paid-day adjustments are switched on (Setup → Time office); until then every row is refused.',
                'One adjustment counts per employee and month: a row for a month that already has one replaces it (the earlier one is kept as reversed).',
                'Partial reductions are rounded as the employee\'s attendance policy says (Paid days round-off). Late-mark penalty days still apply on top.',
                'Needs the right to override attendance, the employee in your access, and never your own paid days.',
            ],
        ];
    }

    protected function examples(): array
    {
        $codes = Employee::query()->orderBy('id')->limit(2)->pluck('employee_code')->all() ?: ['EMP0001'];
        $month = CarbonImmutable::today();
        // A second employee in the same month, or the same employee's month before (one adjustment per employee and month).
        $second = isset($codes[1]) ? [$codes[1], $month] : [$codes[0], $month->subMonthNoOverflow()];

        return [
            [$codes[0], $month->format('Y-m'), 'set', '26', '', '', '', '', 'Agreed with the department head', ''],
            [$second[0], $second[1]->format('Y-m'), 'reduce', '', '', '', '', '50%', 'Weekly offs between unpaid absences', ''],
        ];
    }
}
