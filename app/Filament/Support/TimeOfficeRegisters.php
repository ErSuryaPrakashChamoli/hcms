<?php

namespace App\Filament\Support;

use App\Domain\Attendance\Imports\AttendanceSheetImports;
use App\Domain\Attendance\Imports\DayOverrideImports;
use App\Domain\Attendance\Imports\GeoLocationImports;
use App\Domain\Attendance\Imports\OvertimeImports;
use App\Domain\Attendance\Imports\ShiftRosterImports;
use App\Domain\Employment\Imports\RowImportTypes;
use App\Domain\Leave\Imports\LeaveBalanceImports;
use App\Domain\Leave\Imports\LeaveHistoryImports;
use App\Filament\Resources\AttendanceSheetImports\AttendanceSheetImportResource;
use App\Filament\Resources\PunchImports\PunchImportResource;
use App\Filament\Resources\TimeOfficeImports\TimeOfficeImportResource;
use Closure;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Filters\BaseFilter;
use Illuminate\Support\Carbon;
use Throwable;

/**
 * HROne parity TX-1 (matrix 24 §24.1): the register depth shared by the time-office and leave registers, on top of
 * WO-1 / WO-2 (HasRegisterTools, EmployeeFilters):
 *
 * - filters(): pasted codes (and codes to leave out), the organisation group, employee status / type / joining, the
 *   pending-resignation toggle and the register's own date ranges (request, decision, effective date …) — shown only to
 *   people holding the register's HR view permission (attendance.view / leave.view), so an employee's own list of
 *   requests stays as it was.
 * - reportFilters(): the register's filters as report conditions for its library templates (status and type labels as
 *   the dataset shows them, date ranges on the dataset's date fields).
 * - uploads(): the "Upload" header links to the upload screens of the register's subject (HROne's "Imports ▾"), each
 *   shown only to people allowed that upload.
 */
final class TimeOfficeRegisters
{
    /** Upload key => [label, upload screen class or row-import type]. */
    private const UPLOADS = [
        'shift_roster' => ['Shift roster upload', ShiftRosterImports::TYPE],
        'day_overrides' => ['Day override upload', DayOverrideImports::TYPE],
        'overtime' => ['Overtime upload', OvertimeImports::TYPE],
        'geo_locations' => ['Geo location upload', GeoLocationImports::TYPE],
        'leave_balances' => ['Leave balance upload', LeaveBalanceImports::TYPE],
        'leave_history' => ['Leave history upload', LeaveHistoryImports::TYPE],
        'attendance_sheet' => ['Monthly attendance upload', AttendanceSheetImportResource::class],
        'punches' => ['Punch upload', PunchImportResource::class],
    ];

    /** Whether the signed-in person holds the register's HR view permission. */
    public static function hrSide(string $permission): bool
    {
        return auth()->user()?->hasPermission($permission) ?? false;
    }

    /**
     * @param  array<string, array{0: string, 1: string}>  $dates  filter name => [label, date column of the register]
     * @return list<BaseFilter>
     */
    public static function filters(string $permission, array $dates = [], string $relation = 'employee'): array
    {
        $filters = [
            EmployeeFilters::codes($relation),
            EmployeeFilters::organisation($relation),
            EmployeeFilters::workforce($relation),
            ...array_map(fn (string $name) => EmployeeFilters::dateRange($name, $dates[$name][0], $dates[$name][1]), array_keys($dates)),
            EmployeeFilters::excludePendingResignation($relation),
        ];

        return array_map(fn (BaseFilter $filter) => $filter->visible(fn () => self::hrSide($permission)), $filters);
    }

    /**
     * The register's filter state as report conditions: the shared WO-1 mapping (organisation, codes, employee status,
     * joining) plus the register's own filters. Fields a dataset lacks are dropped by ReportLibrary::create.
     *
     * @param  array<string, mixed>  $state  the register's table filter state
     * @param  array<string, array{0: string, 1: (Closure(string): string)|null}>  $selects  filter name => [dataset field, value labeller]
     * @param  array<string, string>  $dates  date-range filter name => dataset date field
     * @return list<array{field: string, operator: string, value: string|null}>
     */
    public static function reportFilters(array $state, array $selects = [], array $dates = []): array
    {
        $out = RegisterReport::filtersFrom(array_diff_key($state, $selects));
        foreach ($selects as $name => [$field, $labeller]) {
            $filter = (array) ($state[$name] ?? []);
            $values = (array) ($filter['values'] ?? (isset($filter['value']) && filled($filter['value']) ? [$filter['value']] : []));
            $values = array_values(array_filter(array_map(fn ($v) => is_scalar($v) ? trim((string) ($labeller !== null ? $labeller((string) $v) : $v)) : '', $values), fn ($v) => $v !== '' && ! str_contains($v, ',')));
            if ($values !== []) {
                $out[] = ['field' => $field, 'operator' => 'in', 'value' => implode(',', $values)];
            }
        }
        foreach ($dates as $name => $field) {
            foreach (['from' => 'gte', 'until' => 'lte'] as $bound => $operator) {
                $value = $state[$name][$bound] ?? null;
                if (filled($value)) {
                    try {
                        $out[] = ['field' => $field, 'operator' => $operator, 'value' => Carbon::parse($value)->toDateString()];
                    } catch (Throwable) {
                        // An unreadable date is left out of the report rather than guessed.
                    }
                }
            }
        }

        return $out;
    }

    /** @param  list<string>  $keys  keys of UPLOADS */
    public static function uploads(array $keys): ActionGroup
    {
        $actions = [];
        foreach ($keys as $key) {
            [$label, $target] = self::UPLOADS[$key];
            $actions[] = Action::make('registerUpload_'.$key)
                ->label($label)
                ->icon(Heroicon::OutlinedArrowUpTray)
                ->visible(fn () => self::uploadAllowed($target))
                ->url(fn () => self::uploadUrl($target));
        }

        return ActionGroup::make($actions)->label('Upload')->icon(Heroicon::OutlinedArrowUpTray)->button()->color('gray');
    }

    private static function uploadAllowed(string $target): bool
    {
        return match ($target) {
            AttendanceSheetImportResource::class => AttendanceSheetImports::allowed(auth()->user()),
            PunchImportResource::class => PunchImportResource::canAccess(),
            default => RowImportTypes::allowed(auth()->user(), $target),
        };
    }

    /** The upload screen with its upload dialog open (on the time office uploads screen, for this type). */
    private static function uploadUrl(string $target): string
    {
        return match ($target) {
            AttendanceSheetImportResource::class, PunchImportResource::class => $target::getUrl('index', ['action' => 'create']),
            default => TimeOfficeImportResource::getUrl('index', ['action' => 'create', 'actionArguments' => ['type' => $target]]),
        };
    }
}
