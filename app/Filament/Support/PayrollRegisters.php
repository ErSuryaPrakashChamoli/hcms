<?php

namespace App\Filament\Support;

use App\Domain\Attendance\Models\AttendanceArrear;
use App\Domain\Compensation\Contracts\CompensationOutput;
use App\Domain\Compensation\Models\SalaryStructure;
use App\Domain\Compliance\Imports\OutsideIncomeImports;
use App\Domain\Compliance\Imports\PerquisiteImports;
use App\Domain\Compliance\Imports\PfOptionImports;
use App\Domain\Compliance\Imports\StatutoryOverrideImports;
use App\Domain\Employment\Imports\RowImportTypes;
use App\Filament\Pages\ComplianceReportsPage;
use App\Filament\Pages\DeclareInvestments;
use App\Filament\Pages\PayrollControlRoom;
use App\Filament\Resources\Loans\LoanResource;
use App\Filament\Resources\PayrollUploads\PayrollUploadResource;
use App\Filament\Resources\SalaryHolds\SalaryHoldResource;
use App\Filament\Resources\SalaryImports\SalaryImportResource;
use App\Filament\Resources\TaxIncomeEntries\TaxIncomeEntryResource;
use App\Filament\Resources\VariablePayRequests\VariablePayRequestResource;
use Closure;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Forms\Components\Select;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Filters\BaseFilter;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\Indicator;
use Filament\Tables\Filters\SelectFilter;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Throwable;

/**
 * HROne parity PO-1 (matrix 25 §25.1): the register depth shared by the payroll registers, on top of WO-1 / WO-2
 * (HasRegisterTools, EmployeeFilters) as TX-1 and XO-1 did for theirs:
 *
 * - filters(): the WO-2 employee filters (pasted codes, organisation group, employee status / type / joining, the
 *   pending-resignation toggle) and the register's date ranges — only for the register's HR side, so an employee's own
 *   claims, loans, variable pay and payslips look as before.
 * - structure(): HROne's "Pay Group" filter — the salary structure of the employee's approved compensation in force on a
 *   date (the run's period end on run entries, today elsewhere), read through CompensationOutput.
 * - arrears(): "Pay Arrear (For) Month" and "Arrear Days" bands on attendance arrears; revisionMonth(): "Salary Revision
 *   (On) month" on compensation changes.
 * - uploads(): the register's "IMPORTS" (the salary upload and the payroll upload types), each shown only to people
 *   allowed that upload. actionsMenu(): HROne's split button, links to the existing payroll actions the person may take.
 *
 * Nothing here acts on records: no bulk payroll operation is offered (presentation and navigation only).
 */
final class PayrollRegisters
{
    /** Upload key => [label, upload screen class or payroll row-import type]. */
    public const UPLOADS = [
        'salary' => ['Salary upload', SalaryImportResource::class],
        'perquisites' => ['Perquisite upload', PerquisiteImports::TYPE],
        'outside_income' => ['Income outside payroll upload', OutsideIncomeImports::TYPE],
        'stat_overrides' => ['Statutory override upload', StatutoryOverrideImports::TYPE],
        'pf_options' => ['PF options (VPF) upload', PfOptionImports::TYPE],
    ];

    /** HROne's "Arrear Days" bands, over an employee's total arrear days (in the chosen month when one is chosen). */
    public const ARREAR_BANDS = [
        'one_to_five' => '1–5 days',
        'five_to_ten' => 'More than 5, up to 10 days',
        'over_ten' => 'More than 10 days',
        'zero' => 'Zero (cancel out)',
        'negative' => 'Less than zero (days taken back)',
    ];

    /** Whether the signed-in person holds the register's HR right. */
    public static function hrSide(string $permission = 'payroll.manage'): bool
    {
        return auth()->user()?->can($permission) ?? false;
    }

    /**
     * @param  array<string, array{0: string, 1: string}>  $dates  filter name => [label, date column of the register]
     * @return list<BaseFilter>
     */
    public static function filters(string $permission, array $dates = []): array
    {
        return array_map(fn (BaseFilter $filter) => $filter->visible(fn () => self::hrSide($permission)), [
            EmployeeFilters::codes('employee'),
            EmployeeFilters::organisation('employee'),
            EmployeeFilters::workforce('employee'),
            ...array_map(fn (string $name) => EmployeeFilters::dateRange($name, $dates[$name][0], $dates[$name][1]), array_keys($dates)),
            EmployeeFilters::excludePendingResignation('employee'),
        ]);
    }

