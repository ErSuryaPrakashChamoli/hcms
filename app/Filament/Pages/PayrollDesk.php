<?php

namespace App\Filament\Pages;

use App\Filament\Resources\AttendanceArrears\AttendanceArrearResource;
use App\Filament\Resources\BankAccountRules\BankAccountRuleResource;
use App\Filament\Resources\BankFileLayouts\BankFileLayoutResource;
use App\Filament\Resources\CompanyBankAccounts\CompanyBankAccountResource;
use App\Filament\Resources\CompensationChanges\CompensationChangeResource;
use App\Filament\Resources\EmployeePaymentModes\EmployeePaymentModeResource;
use App\Filament\Resources\EstablishmentStatutoryProfiles\EstablishmentStatutoryProfileResource;
use App\Filament\Resources\ExitCases\ExitCaseResource;
use App\Filament\Resources\LeaveEncashments\LeaveEncashmentResource;
use App\Filament\Resources\Letters\LetterResource;
use App\Filament\Resources\Loans\LoanResource;
use App\Filament\Resources\PayrollAdjustments\PayrollAdjustmentResource;
use App\Filament\Resources\PayrollRuns\PayrollRunResource;
use App\Filament\Resources\PayrollUploads\PayrollUploadResource;
use App\Filament\Resources\Payslips\PayslipResource;
use App\Filament\Resources\PfOptions\PfOptionResource;
use App\Filament\Resources\ReimbursementClaims\ReimbursementClaimResource;
use App\Filament\Resources\Reports\ReportResource;
use App\Filament\Resources\SalaryComponents\SalaryComponentResource;
use App\Filament\Resources\SalaryHolds\SalaryHoldResource;
use App\Filament\Resources\SalaryImports\SalaryImportResource;
use App\Filament\Resources\SalaryStructures\SalaryStructureResource;
use App\Filament\Resources\StatutoryOverrides\StatutoryOverrideResource;
use App\Filament\Resources\TaxDeclarations\TaxDeclarationResource;
use App\Filament\Resources\TaxIncomeEntries\TaxIncomeEntryResource;
use App\Filament\Resources\TdsInvestments\TdsInvestmentResource;
use App\Filament\Resources\VariablePayPlans\VariablePayPlanResource;
use App\Filament\Resources\VariablePayRequests\VariablePayRequestResource;
use App\Filament\Support\Pages\OperationsDeskPage;
use App\Filament\Support\PayrollRegisters;
use BackedEnum;
use Closure;
use Filament\Support\Icons\Heroicon;
use Illuminate\Database\Eloquent\Builder;
use UnitEnum;

/**
 * HROne parity PO-1 (HR Desk → Payroll panel, matrix 25 §25.1 #1): the payroll registers in HROne's panel order —
 * Salary structure · Tax & benefit · Variable pay · Loan · Payroll processing · Full and final settlement · Payroll
 * letters · Reports · Payroll setup. HROne's separate request, cancellation and history registers are preset views
 * (`?view=`) of the one register each record type has; nothing here is a new register and nothing acts on records.
 * Opens for people who run payroll (payroll.view plus an operations right) and lists only what each can open; counts
 * come from each register's own (scoped) query. Read-only viewers (auditors) and employees get no desk.
 */
