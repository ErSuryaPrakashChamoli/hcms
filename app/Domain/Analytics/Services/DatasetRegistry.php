<?php

namespace App\Domain\Analytics\Services;

use App\Domain\Analytics\Datasets\AssetsDataset;
use App\Domain\Analytics\Datasets\AttendanceDataset;
use App\Domain\Analytics\Datasets\AttendanceMonthlyDataset;
use App\Domain\Analytics\Datasets\AttendancePunchesDataset;
use App\Domain\Analytics\Datasets\BgvDataset;
use App\Domain\Analytics\Datasets\CtcRegisterDataset;
use App\Domain\Analytics\Datasets\Dataset;
use App\Domain\Analytics\Datasets\EmergencyContactsDataset;
use App\Domain\Analytics\Datasets\EmployeeDocumentsDataset;
use App\Domain\Analytics\Datasets\EmployeeMovementsDataset;
use App\Domain\Analytics\Datasets\EmployeesDataset;
use App\Domain\Analytics\Datasets\ExitClearancesDataset;
use App\Domain\Analytics\Datasets\ExitsDataset;
use App\Domain\Analytics\Datasets\FamilyMembersDataset;
use App\Domain\Analytics\Datasets\FinalSettlementsDataset;
use App\Domain\Analytics\Datasets\GoalsDataset;
use App\Domain\Analytics\Datasets\HolidaysDataset;
use App\Domain\Analytics\Datasets\ImprovementPlansDataset;
use App\Domain\Analytics\Datasets\LearningDataset;
use App\Domain\Analytics\Datasets\LeaveBalancesDataset;
use App\Domain\Analytics\Datasets\LeaveDataset;
use App\Domain\Analytics\Datasets\LeaveEncashmentsDataset;
use App\Domain\Analytics\Datasets\LeaveLedgerDataset;
use App\Domain\Analytics\Datasets\LettersDataset;
use App\Domain\Analytics\Datasets\LifecycleTransitionsDataset;
use App\Domain\Analytics\Datasets\ManagerChangesDataset;
use App\Domain\Analytics\Datasets\OnboardingDataset;
use App\Domain\Analytics\Datasets\OnboardingTasksDataset;
use App\Domain\Analytics\Datasets\PayrollAdjustmentsDataset;
use App\Domain\Analytics\Datasets\PayrollComponentsDataset;
use App\Domain\Analytics\Datasets\PayrollDataset;
use App\Domain\Analytics\Datasets\PayslipsDataset;
use App\Domain\Analytics\Datasets\PerformanceDataset;
use App\Domain\Analytics\Datasets\QualificationsDataset;
use App\Domain\Analytics\Datasets\RegularisationsDataset;
use App\Domain\Analytics\Datasets\SalaryRevisionsDataset;
use App\Domain\Analytics\Datasets\ServiceDeskDataset;
use App\Domain\Analytics\Datasets\ShiftRosterDataset;
use App\Domain\Analytics\Datasets\StatutoryDetailsDataset;
use App\Domain\Analytics\Datasets\TaxDeclarationsDataset;
use App\Domain\Analytics\Datasets\TaxInvestmentsDataset;
use App\Domain\Analytics\Datasets\UsersDataset;
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
        // Attendance and leave
        'attendance_monthly' => AttendanceMonthlyDataset::class, 'attendance_punches' => AttendancePunchesDataset::class, 'attendance_regularisations' => RegularisationsDataset::class,
        'shift_roster' => ShiftRosterDataset::class, 'holidays' => HolidaysDataset::class,
        'leave_balances' => LeaveBalancesDataset::class, 'leave_ledger' => LeaveLedgerDataset::class, 'leave_encashments' => LeaveEncashmentsDataset::class,
        // Payroll, compensation and income tax
        'payroll_components' => PayrollComponentsDataset::class, 'payroll_adjustments' => PayrollAdjustmentsDataset::class, 'payslips' => PayslipsDataset::class,
        'ctc_register' => CtcRegisterDataset::class, 'salary_revisions' => SalaryRevisionsDataset::class,
        'tax_declarations' => TaxDeclarationsDataset::class, 'tax_investments' => TaxInvestmentsDataset::class,
        // Joining and exit
        'onboarding' => OnboardingDataset::class, 'onboarding_tasks' => OnboardingTasksDataset::class, 'bgv' => BgvDataset::class,
        'exit_clearances' => ExitClearancesDataset::class, 'final_settlements' => FinalSettlementsDataset::class,
        // Performance and administration
        'goals' => GoalsDataset::class, 'improvement_plans' => ImprovementPlansDataset::class, 'users' => UsersDataset::class,
    ];

    /** Display order of dataset categories. */
    public const CATEGORIES = ['People', 'Attendance', 'Leave', 'Payroll & compensation', 'Income tax', 'Joining & exit', 'Performance & learning', 'Assets & service desk', 'Administration', 'Other'];

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
