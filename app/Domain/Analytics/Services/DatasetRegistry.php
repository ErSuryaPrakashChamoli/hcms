<?php

namespace App\Domain\Analytics\Services;

use App\Domain\Analytics\Datasets\AppraisalRatingsDataset;
use App\Domain\Analytics\Datasets\AppraisalReviewsDataset;
use App\Domain\Analytics\Datasets\AssetsDataset;
use App\Domain\Analytics\Datasets\AttendanceArrearsDataset;
use App\Domain\Analytics\Datasets\AttendanceDataset;
use App\Domain\Analytics\Datasets\AttendanceMonthlyDataset;
use App\Domain\Analytics\Datasets\AttendancePenaltiesDataset;
use App\Domain\Analytics\Datasets\AttendancePunchesDataset;
use App\Domain\Analytics\Datasets\BadgesLedgerDataset;
use App\Domain\Analytics\Datasets\BgvDataset;
use App\Domain\Analytics\Datasets\CheckInsDataset;
use App\Domain\Analytics\Datasets\ContinuousFeedbackDataset;
use App\Domain\Analytics\Datasets\CtcRegisterDataset;
use App\Domain\Analytics\Datasets\Dataset;
use App\Domain\Analytics\Datasets\DevelopmentNeedsDataset;
use App\Domain\Analytics\Datasets\EmergencyContactsDataset;
use App\Domain\Analytics\Datasets\EmployeeDevicesDataset;
use App\Domain\Analytics\Datasets\EmployeeDocumentsDataset;
use App\Domain\Analytics\Datasets\EmployeeMovementsDataset;
use App\Domain\Analytics\Datasets\EmployeesDataset;
use App\Domain\Analytics\Datasets\ExitClearancesDataset;
use App\Domain\Analytics\Datasets\ExitsDataset;
use App\Domain\Analytics\Datasets\ExpenseAdvanceLedgerDataset;
use App\Domain\Analytics\Datasets\ExpenseAdvancesDataset;
use App\Domain\Analytics\Datasets\ExpenseReceiptsDataset;
use App\Domain\Analytics\Datasets\ExpenseReportsDataset;
use App\Domain\Analytics\Datasets\FamilyMembersDataset;
use App\Domain\Analytics\Datasets\Feedback360ResultsDataset;
use App\Domain\Analytics\Datasets\Feedback360StatusDataset;
use App\Domain\Analytics\Datasets\FinalSettlementsDataset;
use App\Domain\Analytics\Datasets\GoalPlansDataset;
use App\Domain\Analytics\Datasets\GoalsDataset;
use App\Domain\Analytics\Datasets\HappinessIndexDataset;
use App\Domain\Analytics\Datasets\HolidaysDataset;
use App\Domain\Analytics\Datasets\ImprovementPlansDataset;
use App\Domain\Analytics\Datasets\InitiativesDataset;
use App\Domain\Analytics\Datasets\LearningDataset;
use App\Domain\Analytics\Datasets\LeaveBalancesDataset;
use App\Domain\Analytics\Datasets\LeaveDataset;
use App\Domain\Analytics\Datasets\LeaveDonationsDataset;
use App\Domain\Analytics\Datasets\LeaveEncashmentsDataset;
use App\Domain\Analytics\Datasets\LeaveLedgerDataset;
use App\Domain\Analytics\Datasets\LettersDataset;
use App\Domain\Analytics\Datasets\LifecycleTransitionsDataset;
use App\Domain\Analytics\Datasets\LoanInstallmentsDataset;
use App\Domain\Analytics\Datasets\LoansDataset;
use App\Domain\Analytics\Datasets\ManagerChangesDataset;
use App\Domain\Analytics\Datasets\MissingDocumentsDataset;
use App\Domain\Analytics\Datasets\OnboardingDataset;
use App\Domain\Analytics\Datasets\OnboardingTasksDataset;
use App\Domain\Analytics\Datasets\OneOnOnesDataset;
use App\Domain\Analytics\Datasets\PayrollAdjustmentsDataset;
use App\Domain\Analytics\Datasets\PayrollComponentsDataset;
use App\Domain\Analytics\Datasets\PayrollDataset;
use App\Domain\Analytics\Datasets\PayslipsDataset;
use App\Domain\Analytics\Datasets\PerformanceCheckInsDataset;
use App\Domain\Analytics\Datasets\PerformanceDataset;
use App\Domain\Analytics\Datasets\PfOptionsDataset;
use App\Domain\Analytics\Datasets\PipCheckpointsDataset;
use App\Domain\Analytics\Datasets\QualificationsDataset;
use App\Domain\Analytics\Datasets\RegularisationsDataset;
use App\Domain\Analytics\Datasets\ReimbursementClaimsDataset;
use App\Domain\Analytics\Datasets\ReimbursementLedgerDataset;
use App\Domain\Analytics\Datasets\RewardPointsLedgerDataset;
use App\Domain\Analytics\Datasets\SalaryPaymentsDataset;
use App\Domain\Analytics\Datasets\SalaryRevisionsDataset;
use App\Domain\Analytics\Datasets\ServiceDeskDataset;
use App\Domain\Analytics\Datasets\ShiftRosterDataset;
use App\Domain\Analytics\Datasets\StatutoryDetailsDataset;
use App\Domain\Analytics\Datasets\StatutoryOverridesDataset;
use App\Domain\Analytics\Datasets\TaxDeclarationsDataset;
use App\Domain\Analytics\Datasets\TaxIncomeEntriesDataset;
use App\Domain\Analytics\Datasets\TaxInvestmentsDataset;
use App\Domain\Analytics\Datasets\TimeOfficeRequestsDataset;
use App\Domain\Analytics\Datasets\TravelRequestLegsDataset;
use App\Domain\Analytics\Datasets\TravelRequestsDataset;
use App\Domain\Analytics\Datasets\UsersDataset;
use App\Domain\Analytics\Datasets\VariablePayPlansDataset;
use App\Domain\Analytics\Datasets\VariablePayRequestsDataset;
use App\Domain\Identity\Models\User;
use RuntimeException;

