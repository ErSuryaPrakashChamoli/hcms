<?php

namespace App\Filament\Support;

use App\Domain\Employment\Models\Employee;
use App\Domain\Leave\Models\LeaveType;
use App\Domain\Leave\Services\LeaveBalances;
use App\Domain\Leave\Services\LeaveDayCounter;
use App\Domain\Leave\Services\LeaveYear;
use App\Domain\Organisation\Models\BusinessUnit;
use App\Domain\Organisation\Models\Company;
use App\Domain\Organisation\Models\CostCentre;
use App\Domain\Organisation\Models\Department;
use App\Domain\Organisation\Models\Designation;
use App\Domain\Organisation\Models\Division;
use App\Domain\Organisation\Models\EmployeeCategory;
use App\Domain\Organisation\Models\EmploymentType;
use App\Domain\Organisation\Models\Grade;
use App\Domain\Organisation\Models\Level;
use App\Domain\Organisation\Models\Location;
use App\Domain\Organisation\Models\Team;
use App\Domain\Organisation\Models\WorkMode;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\View;
use Illuminate\Support\Carbon;

/**
 * UX: the Before → After preview inside existing change forms (§21). It reads the employee's current
 * values and the values typed into the form, and shows only what would change. It is display only: the
 * form still submits to the same domain action, which validates and records the change.
 */
final class BeforeAfterPreview
{
    /** Position fields shown in the preview (form field → [label, model]). */
    public const POSITION = [
        'company_id' => ['Company', Company::class], 'location_id' => ['Location', Location::class], 'business_unit_id' => ['Business unit', BusinessUnit::class],
        'division_id' => ['Division', Division::class], 'department_id' => ['Department', Department::class], 'team_id' => ['Team', Team::class],
        'designation_id' => ['Designation', Designation::class], 'level_id' => ['Level', Level::class], 'grade_id' => ['Grade', Grade::class],
        'employment_type_id' => ['Employment type', EmploymentType::class], 'employee_category_id' => ['Employee category', EmployeeCategory::class],
        'work_mode_id' => ['Work mode', WorkMode::class], 'cost_centre_id' => ['Cost centre', CostCentre::class],
    ];

    public static function position(): View
    {
        return View::make('filament.forms.before-after')->columnSpanFull()
            ->viewData(fn (Get $get, ?Employee $record) => ['changes' => self::positionChanges($record, $get), 'note' => self::effective($get('effective_from')),
                'empty' => 'Choose what changes. Anything you leave empty carries forward from today’s position.']);
    }

    public static function manager(): View
    {
        return View::make('filament.forms.before-after')->columnSpanFull()
            ->viewData(function (Get $get, ?Employee $record) {
                $current = $record?->currentManager?->manager?->person?->display_name;
                $new = $get('manager_id') ? Employee::query()->with('person')->find($get('manager_id'))?->person?->display_name : null;

                return ['changes' => $new && $new !== $current ? [['label' => 'Reports to', 'before' => $current ?? 'No manager', 'after' => $new]] : [],
                    'note' => self::effective($get('effective_from')), 'empty' => 'Choose the new manager to see the change.'];
            });
    }

    /**
     * Leave request preview: working days in the span (the same LeaveDayCounter the request uses) and the
     * balance before → after. Display only; the Leaves service validates everything again on submit.
     */
    public static function leave(callable $employee): View
    {
        return View::make('filament.forms.before-after')->columnSpanFull()
            ->viewData(function (Get $get) use ($employee) {
                $empty = ['changes' => [], 'note' => null, 'empty' => 'Pick a leave type and dates to see the days and your balance after this request.'];
                $type = $get('leave_type_id') ? LeaveType::query()->find($get('leave_type_id')) : null;
                if ($type === null || blank($get('from_date')) || blank($get('to_date'))) {
                    return $empty;
                }
                try {
                    return self::leaveChanges($employee(), $type, (string) $get('from_date'), (string) $get('to_date'), $get('from_session') ?? 'full', $get('to_session') ?? 'full') + ['empty' => ''];
                } catch (\Throwable) {
                    return $empty;
                }
            });
    }

    /**
     * The leave preview itself: working days (the same LeaveDayCounter the request uses) and the balance
     * before → after. Display only.
     *
     * @return array{changes: list<array{label: string, before: ?string, after: string}>, note: ?string}
     */
    public static function leaveChanges(Employee $employee, LeaveType $type, string $fromDate, string $toDate, string $fromSession = 'full', string $toSession = 'full'): array
    {
        $from = Carbon::parse($fromDate)->startOfDay();
        $to = Carbon::parse($toDate)->startOfDay();
        if ($to->lt($from)) {
            return ['changes' => [], 'note' => 'The end date is before the start date.'];
        }
        $counter = app(LeaveDayCounter::class);
        $days = $counter->total($counter->dates($employee, $from, $to, $fromSession, $from->equalTo($to) ? $fromSession : $toSession));
        $fmt = fn (float $v) => rtrim(rtrim(number_format($v, 1), '0'), '.');
        $changes = [['label' => 'Working days', 'before' => null, 'after' => $fmt($days)]];
        $after = null;
        if ($type->category !== 'unpaid') {
            $available = app(LeaveBalances::class)->balance($employee, $type, app(LeaveYear::class)->periodFor($from))->available();
            $after = $available - $days;
            $changes[] = ['label' => $type->name.' available', 'before' => $fmt($available), 'after' => $fmt($after)];
        }

        return ['changes' => $changes, 'note' => $after !== null && $after < 0 ? 'This is more than your balance; the request may be refused or need an approval.' : 'Weekends and holidays follow your leave policy.'];
    }

    /** @return list<array{label: string, before: ?string, after: string}> */
    private static function positionChanges(?Employee $record, Get $get): array
    {
        $current = $record?->currentPosition;
        $changes = [];
        foreach (self::POSITION as $field => [$label, $model]) {
            $new = $get($field);
            if (blank($new) || (int) $new === (int) ($current?->{$field} ?? 0)) {
                continue;
            }
            $changes[] = [
                'label' => $label,
                'before' => $current?->{$field} ? ($model::query()->whereKey($current->{$field})->value('name') ?? '—') : 'Not set',
                'after' => $model::query()->whereKey($new)->value('name') ?? '—',
            ];
        }
        if ($get('vacate_position')) {
            $changes[] = ['label' => 'Position seat', 'before' => 'Occupied', 'after' => 'Vacated'];
        }

        return $changes;
    }

    private static function effective(mixed $date): ?string
    {
        if (blank($date)) {
            return null;
        }
        $d = Carbon::parse($date);

        return 'Takes effect '.$d->format('D, d M Y').($d->isFuture() ? ' (future-dated: today’s record stays as it is until then)' : '').'.';
    }
}