    /**
     * HROne's pay-group filter: employees whose approved compensation in force on the date is on one of the chosen salary
     * structures (through the CompensationOutput contract; the access scope still applies to the register).
     *
     * @param  (Closure(): mixed)|null  $on  the date (default today)
     */
    public static function structure(string $permission, ?Closure $on = null, string $label = 'Salary structure (pay group)'): SelectFilter
    {
        return SelectFilter::make('salary_structure')
            ->label($label)
            ->multiple()
            ->options(fn () => SalaryStructure::query()->orderBy('name')->limit(500)->pluck('name', 'id')->all())
            ->query(function (Builder $query, array $data) use ($on): Builder {
                $ids = array_values(array_filter(array_map('intval', (array) ($data['values'] ?? []))));
                if ($ids === []) {
                    return $query;
                }
                $date = $on !== null ? $on() : null;

                return $query->whereIn($query->getModel()->qualifyColumn('employee_id'), app(CompensationOutput::class)->coveredEmployeeIds($date ?? today(), $ids));
            })
            ->visible(fn () => self::hrSide($permission));
    }

    /** @return array<string, string> 'Y-m' => 'Oct 2026', newest first */
    public static function monthOptions(int $back = 24, int $ahead = 0): array
    {
        $out = [];
        for ($i = -$ahead; $i <= $back; $i++) {
            $month = today()->startOfMonth()->subMonthsNoOverflow($i);
            $out[$month->format('Y-m')] = $month->format('M Y');
        }

        return $out;
    }

    /** @return array{0: string, 1: string}|null first and last day of a 'Y-m' month */
    private static function monthBounds(mixed $value): ?array
    {
        if (! is_string($value) || preg_match('/^\d{4}-\d{2}$/', $value) !== 1) {
            return null;
        }
        try {
            $first = Carbon::createFromFormat('Y-m-d', $value.'-01')->startOfDay();
        } catch (Throwable) {
            return null;
        }

        return [$first->toDateString(), $first->copy()->endOfMonth()->toDateString()];
    }

    /** "Pay Arrear (For) Month" and "Arrear Days" (per employee) on the attendance arrears register. */
    public static function arrears(string $permission): Filter
    {
        return Filter::make('arrear_month')
            ->label('Arrear for month and arrear days')
            ->columns(2)
            ->schema([
                Select::make('month')->label('Arrear for month')->options(fn () => self::monthOptions(24)),
                Select::make('days')->label('Arrear days (per employee)')->options(self::ARREAR_BANDS),
            ])
            ->query(function (Builder $query, array $data): Builder {
                $month = self::monthBounds($data['month'] ?? null);
                $band = array_key_exists((string) ($data['days'] ?? ''), self::ARREAR_BANDS) ? (string) $data['days'] : null;
                if ($month !== null) {
                    $query->whereDate($query->getModel()->qualifyColumn('date'), '>=', $month[0])->whereDate($query->getModel()->qualifyColumn('date'), '<=', $month[1]);
                }
                if ($band !== null) {
                    $employees = AttendanceArrear::query()->select('employee_id')
                        ->when($month !== null, fn (Builder $q) => $q->whereDate('date', '>=', $month[0])->whereDate('date', '<=', $month[1]))
                        ->groupBy('employee_id')
                        ->when($band === 'one_to_five', fn (Builder $q) => $q->havingRaw('SUM(delta_days) > 0 AND SUM(delta_days) <= 5'))
                        ->when($band === 'five_to_ten', fn (Builder $q) => $q->havingRaw('SUM(delta_days) > 5 AND SUM(delta_days) <= 10'))
                        ->when($band === 'over_ten', fn (Builder $q) => $q->havingRaw('SUM(delta_days) > 10'))
                        ->when($band === 'zero', fn (Builder $q) => $q->havingRaw('SUM(delta_days) = 0'))
                        ->when($band === 'negative', fn (Builder $q) => $q->havingRaw('SUM(delta_days) < 0'));
                    $query->whereIn($query->getModel()->qualifyColumn('employee_id'), $employees);
                }

                return $query;
            })
            ->indicateUsing(function (array $data): array {
                $out = [];
                if (($month = self::monthBounds($data['month'] ?? null)) !== null) {
                    $out[] = Indicator::make('Arrear for '.Carbon::parse($month[0])->format('M Y'))->removeField('month');
                }
                if (array_key_exists((string) ($data['days'] ?? ''), self::ARREAR_BANDS)) {
                    $out[] = Indicator::make('Arrear days: '.self::ARREAR_BANDS[$data['days']])->removeField('days');
                }

                return $out;
            })
            ->visible(fn () => auth()->user()?->hasPermission($permission) ?? false);
    }

    /** HROne's "Salary Month": records of a pay month kept as 'YYYY-MM' (variable pay, salary holds). */
    public static function payMonth(string $permission, string $column = 'pay_month'): SelectFilter
    {
        return SelectFilter::make('pay_month_filter')
            ->label('Pay month')
            ->options(fn () => self::monthOptions(24, 12))
            ->query(fn (Builder $query, array $data): Builder => self::monthBounds($data['value'] ?? null) === null ? $query
                : $query->where($query->getModel()->qualifyColumn($column), (string) $data['value']))
            ->visible(fn () => self::hrSide($permission));
    }

