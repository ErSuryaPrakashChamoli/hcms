<?php

namespace App\Filament\Pages;

use App\Domain\Compensation\Contracts\CompensationOutput;
use App\Domain\Compensation\Services\CompensationAccess;
use App\Domain\Compensation\Support\CompensationSnapshot;
use App\Domain\Compliance\Models\TaxIncomeEntry;
use App\Domain\Compliance\Services\FinancialYear;
use App\Domain\Employment\Models\Employee;
use App\Domain\Employment\Services\SensitiveAccessAuditor;
use App\Domain\Experience\Services\MyProfileSections;
use App\Domain\Identity\Services\CurrentEmployee;
use App\Domain\Payroll\Contracts\PaidPayReader;
use App\Domain\Payroll\Models\SalaryComponent;
use App\Domain\Payroll\Models\VariablePayPlan;
use App\Domain\Payroll\Services\MyPay;
use App\Domain\Platform\Services\SettingsRepository;
use App\Filament\Support\Pages\Concerns\ConfirmsPayPassword;
use App\Http\Middleware\ConfirmPasswordForPay;
use BackedEnum;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Collection;
use Livewire\Attributes\Url;
use UnitEnum;

/**
 * Phase 11 employee self-service: my own approved compensation — in force today, scheduled and past —
 * when the tenant allows it (setting compensation.self_service, permission compensation.self). Never
 * proposals, reasons, internal notes, ranges or anyone else's pay. Opening it is an audited read.
 *
 * HROne parity MS-9 (My space → CTC): tabs "Salary structure" / "Salary insights" (the insights tab only when the
 * organisation shows the wage split or the change at each revision), a statutory block and the pay mode on the structure
 * tab (each behind its own switch, default off), the Code on Wages verdict on the insights tab (thresholds only from
 * verified rules) and, when the organisation asks for it, the login password again before the page opens.
 */
class MyCompensation extends Page
{
    use ConfirmsPayPassword;

    /** HROne parity MS-9: the password asked again first, while compensation.self_service.confirm_password is on. */
    protected static string|array $routeMiddleware = ConfirmPasswordForPay::class.':self';

    public const TABS = ['structure' => 'Salary structure', 'insights' => 'Salary insights'];

    #[Url]
    public string $tab = 'structure';

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
        if (! array_key_exists($this->tab, $this->tabs())) {
            $this->tab = 'structure';
        }
    }

    /**
     * HROne parity MS-9: the tabs, when there is more than the structure to show (none = the page as before).
     *
     * @return array<string, string>
     */
    public function tabs(): array
    {
        return $this->shows('wage_split') || $this->shows('insight') ? self::TABS : [];
    }

    public function setTab(string $tab): void
    {
        $this->tab = array_key_exists($tab, $this->tabs()) ? $tab : 'structure';
    }

    /**
     * HROne parity MS-9: PF / ESI / PT / LWF and the tax regime on the employee's pay (compensation.self_service.show_statutory).
     *
     * @return array{period: ?string, rows: list<array<string, mixed>>, tax_regime: ?string}|null
     */
    public function getStatutory(): ?array
    {
        $employee = self::employee();

        return $employee && $this->shows('statutory') ? app(MyPay::class)->statutory(auth()->user(), $employee) : null;
    }

    /** HROne parity MS-9: how salary is paid (compensation.self_service.show_pay_mode; hidden with the Bank profile section). */
    public function getPayMode(): ?string
    {
        $employee = self::employee();
        if ($employee === null || ! $this->shows('pay_mode')) {
            return null;
        }
        $bank = app(MyProfileSections::class)->bank(auth()->user(), $employee);

        return $bank['pay_mode'] === '' ? null : $bank['pay_mode'].($bank['reimbursement_mode'] && $bank['reimbursement_mode'] !== $bank['pay_mode'] ? ' · reimbursements by '.mb_strtolower($bank['reimbursement_mode']) : '');
    }

    /**
     * HROne parity MS-9 "Salary insights": the Code on Wages verdict on the latest paid pay (with the wage split shown).
     *
     * @return array<string, mixed>|null
     */
    public function getWageCode(): ?array
    {
        $employee = self::employee();

        return $employee && $this->shows('wage_split') ? app(MyPay::class)->wageCode(auth()->user(), $employee) : null;
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
