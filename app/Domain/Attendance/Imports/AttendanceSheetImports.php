<?php

namespace App\Domain\Attendance\Imports;

use App\Domain\Attendance\Contracts\LeaveDayResolver;
use App\Domain\Attendance\Jobs\ProcessAttendanceRange;
use App\Domain\Attendance\Models\AttendanceMark;
use App\Domain\Attendance\Models\AttendanceRecord;
use App\Domain\Audit\Services\AuditRecorder;
use App\Domain\Employment\Enums\ImportMode;
use App\Domain\Employment\Imports\EmployeeImport;
use App\Domain\Employment\Imports\EmployeeImportRow;
use App\Domain\Employment\Imports\EmployeeImports;
use App\Domain\Employment\Models\Employee;
use App\Domain\Identity\Models\User;
use App\Domain\Identity\Scopes\AccessScope;
use App\Domain\Payroll\Contracts\PayCycleReader;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use League\Csv\Reader;
use League\Csv\Writer;
use RuntimeException;
use Throwable;

/**
 * Monthly attendance upload, for organisations whose attendance is not captured by a device: one row per employee,
 * one column per day of the month chosen at upload, a day code in each cell. The same staged pipeline as the other
 * imports (upload → inspect → map → validate → approve → run).
 *
 * A month is a payroll month under the tenant's pay cycle (PayCycleReader): the calendar month, or 25 October to
 * 24 November for November on a 25th-to-24th cycle. A day column is read as the date in the month with that day number.
 * A changeover month longer than a calendar month (25 Oct – 30 Nov) repeats day numbers: the first of each pair is
 * headed by its date (2026-10-25, field date.2026-10-25), the number means the later one. The upload keeps the dates it
 * was made for (options from/to), so a later change of cycle never moves its days.
 *
 * The file never writes attendance days. Running it stores attendance_marks (one per employee and day), which the
 * engine applies when it recalculates those days; days locked by a finalised payroll run are refused. A blank cell
 * is calculated as usual, or read as present on a working day when the upload says so. A cell gives the whole day one
 * code ("P") or each half its own, first half first ("P|A"); one absent half makes a half day.
 *
 * Leave is never marked by upload: employees apply for it on the portal. The sheet has no leave code (leave codes
 * are refused), days of approved full-day leave are left to the leave, and approved leave wins in the engine.
 */
final class AttendanceSheetImports
{
    public const TYPE = 'attendance_month';

    /**
     * Day code => [mark status, meaning]. Codes are read case-insensitively; AB and the meanings' words are accepted too.
     * Every code but HD also marks a half in a split cell ("P|A").
     */
    public const CODES = [
        'P' => ['present', 'Present'],
        'A' => ['absent', 'Absent (a loss-of-pay day in payroll)'],
        'HD' => ['half_day', 'Half day (the same as P|A)'],
        'WO' => ['weekly_off', 'Weekly off'],
        'H' => ['holiday', 'Holiday'],
        'WFH' => ['wfh', 'Work from home'],
        'OD' => ['on_duty', 'On duty'],
    ];

    /** Cells that name leave (also anything containing the word "leave"): refused, because leave is applied for on the portal. */
    public const LEAVE_CODES = ['L', 'PL', 'CL', 'SL', 'EL', 'ML', 'LWP', 'CO', 'COMP OFF'];

    /** What a file can be for: mode => [label, description]. "Uploaded" means an earlier upload marked the day. */
    public const MODES = [
        'new' => ['Fill days not uploaded yet', 'Marks only days no earlier upload has marked for the employee. Days already uploaded are kept.'],
        'update' => ['Correct days already uploaded', 'Replaces only days an earlier upload marked. Days not uploaded before are skipped.'],
        'both' => ['Both', 'Marks every day in the file, replacing earlier uploads.'],
    ];

    /** How a blank cell is read: option => [label, description]. */
    public const BLANK_DAYS = [
        'calculate' => ['Calculate as usual', 'A blank day is worked out from punches, the work schedule and holidays. Without punches, a blank working day counts as absent.'],
        'present' => ['Present on working days', 'A blank working day counts as present; weekly offs, holidays and approved leave stay as they are. Choose this when you only mark absences.'],
    ];

    public function __construct(private readonly AuditRecorder $audit, private readonly LeaveDayResolver $leave) {}

    public static function allowed(?User $user): bool
    {
        return $user !== null && $user->hasPermission('attendance.manage') && $user->hasPermission('employee.import');
    }