    /** "Salary Revision (On) month": compensation changes taking effect in a month. */
    public static function revisionMonth(): SelectFilter
    {
        return SelectFilter::make('revision_month')
            ->label('Revision (effective) month')
            ->options(fn () => self::monthOptions(24, 12))
            ->query(function (Builder $query, array $data): Builder {
                $month = self::monthBounds($data['value'] ?? null);

                return $month === null ? $query : $query->whereDate($query->getModel()->qualifyColumn('effective_from'), '>=', $month[0])
                    ->whereDate($query->getModel()->qualifyColumn('effective_from'), '<=', $month[1]);
            });
    }

    /** @param  list<string>  $keys  keys of UPLOADS */
    public static function uploads(array $keys): ActionGroup
    {
        $actions = [];
        foreach ($keys as $key) {
            [$label, $target] = self::UPLOADS[$key];
            $actions[] = Action::make('payrollUpload_'.$key)
                ->label($label)
                ->icon(Heroicon::OutlinedArrowUpTray)
                ->visible(fn () => (bool) rescue(fn () => $target === SalaryImportResource::class ? SalaryImportResource::canCreate() : RowImportTypes::allowed(auth()->user(), $target), false, false))
                ->url(fn () => $target === SalaryImportResource::class
                    ? SalaryImportResource::getUrl('index', ['action' => 'create'])
                    : PayrollUploadResource::getUrl('index', ['action' => 'create', 'actionArguments' => ['type' => $target]]));
        }

        return ActionGroup::make($actions)->label('Upload')->icon(Heroicon::OutlinedArrowUpTray)->button()->color('gray');
    }

    /**
     * HROne's split button on the payroll pages ("Run payroll", "Variable pay request", "Loan request" …): links to the
     * existing actions, each shown only when its screen opens for the person and they hold its right (nothing is granted;
     * the action checks again when it opens).
     */
    public static function actionsMenu(): ActionGroup
    {
        $can = fn (string $permission) => auth()->user()?->can($permission) ?? false;
        $opens = fn (Closure $check) => fn () => (bool) rescue($check, false, false);

        return ActionGroup::make([
            Action::make('posPayRun')->label('Run payroll')->icon(Heroicon::OutlinedPlayCircle)
                ->visible($opens(fn () => PayrollControlRoom::canAccess() && $can('payroll.calculate')))
                ->url(fn () => PayrollControlRoom::getUrl(['action' => 'openRun'])),
            Action::make('posPaySalaryUpload')->label('Assign salary structures (upload)')->icon(Heroicon::OutlinedArrowUpTray)
                ->visible($opens(fn () => SalaryImportResource::canCreate()))
                ->url(fn () => SalaryImportResource::getUrl('index', ['action' => 'create'])),
            Action::make('posPayInvestments')->label('Declare investments for an employee')->icon(Heroicon::OutlinedClipboardDocumentCheck)
                ->visible($opens(fn () => DeclareInvestments::canAccess() && $can('payroll.manage')))
                ->url(fn () => DeclareInvestments::getUrl()),
            Action::make('posPayVariable')->label('Variable pay request')->icon(Heroicon::OutlinedSparkles)
                ->visible($opens(fn () => VariablePayRequestResource::canAccess() && $can('payroll.manage')))
                ->url(fn () => VariablePayRequestResource::getUrl('index', ['action' => 'forEmployee'])),
            Action::make('posPayLoan')->label('Loan request')->icon(Heroicon::OutlinedCreditCard)
                ->visible($opens(fn () => LoanResource::canAccess() && $can('payroll.manage')))
                ->url(fn () => LoanResource::getUrl('index', ['action' => 'onBehalf'])),
            Action::make('posPayHold')->label('Hold salary')->icon(Heroicon::OutlinedPauseCircle)
                ->visible($opens(fn () => SalaryHoldResource::canAccess()))
                ->url(fn () => SalaryHoldResource::getUrl('index', ['action' => 'hold'])),
            Action::make('posPayPerquisite')->label('Record a perquisite')->icon(Heroicon::OutlinedGiftTop)
                ->visible($opens(fn () => TaxIncomeEntryResource::canAccess()))
                ->url(fn () => TaxIncomeEntryResource::getUrl('index', ['action' => 'perquisite'])),
            Action::make('posPayChallan')->label('Generate challan')->icon(Heroicon::OutlinedDocumentText)
                ->visible($opens(fn () => ComplianceReportsPage::canAccess()))
                ->url(fn () => ComplianceReportsPage::getUrl()),
        ])->label('Payroll actions')->icon(Heroicon::OutlinedSquares2x2)->button()->color('gray')
            ->visible(fn () => self::hrSide('payroll.view') && (self::hrSide('payroll.manage') || self::hrSide('payroll.calculate')));
    }
}