final class DatasetRegistry
{
    /** @var array<string, class-string<Dataset>> */
    private const DATASETS = [
        'employees' => EmployeesDataset::class, 'attendance' => AttendanceDataset::class, 'leave' => LeaveDataset::class, 'payroll' => PayrollDataset::class,
        'exits' => ExitsDataset::class, 'performance' => PerformanceDataset::class, 'learning' => LearningDataset::class, 'assets' => AssetsDataset::class, 'servicedesk' => ServiceDeskDataset::class,
        // People
        'employee_movements' => EmployeeMovementsDataset::class, 'manager_changes' => ManagerChangesDataset::class, 'lifecycle_transitions' => LifecycleTransitionsDataset::class,
        'statutory_bank' => StatutoryDetailsDataset::class, 'family_members' => FamilyMembersDataset::class, 'emergency_contacts' => EmergencyContactsDataset::class,
        'qualifications' => QualificationsDataset::class, 'employee_documents' => EmployeeDocumentsDataset::class, 'letters' => LettersDataset::class,
        // HROne setup parity CA-4: mandatory documents not on file.
        'missing_documents' => MissingDocumentsDataset::class,
        // HROne setup parity HP-9c: the badges ledger.
        'badges_ledger' => BadgesLedgerDataset::class,
        // HROne setup parity HP-9d / HP-9f: the reward points ledger and the happiness index (suppressed aggregates only).
        'reward_points_ledger' => RewardPointsLedgerDataset::class, 'happiness_index' => HappinessIndexDataset::class,
        // Attendance and leave
        'attendance_monthly' => AttendanceMonthlyDataset::class, 'attendance_punches' => AttendancePunchesDataset::class, 'attendance_regularisations' => RegularisationsDataset::class,
        'shift_roster' => ShiftRosterDataset::class, 'holidays' => HolidaysDataset::class,
        'leave_balances' => LeaveBalancesDataset::class, 'leave_ledger' => LeaveLedgerDataset::class, 'leave_encashments' => LeaveEncashmentsDataset::class,
        // HROne parity HP-1: time-office requests, penalties, arrears, check-in devices, leave donations.
        'time_office_requests' => TimeOfficeRequestsDataset::class, 'attendance_penalties' => AttendancePenaltiesDataset::class, 'attendance_arrears' => AttendanceArrearsDataset::class,
        'checkin_devices' => EmployeeDevicesDataset::class, 'leave_donations' => LeaveDonationsDataset::class,
        // HROne parity HP-3: perquisites and outside income, statutory overrides, PF options.
        'tax_income_entries' => TaxIncomeEntriesDataset::class, 'statutory_overrides' => StatutoryOverridesDataset::class, 'pf_options' => PfOptionsDataset::class,
        // Payroll, compensation and income tax
        'payroll_components' => PayrollComponentsDataset::class, 'payroll_adjustments' => PayrollAdjustmentsDataset::class, 'payslips' => PayslipsDataset::class,
        'ctc_register' => CtcRegisterDataset::class, 'salary_revisions' => SalaryRevisionsDataset::class,
        // HROne setup parity BP-13: how each salary was paid (bank transfer, cash, cheque).
        'salary_payments' => SalaryPaymentsDataset::class,
        'tax_declarations' => TaxDeclarationsDataset::class, 'tax_investments' => TaxInvestmentsDataset::class,
        // Joining and exit
        'onboarding' => OnboardingDataset::class, 'onboarding_tasks' => OnboardingTasksDataset::class, 'bgv' => BgvDataset::class,
        'exit_clearances' => ExitClearancesDataset::class, 'final_settlements' => FinalSettlementsDataset::class,
        // Performance and administration
        'goals' => GoalsDataset::class, 'improvement_plans' => ImprovementPlansDataset::class, 'users' => UsersDataset::class,
        // HROne setup parity HP-8j: initiatives (HROne report "Initiative details").
        'initiatives' => InitiativesDataset::class,
        // HROne setup parity EX-G: expense receipts, reports and their payment, advances and their ledger.
        'expense_receipts' => ExpenseReceiptsDataset::class, 'expense_reports' => ExpenseReportsDataset::class,
        'expense_advances' => ExpenseAdvancesDataset::class, 'expense_advance_ledger' => ExpenseAdvanceLedgerDataset::class,
        // HROne setup parity EX-F: travel requests and their legs (journeys, stays, bookings).
        'travel_requests' => TravelRequestsDataset::class, 'travel_request_legs' => TravelRequestLegsDataset::class,
        // HROne parity AN-26: loans and their EMI ledger, the reimbursement entitlement ledger and claims, variable pay plans and requests.
        'loans' => LoansDataset::class, 'loan_installments' => LoanInstallmentsDataset::class,
        'reimbursement_ledger' => ReimbursementLedgerDataset::class, 'reimbursement_claims' => ReimbursementClaimsDataset::class,
        'variable_pay_plans' => VariablePayPlansDataset::class, 'variable_pay_requests' => VariablePayRequestsDataset::class,
        // HROne parity AN-27: one-on-ones, continuous feedback, 360 status and results (aggregates only), goal and conversation
        // check-ins, goal sheets (incl. employees without goals), PIP checkpoints, appraisal reviews and ratings, development needs.
        'one_on_ones' => OneOnOnesDataset::class, 'continuous_feedback' => ContinuousFeedbackDataset::class,
        'feedback_360_status' => Feedback360StatusDataset::class, 'feedback_360_results' => Feedback360ResultsDataset::class,
        'check_ins' => CheckInsDataset::class, 'performance_check_ins' => PerformanceCheckInsDataset::class, 'goal_plans' => GoalPlansDataset::class,
        'pip_checkpoints' => PipCheckpointsDataset::class, 'appraisal_reviews' => AppraisalReviewsDataset::class, 'appraisal_ratings' => AppraisalRatingsDataset::class,
        'development_needs' => DevelopmentNeedsDataset::class,
    ];

