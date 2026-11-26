<?php

namespace App\Domain\Attendance\Imports;

use App\Domain\Attendance\Models\Holiday;
use App\Domain\Attendance\Models\HolidayCalendar;
use App\Domain\Configuration\Applicability\EntityApplicability;
use App\Domain\Employment\Imports\EmployeeImport;
use App\Domain\Employment\Imports\EmployeeImportRow;
use App\Domain\Employment\Imports\RowImports;
use App\Domain\Employment\Imports\Workbooks\ImportReferences;
use App\Domain\Identity\Models\User;
use App\Domain\Organisation\Resolution\ResolutionStatus;
use App\Support\Imports\ReferenceGuide;
use RuntimeException;

/**
 * Master uploads (Time office → Holidays): the holidays of each holiday calendar, as the calendar's Holidays tab
 * (Attendance → Holiday calendars). A row is one holiday, matched on its calendar and date: a new date adds it, a date
 * on file updates its name, type or half day, a row already on file is skipped. Holidays are never removed by an
 * upload (the calendar's screen removes them). The calendar is named by its name, its code or "Name (CODE)",
 * and may be one the Holiday calendars worksheet of the same workbook adds (the row then waits for it). A calendar
 * that also applies to companies outside the uploader's access is refused, as its edit screen refuses it.
 */
final class HolidayImports extends RowImports
{
    public const TYPE = 'holidays';

    public const PERMISSION = 'attendance.manage';

    public static function fields(): array
    {
        return ['calendar_code' => ['Holiday calendar', true], 'date' => ['Date', true], 'name' => ['Holiday name', true], 'type' => ['Type', false], 'half_day' => ['Half day', false]];
    }

    public function label(): string
    {
        return 'Holidays upload';
    }

    protected function module(): string
    {
        return 'attendance';
    }

    protected function entityType(): string
    {
        return Holiday::class;
    }

    protected function aliases(): array
    {
        return ['calendar' => 'calendar_code', 'holiday_calendar' => 'calendar_code', 'holiday_calendar_code' => 'calendar_code', 'calendar_name' => 'calendar_code',
            'holiday_date' => 'date', 'on' => 'date', 'holiday' => 'name', 'holiday_name' => 'name', 'occasion' => 'name', 'holiday_type' => 'type',
            'is_half_day' => 'half_day', 'half' => 'half_day'];
    }

    protected function references(): array
    {
        return ['calendar_code' => 'holiday_calendar'];
    }

    protected function prepare(EmployeeImport $import): array
    {
        return ['calendars' => HolidayCalendar::query()->pluck('id', 'code')->mapWithKeys(fn ($id, $c) => [mb_strtolower((string) $c) => (int) $id])->all(), 'seen' => [], 'editable' => []];
    }

    protected function check(array $r, array &$context, EmployeeImportRow $row, EmployeeImport $import): array
    {
        $errors = [];
        $calendar = $this->calendar($r, $context, $errors);
        $date = $this->date($r['date'] ?? null);
        if ($date === null) {
            $errors[] = 'Date must be a date (YYYY-MM-DD, DD-MM-YYYY or DD/MM/YYYY).';
        }
        $name = trim((string) ($r['name'] ?? ''));
        if (mb_strlen($name) > 255) {
            $errors[] = 'Holiday name may be at most 255 characters.';
        }
        $type = $this->type($r['type'] ?? null, $errors);
        $half = $this->yesNo($r['half_day'] ?? null, $errors);
        if ($errors !== [] || $calendar === null || $date === null) {
            return ['action' => 'error', 'errors' => $errors ?: ['The row could not be read.']];
        }
        if ($calendar->year !== null && (int) substr($date, 0, 4) !== (int) $calendar->year) {
            return ['action' => 'error', 'errors' => ["Calendar {$calendar->name} is for {$calendar->year}; {$date} is outside it."]];
        }
        if (! $this->mayEdit($calendar, $context, $import)) {
            return ['action' => 'error', 'errors' => ["Calendar {$calendar->name} is also used by companies outside your access, so you may not change its holidays here."]];
        }
        $key = $calendar->id.'|'.$date;
        if (isset($context['seen'][$key])) {
            return ['action' => 'error', 'errors' => ["Row {$context['seen'][$key]} already gives a holiday of {$calendar->name} on {$date}."]];
        }
        $context['seen'][$key] = $row->row_number;

        $current = Holiday::query()->where('holiday_calendar_id', $calendar->id)->whereDate('date', $date)->first();
        if ($current === null) {
            return ['action' => 'create'];
        }
        $changes = array_keys(array_filter([
            'name' => $name !== $current->name,
            'type' => $type !== null && $type !== $current->type,
            'half day' => $half !== null && $half !== (bool) $current->is_half_day,
        ]));

        return $changes === []
            ? ['action' => 'skip', 'match' => ['reason' => "{$calendar->name} already has {$current->name} on {$date}."]]
            : ['action' => 'update', 'match' => ['changes' => $changes, 'name' => $current->name]];
    }