class PayrollDesk extends OperationsDeskPage
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedRectangleGroup;

    protected static string|UnitEnum|null $navigationGroup = 'Payroll';

    protected static ?string $navigationLabel = 'Payroll desk';

    protected static ?string $title = 'Payroll desk';

    protected static ?string $slug = 'payroll-desk';

    protected static ?int $navigationSort = 0;

    /** Any one of these, with payroll.view, makes someone a payroll operator for the desk. */
    public const OPERATOR_PERMISSIONS = ['payroll.manage', 'payroll.calculate', 'payroll.approve', 'payroll.finalize', 'payroll.override'];

    public static function canAccess(): bool
    {
        $user = auth()->user();
        if ($user === null || ! $user->hasPermission('payroll.view')) {
            return false;
        }
        foreach (self::OPERATOR_PERMISSIONS as $permission) {
            if ($user->hasPermission($permission)) {
                return static::anyEntryOpens();
            }
        }

        return false;
    }

    public function mount(): void
    {
        abort_unless(static::canAccess(), 403);
    }

    public function getSubheading(): ?string
    {
        return 'Every payroll register in one place, in the order a pay month moves through them. Open a register on its view, filter by employee, organisation, pay group or dates, save the views you use and report on what you see.';
    }

    protected function getHeaderActions(): array
    {
        return [PayrollRegisters::actionsMenu()];
    }

    protected function registers(): array
    {
        return ['reimbursement_claims', 'variable_pay_requests', 'loans', 'salary_holds', 'compensation_changes'];
    }

    protected function sections(): array
    {
        $count = fn (string $resource, Closure $where) => fn () => $where($resource::getEloquentQuery())->count();

        return [
            ['label' => 'Salary structure', 'items' => [
                ['label' => 'Salary structures', 'target' => SalaryStructureResource::class, 'description' => 'Pay groups and their approved versions'],
                ['label' => 'Salary structure requests', 'target' => CompensationChangeResource::class, 'params' => ['view' => 'waiting'], 'description' => 'Compensation changes waiting for review (then approval and execution)',
                    'count' => $count(CompensationChangeResource::class, fn (Builder $q) => $q->where('status', 'submitted'))],
                ['label' => 'Salary revisions', 'target' => CompensationChangeResource::class, 'params' => ['view' => 'effective'], 'description' => 'Changes in force or scheduled'],
                ['label' => 'Salary upload', 'target' => SalaryImportResource::class, 'description' => 'Assign or revise salaries from a file'],
            ]],
            ['label' => 'Tax & benefit', 'items' => [
                ['label' => 'Reimbursement requests', 'target' => ReimbursementClaimResource::class, 'params' => ['view' => 'waiting'], 'description' => 'Claims waiting for approval',
                    'count' => $count(ReimbursementClaimResource::class, fn (Builder $q) => $q->where('status', 'pending'))],
                ['label' => 'Reimbursement cancellations', 'target' => ReimbursementClaimResource::class, 'params' => ['view' => 'cancellations']],
                ['label' => 'Proposed investments', 'target' => TaxDeclarationResource::class, 'params' => ['view' => 'submitted'], 'description' => 'Declarations submitted, to verify',
                    'count' => $count(TaxDeclarationResource::class, fn (Builder $q) => $q->where('status', 'submitted'))],
                ['label' => 'Confirmed investments', 'target' => TdsInvestmentResource::class, 'params' => ['view' => 'pending'], 'description' => 'Investment proofs waiting for verification',
                    'count' => $count(TdsInvestmentResource::class, fn (Builder $q) => $q->where('proof_status', 'pending'))],
                ['label' => 'Declare investments for an employee', 'target' => DeclareInvestments::class],
                ['label' => 'Perquisites and other income', 'target' => TaxIncomeEntryResource::class],
            ]],
            ['label' => 'Variable pay', 'items' => [
                ['label' => 'Variable pay requests', 'target' => VariablePayRequestResource::class, 'params' => ['view' => 'waiting'], 'description' => 'Incentives and bonuses waiting for approval',
                    'count' => $count(VariablePayRequestResource::class, fn (Builder $q) => $q->where('status', 'pending'))],
                ['label' => 'Variable pay cancellations', 'target' => VariablePayRequestResource::class, 'params' => ['view' => 'cancellations']],
                ['label' => 'Variable pay plans', 'target' => VariablePayPlanResource::class],
            ]],
            ['label' => 'Loan', 'items' => [
                ['label' => 'Loan requests', 'target' => LoanResource::class, 'params' => ['view' => 'requests'], 'description' => 'Loans and advances waiting for approval',
                    'count' => $count(LoanResource::class, fn (Builder $q) => $q->where('status', 'pending'))],
                ['label' => 'Dispense loans', 'target' => LoanResource::class, 'params' => ['view' => 'to_disburse'], 'description' => 'Approved, to disburse',
                    'count' => $count(LoanResource::class, fn (Builder $q) => $q->where('status', 'approved'))],
                ['label' => 'Loans', 'target' => LoanResource::class, 'params' => ['view' => 'recovering'], 'description' => 'Being recovered through payroll'],
            ]],
            ['label' => 'Payroll processing', 'items' => [
                ['label' => 'Run payroll', 'target' => PayrollControlRoom::class, 'description' => 'This month per company, readiness checks and the runs'],
                ['label' => 'Payroll runs', 'target' => PayrollRunResource::class, 'description' => 'Runs not yet finalized',
                    'count' => $count(PayrollRunResource::class, fn (Builder $q) => $q->whereNotIn('status', ['finalized', 'paid']))],
                ['label' => 'Adjustments and arrears', 'target' => PayrollAdjustmentResource::class],
                ['label' => 'Attendance arrears', 'target' => AttendanceArrearResource::class, 'description' => 'Corrected days for the next payroll',
                    'count' => $count(AttendanceArrearResource::class, fn (Builder $q) => $q->where('status', 'pending'))],
                ['label' => 'Leave encashment', 'target' => LeaveEncashmentResource::class, 'params' => ['view' => 'approved'], 'description' => 'Approved, paid by the next payroll'],
                ['label' => 'Salary holds', 'target' => SalaryHoldResource::class, 'params' => ['view' => 'held'],
                    'count' => $count(SalaryHoldResource::class, fn (Builder $q) => $q->where('status', 'held'))],
                ['label' => 'Statutory overrides', 'target' => StatutoryOverrideResource::class],
                ['label' => 'PF options (VPF)', 'target' => PfOptionResource::class],
                ['label' => 'Payslips', 'target' => PayslipResource::class],
                ['label' => 'Payroll Auditor', 'target' => PayrollAuditor::class],
                ['label' => 'Generate challan', 'target' => ComplianceReportsPage::class, 'description' => 'PF, ESI, PT and LWF registers, challans and returns'],
                ['label' => 'Compliance control room', 'target' => ComplianceControlRoom::class],
                ['label' => 'Pay cycle', 'target' => PayCyclePage::class],
            ]],
            ['label' => 'Full and final settlement', 'items' => [
                ['label' => 'Settlement', 'target' => ExitCaseResource::class, 'params' => ['view' => 'settlement'], 'description' => 'Exits in settlement',
                    'count' => $count(ExitCaseResource::class, fn (Builder $q) => $q->where('status', 'settlement'))],
                ['label' => 'Settlement requests', 'target' => ExitCaseResource::class, 'params' => ['view' => 'settled'], 'description' => 'Completed exits and their settlements'],
            ]],
            ['label' => 'Payroll letters', 'items' => [
                ['label' => 'Appointment letters', 'target' => LetterResource::class, 'params' => ['view' => 'appointment']],
                ['label' => 'Salary revision letters', 'target' => LetterResource::class, 'params' => ['view' => 'increment']],
            ]],
            ['label' => 'Reports', 'items' => [
                ['label' => 'Report library', 'target' => ReportLibraryPage::class, 'description' => 'Ready-made salary, statutory, loan, reimbursement and variable pay reports'],
                ['label' => 'My reports', 'target' => ReportResource::class],
                ['label' => 'Compensation analytics', 'target' => CompensationAnalyticsPage::class],
            ]],
            ['label' => 'Payroll setup', 'items' => [
                ['label' => 'Setup centre: payroll', 'target' => SetupCentre::class, 'params' => ['module' => 'payroll'], 'description' => 'Payroll settings and policies'],
                ['label' => 'Salary components', 'target' => SalaryComponentResource::class],
                ['label' => 'Payment banks', 'target' => CompanyBankAccountResource::class],
                ['label' => 'Payment modes', 'target' => EmployeePaymentModeResource::class],
                ['label' => 'Bank file layouts', 'target' => BankFileLayoutResource::class],
                ['label' => 'Bank account number rules', 'target' => BankAccountRuleResource::class],
                ['label' => 'Statutory profiles', 'target' => EstablishmentStatutoryProfileResource::class],
                ['label' => 'Payroll uploads', 'target' => PayrollUploadResource::class],
            ]],
        ];
    }
}
