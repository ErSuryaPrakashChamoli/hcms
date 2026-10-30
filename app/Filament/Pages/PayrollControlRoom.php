<?php

namespace App\Filament\Pages;

use App\Domain\Compensation\Contracts\CompensationOutput;
use App\Domain\Compliance\Models\CompanyStatutoryProfile;
use App\Domain\Compliance\Models\ComplianceRule;
use App\Domain\Compliance\Services\ComplianceRules;
use App\Domain\Employment\Models\Employee;
use App\Domain\Organisation\Models\Company;
use App\Domain\Payroll\Models\PayrollPeriod;
use App\Domain\Payroll\Models\PayrollRun;
use App\Domain\Payroll\Services\PayCycle;
use App\Domain\Payroll\Services\PayrollReadiness;
use App\Filament\Resources\PayrollRuns\PayrollRunResource;
use App\Filament\Support\PayrollActions;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Symfony\Component\HttpFoundation\StreamedResponse;
use UnitEnum;

/** Payroll Control Room (§31): readiness per company, this month's status, and the run register. */
class PayrollControlRoom extends Page implements HasTable
{
    use InteractsWithTable;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBanknotes;

    protected static string|UnitEnum|null $navigationGroup = 'Payroll';

    protected static ?string $navigationLabel = 'Control room';

    protected static ?string $title = 'Payroll control room';

    protected static ?int $navigationSort = 1;

    protected string $view = 'filament.pages.payroll-control-room';

    /** HROne parity PO-3: the pre-run readiness panel loads after the page (wire:init), so the page opens as fast as before. */
    public bool $readinessLoaded = false;

    /** Company the readiness checks look at (blank = every company). */
    public ?string $readinessCompany = null;

    /** HROne's "Active employees only": employed people only (default), or everyone in the pay month's population. */
    public bool $readinessActiveOnly = true;

    /** The check whose list is open. */
    public ?string $readinessCheck = null;

    /** @var array<string, mixed>|null this request's readiness result */
    private ?array $readinessResult = null;

    public static function canAccess(): bool
    {
        return auth()->user()?->can('payroll.view') ?? false;
    }

    public function getSubheading(): ?string
    {
        $unverified = app(ComplianceRules::class)->unverified()->pluck('code')->unique();

        return $unverified->isEmpty()
            ? null
            : 'Statutory rules '.$unverified->implode(', ').' are not VERIFIED against official sources'.(ComplianceRules::enforced() ? '; payroll using them cannot be finalized.' : ' (enforcement is off in this environment).');
    }

    protected function getHeaderActions(): array
    {
        return [
            PayrollActions::openRun(),
            Action::make('payCycle')->label('Pay cycle')->icon(Heroicon::OutlinedCalendarDays)->color('gray')
                ->visible(fn () => PayCyclePage::canAccess())->url(fn () => PayCyclePage::getUrl()),
            // Phase 5: statutory outputs built from finalized payroll live in the Compliance control room.
            Action::make('complianceControlRoom')->label('Compliance control room')->icon(Heroicon::OutlinedShieldCheck)->color('gray')
                ->visible(fn () => ComplianceControlRoom::canAccess())->url(fn () => ComplianceControlRoom::getUrl()),
        ];
    }

    /** @return array<int, array<string, mixed>> */
    public function getCompanies(): array
    {
        $cycle = app(PayCycle::class);

        return Company::query()->orderBy('name')->get()->map(function (Company $company) use ($cycle) {
            // The payroll month holding today: on a 25th-to-24th cycle, 26 October is in November's.
            [$year, $month] = $cycle->monthContaining($company->id, today());
            $period = PayrollPeriod::query()->where('company_id', $company->id)->where('year', $year)->where('month', $month)->first();
            [$start, $end] = $period?->isFixed() ? [$period->start_date, $period->end_date] : $cycle->bounds($company->id, $year, $month);
            $run = $period?->runs()->orderByDesc('id')->first();
            $headcount = Employee::query()->employed()->whereHas('positions', fn (Builder $q) => $q->where('company_id', $company->id)->currentlyEffective())->count();
            $withSalary = Employee::query()->employed()->whereHas('positions', fn (Builder $q) => $q->where('company_id', $company->id)->currentlyEffective())
                ->whereIn('employees.id', app(CompensationOutput::class)->coveredEmployeeIds())->count();

            return [
                'company' => $company,
                'period_label' => Carbon::create($year, $month, 1)->format('M Y'),
                'period_dates' => $start->format('j M').' – '.$end->format('j M Y'),
                'run' => $run,
                'status' => $run?->status ?? 'not_started',
                'headcount' => $headcount,
                'without_salary' => max(0, $headcount - $withSalary),
                'profile' => CompanyStatutoryProfile::query()->where('company_id', $company->id)->exists(),
                'url' => $run ? PayrollRunResource::getUrl('view', ['record' => $run]) : null,
            ];
        })->all();
    }

