<?php

namespace App\Domain\Attendance\Imports;

use App\Domain\Attendance\Models\HolidayCalendar;
use App\Domain\Employment\Imports\MasterImports;
use App\Domain\Organisation\Imports\Concerns\ReadsMasterRows;
use App\Domain\Organisation\Imports\Concerns\RequiresUpdatePermission;
use App\Domain\Organisation\Imports\Concerns\SetsApplicability;
use App\Support\Imports\ReferenceGuide;
use Illuminate\Database\Eloquent\Model;

/**
 * Master uploads (Time office → Holiday calendars): holiday calendars by name (names first: the calendar code may be left
 * blank and is then made from the name) or by code, with the year they are for and where
 * they apply (SetsApplicability: every company, some companies, future companies), as Attendance → Holiday calendars.
 * The holidays themselves come from the Holidays upload (HolidayImports). Who gets a calendar stays on its Rules tab:
 * a rule is a condition over the employee's position, which a sheet cannot express safely, so a new calendar applies
 * to nobody until a rule is added there (the format guide and the run's result say so).
 */
final class HolidayCalendarImports extends MasterImports
{
    use ReadsMasterRows, RequiresUpdatePermission, SetsApplicability;

    public const TYPE = 'holiday_calendars';

    public static function fields(): array
    {
        return ['code' => ['Calendar code', false], 'name' => ['Calendar name', true], 'year' => ['Year', false], 'status' => ['Status', false]]
            + self::applicabilityFields();
    }

    public function label(): string
    {
        return 'Holiday calendars upload';
    }

    protected function module(): string
    {
        return 'attendance';
    }

    protected function model(): string
    {
        return HolidayCalendar::class;
    }

    protected function updatePermission(): string
    {
        return 'attendance.manage';
    }

    protected function aliases(): array
    {
        return ['holiday_calendar_code' => 'code', 'calendar_code' => 'code', 'calendar' => 'name', 'calendar_name' => 'name', 'holiday_calendar' => 'name', 'for_year' => 'year'];
    }

    protected function attributes(array $r, array &$context, ?Model $existing, array &$errors): array
    {
        $year = $this->number($r['year'] ?? null, 'Year', $errors, 2000, 2100, true);

        return array_filter([
            'name' => $this->text($r['name'] ?? null, 255, 'Calendar name', $errors),
            'year' => $year === null ? null : (int) $year,
            'status' => $this->status($r['status'] ?? null, $errors),
        ], fn ($v) => $v !== null);
    }

    public function format(): array
    {
        return [
            'columns' => [
                ['Calendar code', 'No', ReferenceGuide::ownCode('holiday calendar'), ''],
                ['Calendar name', 'Yes', 'The calendar name.', 'Maharashtra holidays 2027'],
                ['Year', 'No', 'The year the calendar is for (2000–2100). Blank: any year. Holidays uploaded to it must fall in that year.', '2027'],
                ['Status', 'No', 'active or inactive. Blank for a new one is active.', 'active'],
                ...self::applicabilityColumns(),
            ],
            'notes' => [
                str_replace('The Code column', 'The Calendar code column', ReferenceGuide::OWN_CODE_NOTE),
                'A row whose values are already on file is skipped.',
                'The holidays of each calendar come from the Holidays upload (or the Holidays worksheet of the Time office workbook).',
                'Who gets a calendar is set on the calendar\'s Rules tab (Attendance → Holiday calendars), by company, location, department or any other position field. A new calendar applies to nobody until a rule is added there.',
                'Applies to limits which companies may use the calendar at all; a rule never reaches people of other companies.',
            ],
        ];
    }

    protected function examples(): array
    {
        return [
            ['', 'Maharashtra holidays 2027', '2027', 'active', '', ''],
            ['', 'Karnataka holidays 2027', '2027', 'active', '', ''],
        ];
    }
}