    /**
     * The employee code and one field per day of the longest month; for an upload, each day named with its date and a
     * date field for each repeated day of a long changeover month.
     *
     * @param  EmployeeImport|array{0: Carbon, 1: Carbon}|null  $for  an upload, or a month's first and last day
     * @return array<string, array{0: string, 1: bool}>
     */
    public static function fields(EmployeeImport|array|null $for = null): array
    {
        $window = $for instanceof EmployeeImport ? self::windowOf($for) : $for;
        $dates = $window !== null ? self::dayDates(...$window) : [];
        $fields = ['employee_code' => ['Employee code', true]];
        for ($day = 1; $day <= 31; $day++) {
            $fields["day.{$day}"] = ["Day {$day}".(isset($dates[$day]) && $window[0]->day !== 1 ? ' ('.$dates[$day]->format('j M').')' : ''), false];
        }
        foreach ($window !== null ? self::repeated(...$window) : [] as $date) {
            $fields["date.{$date->toDateString()}"] = ["Day {$date->day} ({$date->format('j M')})", false];
        }

        return $fields;
    }

    /** @return array<string, string> months an upload can be for, most recent first: Y-m => "October 2026", or "November 2026 (25 Oct – 24 Nov)" on a pay cycle */
    public static function months(int $count = 13): array
    {
        $months = [];
        for ($i = 0, $month = Carbon::parse(self::currentMonth().'-01'); $i < $count; $i++, $month->subMonthNoOverflow()) {
            $months[$month->format('Y-m')] = self::monthName($month->format('Y-m'), ...self::period($month->format('Y-m'), false));
        }

        return $months;
    }

    /** The payroll month (Y-m) holding today: on a 25th-to-24th cycle, 26 October is in November. */
    public static function currentMonth(): string
    {
        [$year, $month] = app(PayCycleReader::class)->monthOf(today());

        return sprintf('%04d-%02d', $year, $month);
    }

    /**
     * The first and last day of an attendance month given as Y-m, under the pay cycle; never a month that has not started.
     *
     * @return array{0: Carbon, 1: Carbon}
     */
    public static function period(?string $month, bool $started = true): array
    {
        if (! is_string($month) || ! preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $month)) {
            throw new RuntimeException('Choose the attendance month.');
        }
        [$start, $end] = app(PayCycleReader::class)->window((int) substr($month, 0, 4), (int) substr($month, 5, 2));
        if ($started && $start->gt(today())) {
            throw new RuntimeException('The attendance month cannot be in the future.');
        }

