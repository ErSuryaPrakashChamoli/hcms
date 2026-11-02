<?php

namespace App\Domain\Employment\Imports;

use App\Domain\Assets\Imports\AssetImports;
use App\Domain\Assets\Imports\AssetProductImports;
use App\Domain\Assets\Imports\AssetSubtypeImports;
use App\Domain\Assets\Imports\AssetTypeImports;
use App\Domain\Attendance\Imports\AttendanceArrearImports;
use App\Domain\Attendance\Imports\DayOverrideImports;
use App\Domain\Attendance\Imports\GeoLocationImports;
use App\Domain\Attendance\Imports\OvertimeArrearImports;
use App\Domain\Attendance\Imports\OvertimeImports;
use App\Domain\Attendance\Imports\PaidDayAdjustmentImports;
use App\Domain\Attendance\Imports\ShiftRosterImports;
use App\Domain\Career\Imports\DesignationSkillImports;
use App\Domain\Claims\Imports\ReimbursementBillImports;
use App\Domain\Claims\Imports\ReimbursementClaimImports;
use App\Domain\Claims\Imports\ReimbursementOpeningImports;
use App\Domain\Compensation\Imports\PayGroupChangeImports;
use App\Domain\Compensation\Imports\SalaryComponentImports;
use App\Domain\Compliance\Imports\HraDeclarationImports;
use App\Domain\Compliance\Imports\InvestmentImports;
use App\Domain\Compliance\Imports\OutsideIncomeImports;
use App\Domain\Compliance\Imports\PerquisiteImports;
use App\Domain\Compliance\Imports\PfOptionImports;
use App\Domain\Compliance\Imports\PreviousEmploymentImports;
use App\Domain\Compliance\Imports\PreviousSalaryImports;
use App\Domain\Compliance\Imports\StatutoryOverrideImports;
use App\Domain\Compliance\Imports\TaxCreditImports;
use App\Domain\Engagement\Imports\EventAttendanceImports;
use App\Domain\Exit\Imports\SettlementImports;
use App\Domain\Experience\Imports\ShortcutImports;
use App\Domain\Identity\Models\User;
use App\Domain\Integration\Imports\JvCodeImports;
use App\Domain\Leave\Imports\CompOffImports;
use App\Domain\Leave\Imports\LeaveBalanceImports;
use App\Domain\Leave\Imports\LeaveHistoryImports;
use App\Domain\Loans\Imports\LoanImports;
use App\Domain\Organisation\Imports\BusinessRegionImports;
use App\Domain\Organisation\Imports\BusinessUnitImports;
use App\Domain\Organisation\Imports\DepartmentImports;
use App\Domain\Organisation\Imports\DesignationImports;
use App\Domain\Organisation\Imports\DimensionImports;
use App\Domain\Organisation\Imports\GeoCityImports;
use App\Domain\Organisation\Imports\GeoDistrictImports;
use App\Domain\Organisation\Imports\GeoStateImports;
use App\Domain\Organisation\Imports\GradeImports;
use App\Domain\Organisation\Imports\LevelImports;
use App\Domain\Organisation\Imports\LocationImports;
use App\Domain\Organisation\Imports\RegionImports;
use App\Domain\Organisation\Imports\SubBranchImports;
use App\Domain\Organisation\Imports\SubDepartmentImports;
use App\Domain\Partners\Imports\VendorImports;
use App\Domain\Payroll\Imports\SalaryArrearImports;
use App\Domain\Payroll\Imports\VariablePayImports;
use App\Domain\People\Imports\EmployeeSkillImports;
use App\Domain\People\Imports\ExperienceImports;
use App\Domain\People\Imports\FamilyMemberImports;
use App\Domain\People\Imports\InsuranceImports;
use App\Domain\People\Imports\NominationImports;
use App\Domain\People\Imports\QualificationImports;
use App\Domain\People\Imports\SkillImports;
use App\Domain\Performance\Imports\GoalBankImports;
use App\Domain\Performance\Imports\GoalImports;
use App\Domain\Performance\Imports\ImprovementPlanImports;
use App\Domain\Performance\Imports\InitiativeImports;
use App\Domain\Performance\Imports\OkrFinalImports;
use App\Domain\Performance\Imports\OkrTotalImports;
use App\Domain\Performance\Imports\RatingNormalisationImports;
use App\Domain\Performance\Imports\ReviewNormalisationImports;
use App\Domain\Performance\Imports\ReviewRatingImports;
use App\Domain\Projects\Imports\CustomerImports;
use App\Domain\Projects\Imports\ProjectImports;
use App\Domain\Recognition\Imports\BadgeAwardImports;
use App\Domain\Recognition\Imports\RewardPointImports;