    /** @return array<string, int> */
    public function getReadiness(): array
    {
        $employed = Employee::query()->employed();

        return [
            'no_salary' => (clone $employed)->whereNotIn('employees.id', app(CompensationOutput::class)->coveredEmployeeIds())->count(),
            'no_bank' => (clone $employed)->whereDoesntHave('bankAccounts', fn (Builder $q) => $q->where('is_primary', true))->count(),
            'no_pan' => (clone $employed)->where(fn (Builder $q) => $q->whereDoesntHave('statutoryDetail')->orWhereHas('statutoryDetail', fn (Builder $s) => $s->whereNull('pan')))->count(),
            'rules' => ComplianceRule::query()->where('status', 'active')->currentlyEffective()->count(),
        ];
    }

    // --- HROne parity PO-3: pre-run readiness (read-only; informs, never blocks a run) -----------------------------

    public function loadReadiness(): void
    {
        $this->readinessLoaded = true;
    }

    /** @return array<string, string> company id => name, for the readiness company choice */
    public function readinessCompanies(): array
    {
        return Company::query()->orderBy('name')->pluck('name', 'id')->mapWithKeys(fn ($name, $id) => [(string) $id => (string) $name])->all();
    }

    /** @return array<string, mixed>|null the checks for the chosen company and population, once the panel has loaded */
    public function readiness(): ?array
    {
        if (! $this->readinessLoaded || ! static::canAccess()) {
            return null;
        }

        return $this->readinessResult ??= app(PayrollReadiness::class)->evaluate($this->readinessCompanyId(), $this->readinessActiveOnly, self::readinessAmounts());
    }

    public function openReadinessCheck(string $check): void
    {
        $this->readinessLoaded = true;
        $this->readinessCheck = array_key_exists($check, PayrollReadiness::CHECKS) && $this->readinessCheck !== $check ? $check : null;
    }

    public function updatedReadinessCompany(): void
    {
        $this->readinessResult = null;
    }

    public function updatedReadinessActiveOnly(): void
    {
        $this->readinessResult = null;
    }

    /** The open check's list as CSV (payroll.view, as the list itself; audited as a payroll export; identifiers never in it). */
    public function downloadReadiness(string $check): StreamedResponse
    {
        abort_unless(static::canAccess() && array_key_exists($check, PayrollReadiness::CHECKS), 403);
        $csv = app(PayrollReadiness::class)->csv($check, auth()->user(), $this->readinessCompanyId(), $this->readinessActiveOnly, self::readinessAmounts());

        return response()->streamDownload(fn () => print ($csv), 'payroll-readiness-'.str_replace('_', '-', $check).'-'.today()->toDateString().'.csv', ['Content-Type' => 'text/csv']);
    }

    private function readinessCompanyId(): ?int
    {
        return filled($this->readinessCompany) && ctype_digit((string) $this->readinessCompany) ? (int) $this->readinessCompany : null;
    }

    /** Amounts (CTC, wages) appear in the lists only for readers allowed compensation; ratios and rule figures always. */
    private static function readinessAmounts(): bool
    {
        return auth()->user()?->can('compensation.view') ?? false;
    }

    public function table(Table $table): Table
    {
        return $table
            ->query(fn (): Builder => PayrollRun::query()->with(['period', 'company']))
            ->columns([
                TextColumn::make('period.start_date')->label('Period')->formatStateUsing(fn ($state, PayrollRun $record) => $record->period->label())->sortable(),
                TextColumn::make('company.name')->label('Company'),
                TextColumn::make('status')->badge()->color(fn (string $state) => PayrollRunResource::statusColor($state))->formatStateUsing(fn (string $state) => config("peopleos.payroll.run_statuses.{$state}", $state)),
                TextColumn::make('totals.employees')->label('Employees')->placeholder('—'),
                TextColumn::make('totals.net')->label('Net pay')->numeric(2)->placeholder('—'),
                TextColumn::make('exception_count')->label('Exceptions')->badge()->color(fn ($state) => $state > 0 ? 'warning' : 'gray'),
            ])
            ->defaultSort('id', 'desc')
            ->paginated([10, 25])
            ->recordActions([
                Action::make('open')->label('Open')->icon('heroicon-m-arrow-top-right-on-square')->url(fn (PayrollRun $record) => PayrollRunResource::getUrl('view', ['record' => $record])),
            ])
            ->emptyStateHeading('No payroll runs yet')
            ->emptyStateDescription('Open a run for a company and month to start the pipeline.');
    }
}
