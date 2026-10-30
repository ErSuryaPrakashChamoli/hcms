<?php

namespace App\Domain\Attendance\Imports;

use App\Domain\Attendance\Models\AttendanceRecord;
use App\Domain\Attendance\Models\Shift;
use App\Domain\Attendance\Models\ShiftOverride;
use App\Domain\Attendance\Services\ShiftRoster;
use App\Domain\Employment\Imports\EmployeeImport;
use App\Domain\Employment\Imports\EmployeeImportRow;
use App\Domain\Employment\Imports\RowImports;
use App\Domain\Employment\Models\Employee;
use App\Domain\Identity\Models\User;
use App\Domain\Platform\Services\SettingsRepository;
use Illuminate\Support\Carbon;

/** HROne parity HP-1b: the day-level roster from a file (one row per employee and day or date range). */
final class ShiftRosterImports extends RowImports
{
    public const TYPE = 'shift_roster';

    public const MODES = [
        'both' => ['Add and change', 'Roster the days as the file says, replacing what is rostered.'],
        'new' => ['Only days not rostered yet', 'Leave days already on the roster as they are.'],
        'update' => ['Only days already rostered', 'Change rostered days only.'],
    ];

    public static function fields(): array
    {
        return ['employee_code' => ['Employee code', true], 'from_date' => ['Date', true], 'to_date' => ['To date', false], 'shift_code' => ['Shift code (WO = weekly off)', false]];
    }

    public function label(): string
    {
        return 'Shift roster upload';
    }

    protected function module(): string
    {
        return 'attendance';
    }

    protected function entityType(): string
    {
        return ShiftOverride::class;
    }

    protected function aliases(): array
    {
        return ['date' => 'from_date', 'shift' => 'shift_code', 'from' => 'from_date', 'to' => 'to_date'];
    }

    protected function prepare(EmployeeImport $import): array
    {
        return parent::prepare($import) + ['shifts' => Shift::query()->get()->keyBy(fn (Shift $s) => mb_strtolower($s->code))->all()];
    }

    protected function check(array $r, array &$context, EmployeeImportRow $row, EmployeeImport $import): array
    {
        [$employeeId, $errors] = $this->employeeOf($r, $context);
        $from = $this->date($r['from_date'] ?? null);
        $to = $this->date($r['to_date'] ?? null) ?? $from;
        if ($from === null) {
            $errors[] = 'Date must be a date (YYYY-MM-DD).';
        } elseif ($to < $from || Carbon::parse($from)->diffInDays(Carbon::parse($to)) > 92) {
            $errors[] = 'The range must run forward and cover at most three months.';
        }
        $code = mb_strtolower($r['shift_code'] ?? 'wo');
        if ($code !== 'wo' && ! isset($context['shifts'][$code])) {
            $errors[] = "Shift code '".($r['shift_code'] ?? '')."' does not exist.";
        }
        if ($errors !== []) {
            return ['action' => 'error', 'errors' => $errors, 'employee_id' => $employeeId];
        }
        if (! (bool) app(SettingsRepository::class)->get('attendance.allow_corrections_after_lock', false)
            && AttendanceRecord::query()->where('employee_id', $employeeId)->where('is_locked', true)->whereDate('date', '>=', $from)->whereDate('date', '<=', $to)->exists()) {
            return ['action' => 'error', 'errors' => ['Part of these days is locked for payroll.'], 'employee_id' => $employeeId];
        }

        $rostered = ShiftOverride::query()->where('employee_id', $employeeId)->whereDate('date', '>=', $from)->whereDate('date', '<=', $to)->exists();

        return ['action' => $rostered ? 'update' : 'create', 'employee_id' => $employeeId];
    }

    protected function apply(array $r, EmployeeImportRow $row, EmployeeImport $import, User $user): string
    {
        $employee = Employee::query()->findOrFail($row->employee_id);
        $code = mb_strtolower($r['shift_code'] ?? 'wo');
        $shift = $code === 'wo' ? null : Shift::query()->whereRaw('lower(code) = ?', [$code])->firstOrFail();
        $from = $this->date($r['from_date']);
        $count = app(ShiftRoster::class)->assign([$employee], $from, $this->date($r['to_date'] ?? null) ?? $from, $shift, $this->label()." #{$import->getKey()}", $user, 'import');

        return "{$count} day(s) on ".($shift?->name ?? 'weekly off');
    }

    public function format(): array
    {
        return [
            'columns' => [
                ['Employee code', 'Yes', "The employee's code.", 'EMP0001'],
                ['Date', 'Yes', 'The day (or first day), YYYY-MM-DD.', now()->addDay()->toDateString()],
                ['To date', 'No', 'Last day of a range; blank is one day.', now()->addDays(5)->toDateString()],
                ['Shift code (WO = weekly off)', 'No', 'Code of the shift to work; WO or blank makes the day a weekly off.', 'GEN'],
            ],
            'notes' => [
                'Rostered days override the employee\'s schedule for those dates only; past days are recalculated.',
                'Days locked by a finalised payroll are refused unless corrections after lock are allowed.',
            ],
        ];
    }

    protected function examples(): array
    {
        $code = Employee::query()->orderBy('id')->value('employee_code') ?? 'EMP0001';
        $shift = Shift::query()->orderBy('id')->value('code') ?? 'GEN';

        return [[$code, now()->addDay()->toDateString(), now()->addDays(5)->toDateString(), $shift], [$code, now()->addDays(6)->toDateString(), '', 'WO']];
    }
}