/**
 * The one-row-one-change uploads of the HROne parity programme (RowImports subclasses): their service and the
 * module permission an uploader needs besides employee.import, and the upload screen that lists them. Read by the
 * import policy, ImportActions and the Time office / Payroll upload screens.
 */
final class RowImportTypes
{
    /**
     * type => [service class, label, module permission, area]. The area is the Data import head the upload sits under
     * (ImportTypes::MODULES) and picks the screen its uploads are worked on: time_office, payroll and workforce have
     * their own upload screens; core, performance, engage, asset, projects and integration share Data uploads.
     */
    public const TYPES = [
        // HROne parity TX-2: the narrower keys (leave.adjust, attendance.roster / override) are also granted by leave.manage /
        // attendance.manage, so every uploader keeps these uploads.
        LeaveBalanceImports::TYPE => [LeaveBalanceImports::class, 'Leave balance upload', 'leave.adjust', 'time_office'],
        LeaveHistoryImports::TYPE => [LeaveHistoryImports::class, 'Leave history upload', 'leave.manage', 'time_office'],
        ShiftRosterImports::TYPE => [ShiftRosterImports::class, 'Shift roster upload', 'attendance.roster', 'time_office'],
        GeoLocationImports::TYPE => [GeoLocationImports::class, 'Geo location upload', 'attendance.manage', 'time_office'],
        OvertimeImports::TYPE => [OvertimeImports::class, 'Overtime upload', 'attendance.manage', 'time_office'],
        DayOverrideImports::TYPE => [DayOverrideImports::class, 'Day override upload', 'attendance.override', 'time_office'],
        // HROne parity HP-3 (PY-30).
        PerquisiteImports::TYPE => [PerquisiteImports::class, 'Perquisite upload', 'payroll.manage', 'payroll'],
        OutsideIncomeImports::TYPE => [OutsideIncomeImports::class, 'Income outside payroll upload', 'payroll.manage', 'payroll'],
        StatutoryOverrideImports::TYPE => [StatutoryOverrideImports::class, 'Statutory override upload', 'payroll.override', 'payroll'],
        PfOptionImports::TYPE => [PfOptionImports::class, 'PF options (VPF) upload', 'payroll.manage', 'payroll'],
        // HROne parity MS-5: health insurance policies and insured members (sensitive employee data; Workforce uploads screen).
        InsuranceImports::TYPE => [InsuranceImports::class, 'Health insurance upload', 'employee.sensitive.update', 'workforce'],
        // Data import DI-1: Core masters, Project management and Integration (JV mappings).
        LocationImports::TYPE => [LocationImports::class, 'Branches upload', 'organisation.create', 'core'],
        SubBranchImports::TYPE => [SubBranchImports::class, 'Sub branches upload', 'organisation.create', 'core'],
        RegionImports::TYPE => [RegionImports::class, 'Regions upload', 'organisation.create', 'core'],
        BusinessRegionImports::TYPE => [BusinessRegionImports::class, 'Business regions upload', 'organisation.create', 'core'],
        BusinessUnitImports::TYPE => [BusinessUnitImports::class, 'Business units upload', 'organisation.create', 'core'],
        DepartmentImports::TYPE => [DepartmentImports::class, 'Departments upload', 'organisation.create', 'core'],
        SubDepartmentImports::TYPE => [SubDepartmentImports::class, 'Sub departments upload', 'organisation.create', 'core'],
        // Designations, grades and levels are people setup (their screens need people_setup.*).
        DesignationImports::TYPE => [DesignationImports::class, 'Designations upload', 'people_setup.create', 'core'],
        GradeImports::TYPE => [GradeImports::class, 'Grades upload', 'people_setup.create', 'core'],
        LevelImports::TYPE => [LevelImports::class, 'Levels upload', 'people_setup.create', 'core'],
        SkillImports::TYPE => [SkillImports::class, 'Skill master upload', 'skills.manage', 'core'],
        // The skills a designation needs are its role requirements (CareerArchitecture checks career.manage).
        DesignationSkillImports::TYPE => [DesignationSkillImports::class, 'Designation wise skills upload', 'career.manage', 'core'],
        GeoStateImports::TYPE => [GeoStateImports::class, 'States upload', 'organisation.create', 'core'],
        GeoDistrictImports::TYPE => [GeoDistrictImports::class, 'Districts upload', 'organisation.create', 'core'],
        GeoCityImports::TYPE => [GeoCityImports::class, 'Cities upload', 'organisation.create', 'core'],
        DimensionImports::TYPE => [DimensionImports::class, 'Dimension upload', 'organisation.create', 'core'],
        CustomerImports::TYPE => [CustomerImports::class, 'Customers upload', 'project.manage', 'core'],
        ProjectImports::TYPE => [ProjectImports::class, 'Projects upload', 'project.manage', 'projects'],
        JvCodeImports::TYPE => [JvCodeImports::class, 'JV mapping upload', 'integration.manage', 'integration'],
        // Data import DI-2: Workforce. Profile data needs employee.update (adding a family member, qualification, job or skill
        // also needs employee.create: each action checks its own right per row); dated job changes employee.position; shortcuts
        // change sign-in preferences (user.update). Employee settings check each setting's own right per row.
        FamilyMemberImports::TYPE => [FamilyMemberImports::class, 'Family members upload', 'employee.update', 'workforce'],
        QualificationImports::TYPE => [QualificationImports::class, 'Education upload', 'employee.update', 'workforce'],
        ExperienceImports::TYPE => [ExperienceImports::class, 'Work experience upload', 'employee.update', 'workforce'],
        NominationImports::TYPE => [NominationImports::class, 'Nominee details upload', 'employee.update', 'workforce'],
        EmployeeSkillImports::TYPE => [EmployeeSkillImports::class, 'Employee skills upload', 'employee.update', 'workforce'],
        CustomFieldValueImports::TYPE => [CustomFieldValueImports::class, 'Employee other details upload', 'employee.update', 'workforce'],
        PositionChangeImports::TYPE => [PositionChangeImports::class, 'Employee (advance) upload', PositionChangeImports::PERMISSION, 'workforce'],
        EmployeeCodeImports::TYPE => [EmployeeCodeImports::class, 'Employee code upload', 'employee.update', 'workforce'],
        EmployeeSettingImports::TYPE => [EmployeeSettingImports::class, 'Employee settings upload', 'employee.update', 'workforce'],
        ShortcutImports::TYPE => [ShortcutImports::class, 'Shortcut upload', ShortcutImports::PERMISSION, 'workforce'],
        // Data import (Workforce → Vendor, Contract): after WO-15 (Partners) — vendors through Partners\Services\Vendors,
        // contracts of contract staff through EmployeeContracts.
        VendorImports::TYPE => [VendorImports::class, 'Vendor upload', 'partner.manage', 'workforce'],
        EmployeeContractImports::TYPE => [EmployeeContractImports::class, 'Contract upload', 'employee.update', 'workforce'],
        // Data import DI-3: Time office.
        CompOffImports::TYPE => [CompOffImports::class, 'Comp-off upload', 'leave.adjust', 'time_office'],
        AttendanceArrearImports::TYPE => [AttendanceArrearImports::class, 'Arrear days upload', 'attendance.arrears', 'time_office'],
        PaidDayAdjustmentImports::TYPE => [PaidDayAdjustmentImports::class, 'Paid days upload', 'attendance.override', 'time_office'],
        OvertimeArrearImports::TYPE => [OvertimeArrearImports::class, 'Previous month overtime upload', 'attendance.manage', 'time_office'],
        // Data import DI-4: Payroll (previous employment sits under Workforce). Money only through the owning services; the two
        // compensation uploads prepare compensation cycles (compensation.cycles here, compensation.propose checked by the upload).
        TaxCreditImports::TYPE => [TaxCreditImports::class, '12BAA declaration upload', 'payroll.manage', 'payroll'],
        PreviousEmploymentImports::TYPE => [PreviousEmploymentImports::class, 'Previous employment upload', 'payroll.manage', 'workforce'],
        PreviousSalaryImports::TYPE => [PreviousSalaryImports::class, 'Previous month salary upload', 'payroll.manage', 'payroll'],
        InvestmentImports::TYPE => [InvestmentImports::class, 'Investment declaration upload', 'payroll.manage', 'payroll'],
        HraDeclarationImports::TYPE => [HraDeclarationImports::class, 'HRA declaration upload', 'payroll.manage', 'payroll'],
        EmployeeStatutoryImports::TYPE => [EmployeeStatutoryImports::class, 'Employee statutory upload', 'employee.sensitive.update', 'payroll'],
        LoanImports::TYPE => [LoanImports::class, 'Loan upload', 'payroll.manage', 'payroll'],
        VariablePayImports::TYPE => [VariablePayImports::class, 'Variable pay upload', 'payroll.manage', 'payroll'],
        SalaryArrearImports::TYPE => [SalaryArrearImports::class, 'Salary arrear upload', 'payroll.manage', 'payroll'],
        ReimbursementClaimImports::TYPE => [ReimbursementClaimImports::class, 'Reimbursement claim upload', 'payroll.manage', 'payroll'],
        ReimbursementOpeningImports::TYPE => [ReimbursementOpeningImports::class, 'Reimbursement opening balance upload', 'payroll.manage', 'payroll'],
        ReimbursementBillImports::TYPE => [ReimbursementBillImports::class, 'Reimbursement bill upload', 'payroll.manage', 'payroll'],
        SettlementImports::TYPE => [SettlementImports::class, 'Settlement upload', 'exit.settle', 'payroll'],
        PayGroupChangeImports::TYPE => [PayGroupChangeImports::class, 'Change pay group upload', 'compensation.cycles', 'payroll'],
        SalaryComponentImports::TYPE => [SalaryComponentImports::class, 'Salary structure (component amounts) upload', 'compensation.cycles', 'payroll'],
        // Data import DI-5: Performance, Engage and Asset.
        // Event attendance (after GO-15 company events): marks through CompanyEvents::markAttendance.
        EventAttendanceImports::TYPE => [EventAttendanceImports::class, 'Event attendance upload', 'engagement.events', 'engage'],
        GoalBankImports::TYPE => [GoalBankImports::class, 'Goal bank upload', 'performance.manage', 'performance'],
        GoalImports::TYPE => [GoalImports::class, 'Goal upload', 'performance.manage', 'performance'],
        InitiativeImports::TYPE => [InitiativeImports::class, 'Initiative upload', 'performance.initiatives_manage', 'performance'],
        ImprovementPlanImports::TYPE => [ImprovementPlanImports::class, 'Improvement plan upload', 'performance.pip', 'performance'],
        ReviewRatingImports::TYPE => [ReviewRatingImports::class, 'Review rating upload', 'performance.manage', 'performance'],
        RatingNormalisationImports::TYPE => [RatingNormalisationImports::class, 'Rating normalisation upload', 'performance.calibrate', 'performance'],
        ReviewNormalisationImports::TYPE => [ReviewNormalisationImports::class, 'Review normalisation upload', 'performance.calibrate', 'performance'],
        OkrFinalImports::TYPE => [OkrFinalImports::class, 'OKR final achievement upload', 'performance.manage', 'performance'],
        OkrTotalImports::TYPE => [OkrTotalImports::class, 'OKR total achievement upload', 'performance.manage', 'performance'],
        BadgeAwardImports::TYPE => [BadgeAwardImports::class, 'Badge allocation upload', 'recognition.manage', 'engage'],
        RewardPointImports::TYPE => [RewardPointImports::class, 'Reward points upload', 'recognition.points', 'engage'],
        AssetTypeImports::TYPE => [AssetTypeImports::class, 'Asset type upload', 'asset.manage', 'asset'],
        AssetSubtypeImports::TYPE => [AssetSubtypeImports::class, 'Asset sub type upload', 'asset.manage', 'asset'],
        AssetProductImports::TYPE => [AssetProductImports::class, 'Asset product upload', 'asset.manage', 'asset'],
        AssetImports::TYPE => [AssetImports::class, 'Asset register upload', 'asset.manage', 'asset'],
    ];

    /**
     * Data import DI-4: uploads whose rows hold pay (they prepare compensation cycles), so — like salary uploads — only their
     * uploader (and, under maker-checker, the checker) sees them (EmployeeImportPolicy, Recent uploads, the upload screens).
     */
    public const UPLOADER_ONLY = [PayGroupChangeImports::TYPE, SalaryComponentImports::TYPE];

    public static function uploaderOnly(string $type): bool
    {
        return in_array($type, self::UPLOADER_ONLY, true);
    }

    public static function has(string $type): bool
    {
        return isset(self::TYPES[$type]);
    }

    public static function allowed(?User $user, string $type): bool
    {
        return $user !== null && isset(self::TYPES[$type]) && $user->hasPermission('employee.import') && $user->hasPermission(self::TYPES[$type][2]);
    }

    /** @return list<string> the types this user may upload (on one upload screen, or all) */
    public static function allowedTypes(?User $user, ?string $area = null): array
    {
        return array_values(array_filter(array_keys(self::TYPES), fn (string $type) => ($area === null || self::TYPES[$type][3] === $area) && self::allowed($user, $type)));
    }
}