    protected function apply(array $r, EmployeeImportRow $row, EmployeeImport $import, User $user): string
    {
        $context = ['calendars' => [], 'editable' => []];
        $errors = [];
        $calendar = $this->calendar($r, $context, $errors);
        $date = $this->date($r['date'] ?? null);
        $type = $this->type($r['type'] ?? null, $errors);
        $half = $this->yesNo($r['half_day'] ?? null, $errors);
        if ($errors !== [] || $calendar === null || $date === null) {
            throw new RuntimeException(implode(' ', $errors) ?: 'The row could not be read.');
        }
        $reason = $this->label()." #{$import->getKey()} ({$import->original_name})";
        $values = array_filter(['name' => trim((string) $r['name']), 'type' => $type, 'is_half_day' => $half], fn ($v) => $v !== null);
        $holiday = Holiday::query()->where('holiday_calendar_id', $calendar->id)->whereDate('date', $date)->first();
        if ($holiday === null) {
            // The row's values first: + keeps the left-hand keys, so the defaults only fill what the row left blank.
            $holiday = new Holiday(['holiday_calendar_id' => $calendar->id, 'date' => $date] + $values + ['type' => 'public', 'is_half_day' => false]);
            $holiday->withAuditReason($reason)->save();
            $done = "Added {$holiday->name} on {$date} to {$calendar->name}";
        } else {
            $holiday->withAuditReason($reason)->update($values);
            $done = "Updated {$holiday->name} on {$date} in {$calendar->name}";
        }
        $ruled = $calendar->rules()->where('status', 'active')->exists();

        return $ruled ? $done : $done.". {$calendar->name} has no rule yet, so it applies to nobody until one is added on its Rules tab (Attendance → Holiday calendars)";
    }

    public function format(): array
    {
        return [
            'columns' => [
                ['Holiday calendar', 'Yes', ReferenceGuide::name('holiday calendar', 'Attendance → Holiday calendars', 'It may be one the Holiday calendars worksheet of the same workbook adds.'), 'Maharashtra holidays 2027'],
                ['Date', 'Yes', 'The day: YYYY-MM-DD, DD-MM-YYYY or DD/MM/YYYY. Within the calendar\'s year when it has one.', '2027-01-26'],
                ['Holiday name', 'Yes', 'What the day is.', 'Republic Day'],
                ['Type', 'No', 'One of: '.implode(', ', array_keys($this->types())).' (or the label). Blank: public for a new holiday, unchanged otherwise.', 'public'],
                ['Half day', 'No', 'yes or no. Blank: no for a new holiday, unchanged otherwise.', 'no'],
            ],
            'codes' => $this->types(),
            'codes_title' => 'Holiday types',
            'notes' => [
                'One row per holiday, matched on its calendar and date: a new date adds the holiday, a date on file updates it, a row already on file is skipped.',
                'An upload never removes a holiday: remove it on the calendar\'s Holidays tab.',
                'Optional holidays on a calendar whose optional holidays need choosing count only for employees whose restricted-holiday request is approved.',
                'Who gets a calendar is set on its Rules tab. A calendar without a rule applies to nobody; the result says so.',
                ReferenceGuide::NOTE,
            ],
        ];
    }

    protected function examples(): array
    {
        $calendar = HolidayCalendar::query()->orderBy('id')->first();
        // Names first: the calendar as people know it (its code works too).
        $name = $calendar?->name ?? 'Maharashtra holidays 2027';
        $year = $calendar?->year ?? (int) now()->addYear()->format('Y');

        return [
            [$name, "{$year}-01-26", 'Republic Day', 'public', 'no'],
            [$name, "{$year}-08-15", 'Independence Day', 'public', 'no'],
        ];
    }

    // ---------------------------------------------------------------------------------------

    /**
     * The calendar a row names: by code (ReferenceCells has already turned a name into its code), else by exact name or
     * "Name (CODE)" through the name resolver (as the uploader).
     */
    private function calendar(array $r, array &$context, array &$errors): ?HolidayCalendar
    {
        $value = trim((string) ($r['calendar_code'] ?? ''));
        if ($value === '') {
            $errors[] = 'Holiday calendar is required.';

            return null;
        }
        $id = $context['calendars'][mb_strtolower($value)] ?? HolidayCalendar::query()->whereRaw('lower(code) = ?', [mb_strtolower($value)])->value('id');
        if ($id === null) {
            $resolution = app(ImportReferences::class)->resolve(HolidayCalendar::class, $value, null, null, null, 'Holiday calendar');
            if ($resolution === null || ! $resolution->ok() || (int) $resolution->id <= 0) {
                $errors[] = $resolution !== null && $resolution->status !== ResolutionStatus::Unmatched && $resolution->error() !== null
                    ? $resolution->error() : "Holiday calendar '{$value}' does not exist.";

                return null;
            }
            $id = (int) $resolution->id;
        }

        return HolidayCalendar::query()->find($id);
    }

    /** Whether the uploader may change this calendar: not when it also reaches companies outside their access. */
    private function mayEdit(HolidayCalendar $calendar, array &$context, EmployeeImport $import): bool
    {
        if (! isset($context['editable'][$calendar->id])) {
            $uploader = $context['uploader'] ??= User::query()->find($import->uploaded_by);
            $context['editable'][$calendar->id] = $uploader === null || app(EntityApplicability::class)->editableBy($calendar, $uploader);
        }

        return $context['editable'][$calendar->id];
    }

    /** @return array<string, string> */
    private function types(): array
    {
        return (array) config('peopleos.attendance.holiday_types', []);
    }

    /** @param  list<string>  $errors */
    private function type(?string $value, array &$errors): ?string
    {
        $value = trim((string) $value);
        if ($value === '') {
            return null;
        }
        foreach ($this->types() as $code => $label) {
            if (mb_strtolower($value) === mb_strtolower((string) $code) || mb_strtolower($value) === mb_strtolower((string) $label)) {
                return (string) $code;
            }
        }
        $errors[] = 'Type must be one of: '.implode(', ', array_keys($this->types())).'.';

        return null;
    }

    /** @param  list<string>  $errors */
    private function yesNo(?string $value, array &$errors): ?bool
    {
        $v = mb_strtolower(trim((string) $value));
        if ($v === '') {
            return null;
        }
        if (in_array($v, ['yes', 'y', '1', 'true'], true)) {
            return true;
        }
        if (in_array($v, ['no', 'n', '0', 'false'], true)) {
            return false;
        }
        $errors[] = 'Half day must be yes or no.';

        return null;
    }
}