        return [Carbon::instance($start), Carbon::instance($end)];
    }

    /** "September 2026", or "November 2026 (25 Oct – 24 Nov)" when the month is not the calendar month. */
    public static function monthName(string $month, Carbon $start, Carbon $end): string
    {
        $calendar = $start->day === 1 && $start->isSameMonth($end) && $end->isSameDay($end->copy()->endOfMonth());

        return Carbon::parse("{$month}-01")->format('F Y').($calendar ? '' : ' ('.$start->format('j M').' – '.$end->format('j M').')');
    }

    /**
     * The days an upload is for: the dates kept when it was made, else the month under the pay cycle as it is now (null
     * when it names no valid month).
     *
     * @return array{0: Carbon, 1: Carbon}|null
     */
    public static function windowOf(EmployeeImport $import): ?array
    {
        $options = $import->options ?? [];
        if (isset($options['from'], $options['to'])) {
            return [Carbon::parse($options['from'])->startOfDay(), Carbon::parse($options['to'])->startOfDay()];
        }
        try {
            return self::period($options['month'] ?? null, false);
        } catch (RuntimeException) {
            return null;
        }
    }

    public function register(string $path, string $originalName, User $user, string $month, ImportMode $mode = ImportMode::Both, string $blankDays = 'calculate', ?string $reason = null): EmployeeImport
    {
        if (! self::allowed($user)) {
            throw new RuntimeException('Uploading attendance needs attendance.manage and employee.import.');
        }
        [$from, $to] = self::period($month);
        if (! isset(self::BLANK_DAYS[$blankDays])) {
            throw new RuntimeException('Choose how blank days are read.');
        }
        $disk = EmployeeImports::disk();
        if (! Storage::disk($disk)->exists($path)) {
            throw new RuntimeException('The uploaded file could not be found on the private disk.');
        }

        $import = new EmployeeImport(['type' => self::TYPE, 'original_name' => $originalName, 'disk' => $disk, 'path' => $path, 'size_bytes' => (int) Storage::disk($disk)->size($path),
            'status' => 'uploaded', 'uploaded_by' => $user->getKey(), 'options' => ['month' => $month, 'from' => $from->toDateString(), 'to' => $to->toDateString(), 'mode' => $mode->value, 'blank_days' => $blankDays]]);
        $import->withAuditReason($reason)->save();

        return $import;
    }

    /**
     * Downloadable sheet for a month: everyone employed during it, with the employee code, the name (not read back)
     * and a blank column per day headed by the day number and weekday.
     */
    public function template(string $month): string
    {
        [$start, $end] = self::period($month);
        $employees = Employee::query()->with('person')->whereDate('joining_date', '<=', $end->toDateString())
            ->where(fn ($q) => $q->whereNull('exit_date')->orWhereDate('exit_date', '>=', $start->toDateString()))->orderBy('employee_code')->get();

        $csv = Writer::fromString();
        $csv->insertOne($this->headers($start, $end));
        foreach ($employees as $employee) {
            $csv->insertOne([$employee->employee_code, $employee->person?->display_name, ...array_fill(0, (int) $start->diffInDays($end) + 1, '')]);
        }

        return $csv->toString();
    }

    /**
     * The format guide the upload screen shows, with an example sheet for the current month.
     *
     * @return array{columns: list<array{0: string, 1: string, 2: string, 3: string}>, codes: array<string, string>, notes: list<string>, sample: string}
     */
    public function format(): array
    {
        $month = self::currentMonth();
        [$start, $end] = self::period($month, false);
        $example = $start->copy()->addDays(4);
        $calendar = self::monthName($month, $start, $end) === Carbon::parse("{$month}-01")->format('F Y');

        return [
            'columns' => [
                ['Employee code', 'Yes', 'The employee\'s code.', 'EMP0001'],
                ['Employee name', 'No', 'Not read: it helps you find people in the sheet.', 'Asha Rao'],
                [$calendar ? '1 … 31' : "{$start->day} … {$end->day}", 'No', 'One column per day of the month you upload for, headed by the day number: '.$example->day.', Day '.$example->day.', '.$example->format('j D').' or the date '.$example->toDateString().'. Enter a day code for the whole day, or a code for each half as first half|second half (P|A), or leave the cell blank.', 'P or P|A'],
            ],
            'codes' => collect(self::CODES)->map(fn (array $code) => $code[1])->all() + ['AB' => 'Absent (the same as A)',
                'P|P' => 'Each half on its own, first half first: P|A is present in the morning and absent in the afternoon. A half takes any code but HD.'],
            'notes' => [
                'One file is one month, which you choose when you upload. Download template gives the month\'s sheet with everyone employed in it.',
                $calendar ? 'Months follow the pay cycle (Payroll → Pay cycle), now the calendar month.'
                    : 'Months follow the pay cycle (Payroll → Pay cycle): '.self::monthName($month, $start, $end).', so the sheet\'s day columns run '.$start->day.', '.$start->copy()->addDay()->day.' … '.$end->day.'.',
                'A changeover month longer than a calendar month repeats day numbers: the template heads the first of each pair with its date (such as 2026-10-25), and the number alone means the later one.',
                'In payroll, a day with both halves absent (A or A|A) is a day of loss of pay; a day with one absent half (HD, P|A, A|WFH, …) is half a day.',
                'Blank cells follow your choice at upload: calculated as usual (a working day without punches is absent) or present on working days.',
                'Leave cannot be marked in the sheet: employees apply for leave on the portal. Days of approved full-day leave are left as leave, whatever the sheet says; on a half-day leave, the code is for the other half.',
                'Leave days before joining, after the exit date and after today blank. Days locked by a finalised payroll run cannot be changed.',
                'To correct a month, upload the corrected rows again with Correct days already uploaded.',
            ],
            'sample' => $this->sample($start, $end),
        ];
    }

    public function inspect(EmployeeImport $import): EmployeeImport
    {
        $this->assert($import, ['uploaded', 'inspected']);
        $max = (int) config('peopleos.attendance.import_max_rows', 50000);

        $csv = Reader::from(Storage::disk($import->disk)->readStream($import->path));
        $csv->setHeaderOffset(0);
        $headers = array_values(array_map(fn ($h) => trim((string) $h), $csv->getHeader()));
        if ($headers === [] || count(array_filter($headers)) !== count(array_unique(array_filter($headers)))) {
            throw new RuntimeException('The file needs a header row with unique column names.');
        }

        DB::transaction(function () use ($import, $csv, $headers, $max) {
            $import->rows()->delete();
            $count = 0;
            foreach ($csv->getRecords() as $record) {
                if (++$count > $max) {
                    throw new RuntimeException("The file has more than {$max} rows; split it.");
                }
                EmployeeImportRow::create(['employee_import_id' => $import->getKey(), 'row_number' => $count, 'data' => array_map(fn ($v) => is_string($v) ? trim($v) : $v, $record)]);
            }
            $import->update(['headers' => $headers, 'row_count' => $count, 'mapping' => $import->mapping ?: $this->suggestMapping($headers, self::windowOf($import)), 'status' => 'inspected']);
        });

        return $import->refresh();
    }

    /**
     * @param  array{0: Carbon, 1: Carbon}|null  $window  the month's first and last day: a date header outside it is left unmapped
     * @return array<string, string> header => field: the employee code column, and day columns by their number or date
     */
    public function suggestMapping(array $headers, ?array $window = null): array
    {
        $mapping = [];
        foreach ($headers as $header) {
            $field = null;
            if (in_array(Str::slug($header, '_'), ['employee_code', 'code', 'emp_code', 'employee', 'emp_id', 'employee_id'], true)) {
                $field = 'employee_code';
            } elseif (preg_match('/^\d{4}-\d{2}-(\d{2})$/', $header, $m)) {
                $date = Carbon::parse($header)->startOfDay();
                $field = match (true) {
                    $window === null => 'day.'.(int) $m[1],
                    ! $date->betweenIncluded($window[0], $window[1]) => null,
                    // The first of a repeated day number in a long changeover month keeps its date.
                    in_array($header, array_map(fn (Carbon $d) => $d->toDateString(), self::repeated(...$window)), true) => "date.{$header}",
                    default => 'day.'.(int) $m[1],
                };
            } elseif (preg_match('/^(?:day\s*)?(\d{1,2})(?!\d)/i', $header, $m) && (int) $m[1] >= 1 && (int) $m[1] <= 31) {
                $field = 'day.'.(int) $m[1];
            }
            if ($field !== null && ! in_array($field, $mapping, true)) {
                $mapping[$header] = $field;
            }
        }

        return $mapping;
    }

    /**
     * @param  array<string, string|null>  $mapping  header => field
     * @param  array{month?: string|null, mode?: string|null, blank_days?: string|null}  $options
     */
    public function map(EmployeeImport $import, array $mapping, array $options = []): EmployeeImport
    {
        $this->assert($import, ['inspected', 'mapped', 'validated']);
        $options = array_merge($import->options ?? [], array_filter($options, fn ($v) => $v !== null && $v !== ''));
        [$from, $to] = self::period($options['month'] ?? null);
        if (($import->options['month'] ?? null) !== $options['month'] || ! isset($options['from'], $options['to'])) {
            // A different month (or an upload from before pay cycles) takes the month's dates as the cycle has them now.
            $options = ['from' => $from->toDateString(), 'to' => $to->toDateString()] + $options;
        }
        $fields = self::fields([Carbon::parse($options['from']), Carbon::parse($options['to'])]);
        $mapping = array_filter($mapping, fn ($f) => $f !== null && $f !== '');
        foreach ($mapping as $header => $field) {
            // A header that is a bare day number ("5") becomes an integer array key.
            if (! in_array((string) $header, $import->headers ?? [], true) || ! isset($fields[$field])) {
                throw new RuntimeException("Column [{$header}] or field [{$field}] is not valid.");
            }
        }
        if (count($mapping) !== count(array_unique($mapping))) {
            throw new RuntimeException('Each field can be mapped from one column only.');
        }
        if (! in_array('employee_code', $mapping, true)) {
            throw new RuntimeException('Required fields are not mapped: Employee code.');
        }
        if (collect($mapping)->filter(fn ($f) => str_starts_with($f, 'day.') || str_starts_with($f, 'date.'))->isEmpty()) {
            throw new RuntimeException('Map at least one day column.');
        }
        if (! isset(self::BLANK_DAYS[$options['blank_days'] ?? ''])) {
            throw new RuntimeException('Choose how blank days are read.');
        }
        $options['mode'] = (ImportMode::tryFrom((string) ($options['mode'] ?? '')) ?? ImportMode::of($import))->value;

        $import->update(['mapping' => $mapping, 'options' => $options, 'status' => 'mapped', 'valid_count' => 0, 'error_count' => 0, 'review_count' => 0, 'create_count' => 0, 'update_count' => 0, 'skip_count' => 0]);

        return $import->refresh();
    }

    /** Check every row and work out which days it marks; touches no attendance. */
    public function validate(EmployeeImport $import): EmployeeImport
    {
        $this->assert($import, ['mapped', 'validated']);
        [$start, $end] = $this->window($import);
        $employees = $this->employees();
        $counts = ['valid' => 0, 'error' => 0, 'create' => 0, 'update' => 0, 'skip' => 0];
        $seen = [];

        DB::transaction(function () use ($import, $start, $end, $employees, &$counts, &$seen) {
            foreach ($import->rows()->cursor() as $row) {
                [$employee, $days, $errors] = $this->resolve($row, $import, $employees, $start, $end);
                if ($employee !== null) {
                    if (isset($seen[$employee->id])) {
                        $errors[] = "Employee {$employee->employee_code} also appears in row {$seen[$employee->id]}.";
                    }
                    $seen[$employee->id] ??= $row->row_number;
                }
                $plan = $errors === [] ? $this->plan($employee, $days, $import, $start, $end) : null;
                $errors = [...$errors, ...($plan['errors'] ?? [])];
                $action = $errors !== [] ? 'error' : ($plan['replaced'] > 0 ? 'update' : ($plan['added'] > 0 ? 'create' : 'skip'));

                $row->update(['action' => $action, 'errors' => $errors ?: null, 'employee_id' => $employee?->id, 'status' => 'pending', 'result' => null,
                    'match' => $employee === null ? null : ['employee_id' => $employee->id, 'employee_code' => $employee->employee_code, 'name' => $employee->person?->full_name,
                        'matched_on' => ['employee code'], 'reason' => $errors === [] ? $plan['summary'] : null]]);
                $counts[$action === 'error' ? 'error' : 'valid']++;
                if ($action !== 'error') {
                    $counts[$action]++;
                }
            }
            $import->update(['status' => 'validated', 'valid_count' => $counts['valid'], 'error_count' => $counts['error'], 'review_count' => 0,
                'create_count' => $counts['create'], 'update_count' => $counts['update'], 'skip_count' => $counts['skip']]);
        });

        return $import->refresh();
    }

    public function approve(EmployeeImport $import, User $user, ?string $reason = null): EmployeeImport
    {
        $this->assert($import, ['validated']);
        if ($import->create_count + $import->update_count === 0) {
            throw new RuntimeException('Nothing to import: no row would mark a day.');
        }
        $import->withAuditReason($reason)->update(['status' => 'approved', 'approved_by' => $user->getKey(), 'approved_at' => now()]);

        return $import->refresh();
    }

    /** Store the approved rows' marks (planned again: facts may have moved) and recalculate the marked days. */
    public function run(EmployeeImport $import, User $user): EmployeeImport
    {
        $this->assert($import, ['approved']);
        $import->update(['status' => 'importing']);
        [$start, $end] = $this->window($import);
        $employees = $this->employees();
        $result = ['created' => 0, 'updated' => 0, 'skipped' => 0, 'failed' => 0];
        $label = 'Monthly attendance upload #'.$import->getKey().' for '.self::monthName($import->options['month'], $start, $end)." ({$import->original_name})";

        try {
            $operationId = $this->audit->operation('attendance', $label, function () use ($import, $user, $start, $end, $employees, &$result) {
                foreach ($import->rows()->whereIn('action', ['create', 'update', 'skip'])->cursor() as $row) {
                    if ($row->action === 'skip') {
                        $row->update(['status' => 'skipped', 'result' => 'Skipped: '.($row->match['reason'] ?? 'nothing to mark')]);
                        $result['skipped']++;

                        continue;
                    }
                    try {
                        [$employee, $days, $errors] = $this->resolve($row, $import, $employees, $start, $end);
                        $plan = $errors === [] ? $this->plan($employee, $days, $import, $start, $end) : ['errors' => $errors];
                        if ($plan['errors'] !== []) {
                            throw new RuntimeException(implode(' ', $plan['errors']));
                        }
                        if ($plan['write'] === []) {
                            $row->update(['status' => 'skipped', 'result' => "Skipped: {$plan['summary']}"]);
                            $result['skipped']++;

                            continue;
                        }
                        $this->write($employee, $plan['write'], $import, $user);
                        $row->update(['status' => 'imported', 'result' => ucfirst($plan['summary'])]);
                        $result[$plan['replaced'] > 0 ? 'updated' : 'created']++;
                    } catch (Throwable $e) {
                        if (! $e instanceof RuntimeException) {
                            report($e);
                        }
                        $row->update(['status' => 'failed', 'result' => Str::limit($e->getMessage(), 500)]);
                        $result['failed']++;
                    }
                }

                return ['succeeded' => $result['created'] + $result['updated'] + $result['skipped'], 'failed' => $result['failed']];
            }, reason: $label, entityType: AttendanceMark::class);
        } catch (Throwable $e) {
            $import->update(['status' => 'failed', 'error' => Str::limit($e->getMessage(), 500)]);

            throw $e;
        }

        $import->update(['status' => 'imported', 'imported_at' => now(), 'operation_id' => $operationId,
            'create_count' => $result['created'], 'update_count' => $result['updated'], 'skip_count' => $result['skipped'], 'failure_count' => $result['failed']]);

        return $import->refresh();
    }

    public function discard(EmployeeImport $import, ?string $reason = null): EmployeeImport
    {
        if (in_array($import->status, ['importing', 'imported'], true)) {
            throw new RuntimeException('A completed import cannot be discarded; it is part of the audit trail.');
        }
        $import->withAuditReason($reason)->update(['status' => 'discarded']);

        return $import;
    }

    // ---------------------------------------------------------------------------------------

    /**
     * The days the upload is for (windowOf), refusing an upload for a month that has not started.
     *
     * @return array{0: Carbon, 1: Carbon}
     */
    private function window(EmployeeImport $import): array
    {
        $period = self::period($import->options['month'] ?? null);

        return self::windowOf($import) ?? $period;
    }

    /** @return array<int, Carbon> day number => its date in the window (the later one where a long month repeats it) */
    private static function dayDates(Carbon $start, Carbon $end): array
    {
        $dates = [];
        for ($date = $start->copy(); $date->lte($end); $date->addDay()) {
            $dates[$date->day] = $date->copy();
        }

        return $dates;
    }

    /** @return list<Carbon> dates of a long changeover month whose day number comes round again later in it */
    private static function repeated(Carbon $start, Carbon $end): array
    {
        $later = self::dayDates($start, $end);
        $repeated = [];
        for ($date = $start->copy(); $date->lte($end); $date->addDay()) {
            if (! $later[$date->day]->isSameDay($date)) {
                $repeated[] = $date->copy();
            }
        }

        return $repeated;
    }

    /** How messages name a date: its day number, or the date itself for the first of a repeated day number. */
    private static function dayLabel(Carbon $date, array $repeated): string
    {
        return in_array($date->toDateString(), $repeated, true) ? $date->toDateString() : (string) $date->day;
    }

    /**
     * The employee a row names, its marks (date => status and halves) and what is wrong with it.
     *
     * @param  array<string, int>  $employees
     * @return array{0: ?Employee, 1: array<string, array{status: string, first_half: ?string, second_half: ?string}>, 2: list<string>}
     */
    private function resolve(EmployeeImportRow $row, EmployeeImport $import, array $employees, Carbon $start, Carbon $end): array
    {
        $dates = self::dayDates($start, $end);
        $repeated = array_map(fn (Carbon $d) => $d->toDateString(), self::repeated($start, $end));
        $r = $this->recordFor($row, $import->mapping ?? []);
        $errors = [];
        $employee = null;
        if (! isset($r['employee_code'])) {
            $errors[] = 'Employee code is required.';
        } else {
            $id = $employees[mb_strtolower($r['employee_code'])] ?? null;
            $employee = $id ? Employee::query()->withoutGlobalScope(AccessScope::class)->with('person')->find($id) : null;
            if ($employee === null) {
                $errors[] = "Employee code '{$r['employee_code']}' does not exist.";
            }
        }

        $days = [];
        $beyond = [];
        $refused = ['leave' => [], 'code' => [], 'half' => []];
        foreach ($r as $field => $value) {
            if (str_starts_with($field, 'day.')) {
                $day = (int) Str::after($field, 'day.');
                $date = $dates[$day] ?? null;
                $label = (string) $day;
            } elseif (str_starts_with($field, 'date.')) {
                $label = Str::after($field, 'date.');
                $date = in_array($label, $repeated, true) ? Carbon::parse($label)->startOfDay() : null;
            } else {
                continue;
            }
            [$mark, $problem] = self::cell($value);
            if ($date === null) {
                $beyond[] = $label;
            } elseif ($problem !== null) {
                $refused[$problem][] = "day {$label} ('{$value}')";
            } else {
                $days[$date->toDateString()] = $mark;
            }
        }
        ksort($days);
        if ($beyond !== []) {
            $errors[] = 'Day '.implode(', ', $beyond).' is not in '.self::monthName($import->options['month'], $start, $end).'; leave it blank.';
        }
        if ($refused['leave'] !== []) {
            $errors[] = 'Leave cannot be marked by upload: '.implode(', ', $refused['leave']).'. Employees apply for leave on the portal; approved leave fills the day. Leave the cell blank.';
        }
        if ($refused['code'] !== []) {
            $errors[] = 'Not a day code: '.implode(', ', $refused['code']).'. Use '.implode(', ', array_keys(self::CODES)).' for the whole day, or a code for each half such as P|A.';
        }
        if ($refused['half'] !== []) {
            $errors[] = 'HD is a whole day (a present and an absent half), not a half: '.implode(', ', $refused['half']).'. Give each half P, A, WO, H, WFH or OD, such as A|P.';
        }

        if ($employee !== null) {
            $outside = ['before' => [], 'after' => [], 'future' => []];
            foreach (array_keys($days) as $key) {
                $date = Carbon::parse($key);
                $day = self::dayLabel($date, $repeated);
                if ($employee->joining_date !== null && $date->lt($employee->joining_date)) {
                    $outside['before'][] = $day;
                } elseif ($employee->exit_date !== null && $date->gt($employee->exit_date)) {
                    $outside['after'][] = $day;
                } elseif ($date->gt(today())) {
                    $outside['future'][] = $day;
                }
            }
            $say = fn (array $list) => (count($list) === 1 ? 'Day ' : 'Days ').implode(', ', $list);
            if ($outside['before'] !== []) {
                $errors[] = $say($outside['before']).' '.(count($outside['before']) === 1 ? 'is' : 'are').' before the joining date ('.$employee->joining_date->toDateString().'); leave them blank.';
            }
            if ($outside['after'] !== []) {
                $errors[] = $say($outside['after']).' '.(count($outside['after']) === 1 ? 'is' : 'are').' after the exit date ('.$employee->exit_date->toDateString().'); leave them blank.';
            }
            if ($outside['future'] !== []) {
                $errors[] = $say($outside['future']).' '.(count($outside['future']) === 1 ? 'is' : 'are').' still to come; leave them blank.';
            }
        }

        return [$employee, $days, $errors];
    }

    /**
     * Which days of the employee's month the row marks under the upload's mode and blank-day choice, against what earlier
     * uploads marked, what payroll has locked and the employee's approved full-day leave (left to the leave).
     *
     * @param  array<string, array{status: string, first_half: ?string, second_half: ?string}>  $days  date => mark
     * @return array{write: array<string, array{status: string, first_half: ?string, second_half: ?string}>, added: int, replaced: int, errors: list<string>, summary: string}
     */
    private function plan(Employee $employee, array $days, EmployeeImport $import, Carbon $start, Carbon $end): array
    {
        $mode = ImportMode::of($import);
        $blankPresent = ($import->options['blank_days'] ?? 'calculate') === 'present';
        $from = $employee->joining_date !== null && $employee->joining_date->gt($start) ? $employee->joining_date->copy()->startOfDay() : $start->copy();
        $to = collect([$end, today(), $employee->exit_date?->copy()->startOfDay()])->filter()->min();

        $marked = AttendanceMark::query()->withoutGlobalScope(AccessScope::class)->where('employee_id', $employee->id)
            ->whereDate('date', '>=', $start->toDateString())->whereDate('date', '<=', $end->toDateString())->get()
            ->mapWithKeys(fn (AttendanceMark $m) => [$m->date->toDateString() => $m->label()])->all();
        $locked = AttendanceRecord::query()->withoutGlobalScope(AccessScope::class)->where('employee_id', $employee->id)->where('is_locked', true)
            ->whereDate('date', '>=', $start->toDateString())->whereDate('date', '<=', $end->toDateString())->get()
            ->mapWithKeys(fn (AttendanceRecord $r) => [$r->date->toDateString() => true])->all();
        $leave = $from->lte($to) ? $this->leave->approvedLeaveBetween($employee, $from, $to) : [];
        $repeated = array_map(fn (Carbon $d) => $d->toDateString(), self::repeated($start, $end));

        $write = [];
        $tally = ['added' => 0, 'replaced' => 0, 'same' => 0, 'kept' => 0, 'not_uploaded' => 0, 'leave' => 0];
        $lockedDays = [];
        for ($date = $from->copy(); $date->lte($to); $date->addDay()) {
            $key = $date->toDateString();
            $want = $days[$key] ?? null;
            if (isset($locked[$key])) {
                if ($want !== null) {
                    $lockedDays[] = self::dayLabel($date, $repeated);
                }

                continue;
            }
            if (isset($leave[$key]) && $leave[$key]->isFullDay()) {
                $tally['leave'] += $want !== null ? 1 : 0;

                continue;
            }
            $have = $marked[$key] ?? null;
            if ($want === null) {
                // A blank cell never replaces a day an earlier upload marked.
                if (! $blankPresent || $have !== null || ! $mode->adds()) {
                    continue;
                }
                $want = ['status' => 'working', 'first_half' => null, 'second_half' => null];
            }
            if ($have === null && $mode->adds()) {
                $write[$key] = $want;
                $tally['added']++;
            } elseif ($have === null) {
                $tally['not_uploaded']++;
            } elseif ($have === self::label($want)) {
                $tally['same']++;
            } elseif ($mode->updates()) {
                $write[$key] = $want;
                $tally['replaced']++;
            } else {
                $tally['kept']++;
            }
        }

        $errors = $lockedDays === [] ? [] : [(count($lockedDays) === 1 ? 'Day ' : 'Days ').implode(', ', $lockedDays).' '.(count($lockedDays) === 1 ? 'is' : 'are').' locked by a finalised payroll run and cannot change.'];
        $parts = array_filter([
            $tally['added'] + $tally['replaced'] > 0 ? 'marks '.($tally['added'] + $tally['replaced']).' day(s)'.($tally['replaced'] > 0 ? " ({$tally['replaced']} replaced)" : '') : null,
            $tally['same'] > 0 ? "{$tally['same']} day(s) already marked the same" : null,
            $tally['kept'] > 0 ? "{$tally['kept']} day(s) uploaded before are kept (".self::MODES['new'][0].')' : null,
            $tally['not_uploaded'] > 0 ? "{$tally['not_uploaded']} day(s) not uploaded before are skipped (".self::MODES['update'][0].')' : null,
            $tally['leave'] > 0 ? "{$tally['leave']} day(s) of approved leave stay as leave" : null,
        ]);

        return ['write' => $write, 'added' => $tally['added'], 'replaced' => $tally['replaced'], 'errors' => $errors, 'summary' => $parts === [] ? 'no day to mark' : implode('; ', $parts)];
    }

    /** @param  array<string, array{status: string, first_half: ?string, second_half: ?string}>  $write  date => mark */
    private function write(Employee $employee, array $write, EmployeeImport $import, User $user): void
    {
        $tenantId = app(TenantContext::class)->id();
        $dateFormat = (new AttendanceMark)->getDateFormat();
        $rows = collect($write)->map(fn (array $mark, string $date) => ['tenant_id' => $tenantId, 'employee_id' => $employee->id, 'date' => Carbon::parse($date)->format($dateFormat),
            ...$mark, 'source' => 'upload', 'employee_import_id' => $import->getKey(), 'marked_by' => $user->getKey()])->values()->all();

        DB::transaction(fn () => AttendanceMark::query()->upsert($rows, ['tenant_id', 'employee_id', 'date'], ['status', 'first_half', 'second_half', 'source', 'employee_import_id', 'marked_by']));
        ProcessAttendanceRange::dispatch($employee->id, (string) array_key_first($write), (string) array_key_last($write));
    }

    /**
     * The mark a cell asks for, or why it cannot be read: leave (a leave code), code (not a code, or a split cell
     * without two halves) or half (HD given as a half).
     *
     * @return array{0: ?array{status: string, first_half: ?string, second_half: ?string}, 1: 'leave'|'code'|'half'|null}
     */
    private static function cell(string $value): array
    {
        $parts = array_map('trim', explode('|', $value));
        if (count($parts) > 2) {
            return [null, 'code'];
        }
        $statuses = [];
        foreach ($parts as $part) {
            if (self::isLeave($part)) {
                return [null, 'leave'];
            }
            $status = self::status($part);
            if ($status === null) {
                return [null, 'code'];
            }
            if ($status === 'half_day' && count($parts) === 2) {
                return [null, 'half'];
            }
            $statuses[] = $status;
        }

        return count($statuses) === 1
            ? [['status' => $statuses[0], 'first_half' => null, 'second_half' => null], null]
            : [['status' => AttendanceMark::combine(...$statuses), 'first_half' => $statuses[0], 'second_half' => $statuses[1]], null];
    }

    /** A code's mark status: a code (any case), AB, or its meaning's first words ("present", "half day"); null when neither. */
    private static function status(string $value): ?string
    {
        $key = mb_strtoupper(trim($value));
        if (isset(self::CODES[$key])) {
            return self::CODES[$key][0];
        }
        $words = ['AB' => 'A', 'PRESENT' => 'P', 'ABSENT' => 'A', 'HALF DAY' => 'HD', 'WEEKLY OFF' => 'WO', 'HOLIDAY' => 'H', 'WORK FROM HOME' => 'WFH', 'ON DUTY' => 'OD'];

        return isset($words[$key]) ? self::CODES[$words[$key]][0] : null;
    }

    /** A planned mark as AttendanceMark::label() writes a stored one, so the two compare. */
    private static function label(array $mark): string
    {
        return $mark['first_half'] !== null ? "{$mark['first_half']}|{$mark['second_half']}" : $mark['status'];
    }

    /** Whether a cell names leave, which only a leave request on the portal can record. */
    private static function isLeave(string $value): bool
    {
        $key = mb_strtoupper(trim($value));

        return in_array($key, self::LEAVE_CODES, true) || preg_match('/\bLEAVE\b/', $key) === 1;
    }

    /** @return list<string> Employee code, Employee name and one "5 Thu" header per day of the month (the date for the first of a repeated day number) */
    private function headers(Carbon $start, Carbon $end): array
    {
        $repeated = array_map(fn (Carbon $d) => $d->toDateString(), self::repeated($start, $end));
        $days = [];
        for ($date = $start->copy(); $date->lte($end); $date->addDay()) {
            $days[] = in_array($date->toDateString(), $repeated, true) ? $date->toDateString() : $date->day.' '.$date->format('D');
        }

        return ['Employee code', 'Employee name', ...$days];
    }

    /** An example sheet for the format guide: two people, weekdays present, weekends off, an absence, a day working from home and half days. */
    private function sample(Carbon $start, Carbon $end): string
    {
        $people = Employee::query()->with('person')->orderBy('id')->limit(2)->get()->map(fn (Employee $e) => [$e->employee_code, $e->person?->display_name])->all()
            ?: [['EMP0001', 'Asha Rao'], ['EMP0002', 'Vikram Sen']];

        $csv = Writer::fromString();
        $csv->insertOne($this->headers($start, $end));
        foreach ($people as $i => [$code, $name]) {
            $cells = [];
            for ($date = $start->copy(); $date->lte($end); $date->addDay()) {
                $cells[] = $date->isWeekend() ? 'WO' : match ($date->day + $i) {
                    3 => 'A', 10 => 'WFH', 16 => 'P|A', 23 => 'HD', default => 'P'
                };
            }
            $csv->insertOne([$code, $name, ...$cells]);
        }

        return $csv->toString();
    }

    /** @return array<string, string> field => non-empty value */
    private function recordFor(EmployeeImportRow $row, array $mapping): array
    {
        $r = [];
        foreach ($mapping as $header => $field) {
            $v = $row->data[$header] ?? null;
            $v = is_string($v) ? trim($v) : $v;
            if ($v !== null && $v !== '') {
                $r[$field] = (string) $v;
            }
        }

        return $r;
    }

    /** @return array<string, int> lower-cased employee code => id, within the uploader's organisation scope */
    private function employees(): array
    {
        return Employee::query()->pluck('id', 'employee_code')->mapWithKeys(fn ($id, $code) => [mb_strtolower((string) $code) => (int) $id])->all();
    }

    private function assert(EmployeeImport $import, array $allowed): void
    {
        if ($import->type !== self::TYPE) {
            throw new RuntimeException('This import is not a monthly attendance upload.');
        }
        if (! in_array($import->status, $allowed, true)) {
            throw new RuntimeException("This import is {$import->status}; expected ".implode(' or ', $allowed).'.');
        }
    }
}
