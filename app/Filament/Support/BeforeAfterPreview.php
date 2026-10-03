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
                    $emp = $employee();
                    $from = Carbon::parse($get('from_date'))->startOfDay();
                    $to = Carbon::parse($get('to_date'))->startOfDay();
                    if ($to->lt($from)) {
                        return ['changes' => [], 'note' => null, 'empty' => 'The end date is before the start date.'];
                    }
                    $counter = app(LeaveDayCounter::class);
                    $days = $counter->total($counter->dates($emp, $from, $to, $get('from_session') ?? 'full', $from->equalTo($to) ? ($get('from_session') ?? 'full') : ($get('to_session') ?? 'full')));
                    $changes = [['label' => 'Working days', 'before' => null, 'after' => rtrim(rtrim(number_format($days, 1), '0'), '.')]];
                    if ($type->category !== 'unpaid') {
                        $available = app(LeaveBalances::class)->balance($emp, $type, app(LeaveYear::class)->periodFor($from))->available();
                        $after = $available - $days;
                        $changes[] = ['label' => $type->name.' available', 'before' => rtrim(rtrim(number_format($available, 1), '0'), '.'), 'after' => rtrim(rtrim(number_format($after, 1), '0'), '.')];
                    }

                    return ['changes' => $changes, 'note' => $type->category !== 'unpaid' && isset($after) && $after < 0 ? 'This is more than your balance; the request may be refused or need an approval.' : 'Weekends and holidays follow your leave policy.', 'empty' => ''];
                } catch (\Throwable) {
                    return $empty;
                }
            });
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
