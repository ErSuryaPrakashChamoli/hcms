<?php

namespace App\Domain\Attendance\Imports;

use App\Domain\Attendance\Models\AttendanceRecord;
use App\Domain\Attendance\Models\Shift;
use App\Domain\Attendance\Models\ShiftOverride;
use App\Domain\Attendance\Services\AttendanceMonthCloses;
use App\Domain\Attendance\Services\ShiftRoster;
use App\Domain\Employment\Imports\EmployeeImport;
use App\Domain\Employment\Imports\EmployeeImportRow;
use App\Domain\Employment\Imports\RowImports;
use App\Domain\Employment\Models\Employee;
use App\Domain\Identity\Models\User;
use App\Domain\Platform\Services\SettingsRepository;
use Illuminate\Support\Carbon;
use RuntimeException;

/**
 * HROne parity HP-1b: the day-level roster from a file (one row per employee and day or date range).
 * HROne parity TX-5: two optional columns as on the roster board — "Weekly off on" (weekdays of the row's range that
 * become weekly offs; the other days take the row's shift) and "Auto shift correction" (yes / no). A file without them
 * (or with them blank) imports exactly as before.
 */
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
        return ['employee_code' => ['Employee code', true], 'from_date' => ['Date', true], 'to_date' => ['To date', false], 'shift_code' => ['Shift code (WO = weekly off)', false],
            'weekly_off_days' => ['Weekly off on', false], 'auto_shift' => ['Auto shift correction', false]];
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
        return ['date' => 'from_date', 'shift' => 'shift_code', 'from' => 'from_date', 'to' => 'to_date',
            'weekly_off' => 'weekly_off_days', 'weekly_offs' => 'weekly_off_days', 'auto_shift' => 'auto_shift'];
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
        // HROne parity TX-5: the optional weekly-off weekdays and automatic shift correction.
        try {
            self::weeklyOffDays($r);
        } catch (RuntimeException $e) {
            $errors[] = 'Weekly off on: '.$e->getMessage();
        }
        if (isset($r['auto_shift']) && self::autoShift($r) === null) {
            $errors[] = 'Auto shift correction must be yes or no.';
        }
        if ($errors !== []) {
            return ['action' => 'error', 'errors' => $errors, 'employee_id' => $employeeId];
        }
        if (! (bool) app(SettingsRepository::class)->get('attendance.allow_corrections_after_lock', false)
            && AttendanceRecord::query()->where('employee_id', $employeeId)->where('is_locked', true)->whereDate('date', '>=', $from)->whereDate('date', '<=', $to)->exists()) {
            return ['action' => 'error', 'errors' => ['Part of these days is locked (finalized days).'], 'employee_id' => $employeeId];
        }
        // HROne parity TX-3: days of a closed attendance month, like locked days.
        if (! (bool) app(SettingsRepository::class)->get('attendance.allow_corrections_after_lock', false) && app(AttendanceMonthCloses::class)->closedDates((int) $employeeId, $from, $to) !== []) {
            return ['action' => 'error', 'errors' => ['Part of these days is in a closed attendance month.'], 'employee_id' => $employeeId];
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
        $weeklyOff = self::weeklyOffDays($r);
        $count = app(ShiftRoster::class)->assign([$employee], $from, $this->date($r['to_date'] ?? null) ?? $from, $shift, $this->label()." #{$import->getKey()}", $user, 'import',
            $weeklyOff, self::autoShift($r));

        return "{$count} day(s) on ".($shift?->name ?? 'weekly off').($weeklyOff !== [] && $shift !== null ? ', weekly off on '.implode(', ', array_map('ucfirst', $weeklyOff)) : '')
            .(self::autoShift($r) ? ', automatic shift correction allowed' : '');
    }

    /**
     * HROne parity TX-5: the row's weekly-off weekdays ("Sat;Sun", "sat, sun", "Saturday Sunday"); none when blank.
     *
     * @param  array<string, string>  $r
     * @return list<string>
     */
    private static function weeklyOffDays(array $r): array
    {
        $value = trim((string) ($r['weekly_off_days'] ?? ''));

        return $value === '' ? [] : ShiftRoster::weekdays(preg_split('/[\s,;|\/]+/', $value) ?: []);
    }

    /**
     * HROne parity TX-5: the row's automatic shift correction — true / false, null when blank (not chosen: as before) or
     * not a yes / no.
     *
     * @param  array<string, string>  $r
     */
    private static function autoShift(array $r): ?bool
    {
        return match (mb_strtolower(trim((string) ($r['auto_shift'] ?? '')))) {
            'yes', 'y', 'true', '1' => true,
            'no', 'n', 'false', '0' => false,
            default => null,
        };
    }

    public function format(): array
    {
        return [
            'columns' => [
                ['Employee code', 'Yes', "The employee's code.", 'EMP0001'],
                ['Date', 'Yes', 'The day (or first day), YYYY-MM-DD.', now()->addDay()->toDateString()],
                ['To date', 'No', 'Last day of a range; blank is one day.', now()->addDays(5)->toDateString()],
                ['Shift code (WO = weekly off)', 'No', 'Code of the shift to work; WO or blank makes the day a weekly off.', 'GEN'],
                ['Weekly off on', 'No', 'Weekdays of the range that become weekly offs (the other days take the shift), separated by ; or a comma. Blank: every day takes the shift.', 'Sat;Sun'],
                ['Auto shift correction', 'No', 'Yes lets the first check-in pick the nearest shift among this one and those the work schedule lists for automatic correction. Blank or No: the shift as rostered.', 'No'],
            ],
            'notes' => [
                'Rostered days override the employee\'s schedule for those dates only; past days are recalculated.',
                'Locked (finalized) days are refused unless corrections after lock are allowed.',
                'Files without the last two columns import as before.',
            ],
        ];
    }

    protected function examples(): array
    {
        $code = Employee::query()->orderBy('id')->value('employee_code') ?? 'EMP0001';
        $shift = Shift::query()->orderBy('id')->value('code') ?? 'GEN';

        return [[$code, now()->addDay()->toDateString(), now()->addDays(7)->toDateString(), $shift, 'Sat;Sun', 'No'], [$code, now()->addDays(8)->toDateString(), '', 'WO', '', '']];
    }
}
