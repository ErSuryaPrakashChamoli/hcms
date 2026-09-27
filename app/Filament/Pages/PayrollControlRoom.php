<?php

namespace App\Filament\Pages;

use App\Domain\Compliance\Models\CompanyStatutoryProfile;
use App\Domain\Compliance\Models\ComplianceRule;
use App\Domain\Compliance\Services\ComplianceRules;
use App\Domain\Employment\Models\Employee;
use App\Domain\Organisation\Models\Company;
use App\Domain\Payroll\Models\EmployeeSalaryAssignment;
use App\Domain\Payroll\Models\PayrollPeriod;
use App\Domain\Payroll\Models\PayrollRun;
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

    public static function canAccess(): bool
    {
        return auth()->user()?->can('payroll.view') ?? false;
    }

    public function getSubheading(): ?string
    {
        $unverified = app(ComplianceRules::class)->unverified()->pluck('code')->unique();

        return $unverified->isEmpty()
            ? null
            : 'Statutory rules '.$unverified->implode(', ').' are ILLUSTRATIVE / development only. Verify them against official sources before running production payroll.';
    }

    protected function getHeaderActions(): array
    {
        return [PayrollActions::openRun()];
    }

    /** @return array<int, array<string, mixed>> */
    public function getCompanies(): array
    {
        $now = now();

        return Company::query()->orderBy('name')->get()->map(function (Company $company) use ($now) {
            $period = PayrollPeriod::query()->where('company_id', $company->id)->where('year', $now->year)->where('month', $now->month)->first();
            $run = $period?->runs()->orderByDesc('id')->first();
            $headcount = Employee::query()->employed()->whereHas('positions', fn (Builder $q) => $q->where('company_id', $company->id)->currentlyEffective())->count();
            $withSalary = EmployeeSalaryAssignment::query()->currentlyEffective()->whereHas('employee', fn (Builder $q) => $q->employed()->whereHas('positions', fn (Builder $p) => $p->where('company_id', $company->id)->currentlyEffective()))->distinct('employee_id')->count('employee_id');

            return [
                'company' => $company,
                'period_label' => $now->format('M Y'),
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
            'no_salary' => (clone $employed)->whereDoesntHave('salaryAssignments', fn (Builder $q) => $q->currentlyEffective())->count(),
            'no_bank' => (clone $employed)->whereDoesntHave('bankAccounts', fn (Builder $q) => $q->where('is_primary', true))->count(),
            'no_pan' => (clone $employed)->where(fn (Builder $q) => $q->whereDoesntHave('statutoryDetail')->orWhereHas('statutoryDetail', fn (Builder $s) => $s->whereNull('pan')))->count(),
            'rules' => ComplianceRule::query()->where('status', 'active')->currentlyEffective()->count(),
        ];
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