    /** Display order of dataset categories. */
    public const CATEGORIES = ['People', 'Attendance', 'Leave', 'Payroll & compensation', 'Income tax', 'Expense & travel', 'Joining & exit', 'Performance & learning', 'Assets & service desk', 'Administration', 'Other'];

    /** @return array<string, Dataset> */
    public function all(): array
    {
        return array_map(fn ($class) => app($class), self::DATASETS);
    }

    public function get(string $key): Dataset
    {
        $class = self::DATASETS[$key] ?? throw new RuntimeException("Unknown dataset '{$key}'.");

        return app($class);
    }

    public function has(string $key): bool
    {
        return isset(self::DATASETS[$key]);
    }

    /** @return array<string, Dataset> */
    public function availableTo(User $user): array
    {
        return array_filter($this->all(), fn (Dataset $d) => $d->allowedFor($user));
    }

    /** @return array<string, string> key => label */
    public function options(?User $user = null): array
    {
        return array_map(fn (Dataset $d) => $d->label(), $user ? $this->availableTo($user) : $this->all());
    }

    /** @return array<string, array<string, string>> category => [key => label], in CATEGORIES order */
    public function groupedOptions(?User $user = null): array
    {
        $grouped = [];
        foreach ($user ? $this->availableTo($user) : $this->all() as $key => $dataset) {
            $grouped[$dataset->category()][$key] = $dataset->label();
        }
        uksort($grouped, fn ($a, $b) => self::categoryRank($a) <=> self::categoryRank($b));

        return $grouped;
    }

    public static function categoryRank(string $category): int
    {
        $rank = array_search($category, self::CATEGORIES, true);

        return $rank === false ? count(self::CATEGORIES) : $rank;
    }
}
