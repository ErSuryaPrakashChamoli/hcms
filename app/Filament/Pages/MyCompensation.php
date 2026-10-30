<?php

namespace App\Filament\Pages;

use App\Domain\Compensation\Contracts\CompensationOutput;
use App\Domain\Compensation\Services\CompensationAccess;
use App\Domain\Compensation\Support\CompensationSnapshot;
use App\Domain\Compliance\Models\TaxIncomeEntry;
use App\Domain\Compliance\Services\FinancialYear;
use App\Domain\Employment\Models\Employee;
use App\Domain\Employment\Services\SensitiveAccessAuditor;
use App\Domain\Identity\Services\CurrentEmployee;
use App\Domain\Payroll\Contracts\PaidPayReader;
use App\Domain\Payroll\Models\SalaryComponent;
use App\Domain\Payroll\Models\VariablePayPlan;
use App\Domain\Platform\Services\SettingsRepository;
use BackedEnum;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Collection;
use UnitEnum;

/**
 * Phase 11 employee self-service: my own approved compensation — in force today, scheduled and past —
 * when the tenant allows it (setting compensation.self_service, permission compensation.self). Never
 * proposals, reasons, internal notes, ranges or anyone else's pay. Opening it is an audited read.
 */
class MyCompensation extends Page
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBanknotes;

    protected static string|UnitEnum|null $navigationGroup = 'Me';

    protected static ?string $navigationLabel = 'My compensation';

    protected static ?string $title = 'My compensation';

    protected static ?int $navigationSort = 8;

    protected string $view = 'filament.pages.my-compensation';

    public static function canAccess(): bool
    {
        $me = self::employee();

        return $me !== null && app(CompensationAccess::class)->level(auth()->user(), $me) === 'self';
    }

    public function mount(): void
    {
        // UX.19: refuse before recording a sensitive view (Filament's own access hook runs after mount(), and someone
        // without an employee record got a 500 instead of a 403).
        abort_unless(static::canAccess(), 403);
        app(SensitiveAccessAuditor::class)->recordView(self::employee(), 'compensation', 'self-service');
    }

    public static function employee(): ?Employee
    {
        return auth()->check() ? app(CurrentEmployee::class)->of(auth()->user()) : null;
    }

    /** @return Collection<int, CompensationSnapshot> newest first */
    public function getHistory(): Collection
    {
        return app(CompensationOutput::class)->history(self::employee())->reverse()->values();
    }

    public function getCurrent(): ?CompensationSnapshot
    {
        return app(CompensationOutput::class)->on(self::employee());
    }

    /** HROne setup parity (PS-3): what the organisation lets employees see on this page. */
    public function shows(string $what): bool
    {
        return (bool) app(SettingsRepository::class)->get("compensation.self_service.show_{$what}", $what === 'history');
    }

    /**
     * Net take-home, wages and exclusions from the latest finalized payslip (the page shows only what was paid).
     *
     * @return array{net: float, gross: float, wages: float, exclusions: float, wage_share: float, period: string}|null
     */
    public function getLatestPay(): ?array
    {
        $employee = self::employee();

        return $employee ? app(PaidPayReader::class)->latestPaid($employee) : null;
    }

    /** @return Collection<int, TaxIncomeEntry> this financial year's perquisites */
    public function getPerquisites(): Collection
    {
        return TaxIncomeEntry::query()->active()->where('kind', 'perquisite')->where('employee_id', self::employee()?->id)->where('financial_year', app(FinancialYear::class)->label(now()))->get();
    }

    /**
     * The fixed monthly amounts on a compensation, without input components (HROne setup parity BP-2: values formulas read
     * that are never paid).
     *
     * @return array<string, float>
     */
    public function paidValues(CompensationSnapshot $snapshot): array
    {
        $inputs = SalaryComponent::query()->where('calculation_method', 'input')->whereIn('code', array_keys($snapshot->componentValues))->pluck('code')->all();

        return array_diff_key($snapshot->componentValues, array_flip($inputs));
    }

    /**
     * HROne setup parity BP-9 (variable payment schedule, when the organisation shows it): the employee's active plans,
     * each month's target and what was asked or paid for it.
     *
     * @return list<array{name: string, component: string, months: list<array{month: string, target: float, status: ?string, amount: ?float}>}>
     */
    public function getVariablePlans(): array
    {
        $employee = self::employee();
        if ($employee === null) {
            return [];
        }

        return VariablePayPlan::query()->with(['component', 'requests'])->where('employee_id', $employee->id)->where('status', 'active')->orderBy('id')->get()
            ->map(fn (VariablePayPlan $plan) => ['name' => $plan->name ?: (string) $plan->component?->name, 'component' => (string) $plan->component?->name,
                'months' => collect($plan->targets())->map(function (float $target, string $month) use ($plan) {
                    $request = $plan->requests->where('plan_month', $month)->whereNotIn('status', ['rejected', 'cancelled'])->sortByDesc('id')->first();

                    return ['month' => $month, 'target' => $target, 'status' => $request?->status, 'amount' => $request ? (float) $request->amount : null];
                })->values()->all()])->all();
    }

    /** Change of annual CTC against the row before it, in percent, keyed by the row's start date. @return array<string, float> */
    public function getInsight(): array
    {
        $rows = app(CompensationOutput::class)->history(self::employee())->values();
        $out = [];
        foreach ($rows as $i => $row) {
            $before = $rows->get($i - 1);
            if ($before !== null && (float) $before->ctcAnnual > 0) {
                $out[$row->effectiveFrom->toDateString()] = round(((float) $row->ctcAnnual - (float) $before->ctcAnnual) / (float) $before->ctcAnnual * 100, 1);
            }
        }

        return $out;
    }
}
