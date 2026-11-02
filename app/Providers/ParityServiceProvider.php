<?php

namespace App\Providers;

use App\Domain\Assets\Listeners\AssetRequestTriggers;
use App\Domain\Assets\Listeners\AssetRequestWorkflowBridge;
use App\Domain\Assets\Models\AssetRequest;
use App\Domain\Assets\Models\AssetSubtype;
use App\Domain\Assets\Policies\AssetPolicy;
use App\Domain\Assets\Policies\AssetRequestPolicy;
use App\Domain\Attendance\Contracts\AttendanceCycle;
use App\Domain\Attendance\Contracts\AttendancePeriodState;
use App\Domain\Attendance\Contracts\LeaveOnDay;
use App\Domain\Attendance\Contracts\PaidDayAdjustmentReader;
use App\Domain\Attendance\Contracts\PendingLeaveReader;
use App\Domain\Attendance\Events\AttendanceEvent;
use App\Domain\Attendance\Listeners\CompOffGenerator;
use App\Domain\Attendance\Listeners\RegularisationWorkflowBridge;
use App\Domain\Attendance\Listeners\TimeOfficeWorkflowBridge;
use App\Domain\Attendance\Models\AttendanceArrear;
use App\Domain\Attendance\Models\AttendanceOverride;
use App\Domain\Attendance\Models\AttendancePenalty;
use App\Domain\Attendance\Models\AttendanceRecord;
use App\Domain\Attendance\Models\EmployeeDevice;
use App\Domain\Attendance\Models\GeoFence;
use App\Domain\Attendance\Models\LocationRequest;
use App\Domain\Attendance\Models\ShiftOverride;
use App\Domain\Attendance\Models\TimeOfficeRequest;
use App\Domain\Attendance\Policies\AttendanceConfigPolicy;
use App\Domain\Attendance\Policies\AttendanceRecordPolicy;
use App\Domain\Attendance\Services\AttendanceMonthCloses;
use App\Domain\Attendance\Services\AttendanceProcessor;
use App\Domain\Attendance\Services\LatePenalties;
use App\Domain\Attendance\Services\PaidDayAdjustments;
use App\Domain\Attendance\Support\RequestRules;
use App\Domain\Claims\Models\ReimbursementBill;
use App\Domain\Claims\Models\ReimbursementClaim;
use App\Domain\Claims\Models\ReimbursementLedgerEntry;
use App\Domain\Claims\Services\ReimbursementClaims;
use App\Domain\Communication\Events\CommunicationEvent;
use App\Domain\Compliance\Listeners\TaxDeclarationWorkflowBridge;
use App\Domain\Compliance\Models\EmployeePfOption;
use App\Domain\Compliance\Models\StatutoryChallan;
use App\Domain\Compliance\Models\StatutoryChallanAllocation;
use App\Domain\Compliance\Models\StatutoryOverride;
use App\Domain\Compliance\Models\TaxIncomeEntry;
use App\Domain\Compliance\Policies\StatutoryOverridePolicy;
use App\Domain\Compliance\Policies\StatutoryReturnPolicy;
use App\Domain\Configuration\Models\CodeSeries;
use App\Domain\Configuration\Models\OptionList;
use App\Domain\Configuration\Services\PolicyResolver;
use App\Domain\Documents\Models\DocumentShareBatch;
use App\Domain\Documents\Models\DocumentShareItem;
use App\Domain\Documents\Policies\DocumentSharePolicy;
use App\Domain\Employment\Events\EmploymentEvent;
use App\Domain\Employment\Listeners\ImportWorkflowBridge;
use App\Domain\Employment\Models\BankAccountRule;
use App\Domain\Employment\Models\EmployeeContract;
use App\Domain\Employment\Models\TransferRequest;
use App\Domain\Employment\Models\WorkforceReason;
use App\Domain\Employment\Policies\ClearanceChecklistPolicy;
use App\Domain\Employment\Policies\EmployeeContractPolicy;
use App\Domain\Employment\Policies\TransferRequestPolicy;
use App\Domain\Employment\Policies\WorkforceReasonPolicy;
use App\Domain\Engagement\Contracts\PledgePayroll;
use App\Domain\Engagement\Events\EngagementEvent;
use App\Domain\Engagement\Models\CompanyEvent;
use App\Domain\Engagement\Models\CompanyEventAttendance;
use App\Domain\Engagement\Models\CompanyEventInvitation;
use App\Domain\Engagement\Models\CompanyEventSession;
use App\Domain\Engagement\Models\FundraiserCampaign;
use App\Domain\Engagement\Models\FundraiserPledge;
use App\Domain\Engagement\Models\MissionStatement;
use App\Domain\Engagement\Models\MoodReason;
use App\Domain\Engagement\Policies\CompanyEventPolicy;
use App\Domain\Engagement\Policies\CompanyEventRecordPolicy;
use App\Domain\Engagement\Policies\FundraiserPolicy;
use App\Domain\Engagement\Policies\MissionStatementPolicy;
use App\Domain\Engagement\Policies\MoodReasonPolicy;
use App\Domain\Engagement\Services\Fundraisers;
use App\Domain\Exit\Events\ExitEvent;
use App\Domain\Exit\Listeners\ExitWithdrawalWorkflowBridge;
use App\Domain\Exit\Listeners\ResignationWorkflowBridge;
use App\Domain\Exit\Models\ClearanceChecklist;
use App\Domain\Exit\Models\ExitWithdrawalRequest;
use App\Domain\Exit\Policies\ExitCasePolicy;
use App\Domain\Exit\Settlement\NoticeRecoverySettlementSource;
use App\Domain\Expense\Contracts\ExpenseUsage;
use App\Domain\Expense\Contracts\PayrollCommitments;
use App\Domain\Expense\Contracts\PayrollMonths;
use App\Domain\Expense\Listeners\ExpenseWorkflowBridge;
use App\Domain\Expense\Models\ExpenseAdvance;
use App\Domain\Expense\Models\ExpenseAdvanceEntry;
use App\Domain\Expense\Models\ExpenseCategory;
use App\Domain\Expense\Models\ExpenseCategoryLimit;
use App\Domain\Expense\Models\ExpenseGroup;
use App\Domain\Expense\Models\ExpenseLocationGroup;
use App\Domain\Expense\Models\ExpenseLocationGroupPlace;
use App\Domain\Expense\Models\ExpenseReceipt;
use App\Domain\Expense\Models\ExpenseReport;
use App\Domain\Expense\Models\ExpenseReportPart;
use App\Domain\Expense\Models\ExpenseTaxType;
use App\Domain\Expense\Models\TravelRequest;
use App\Domain\Expense\Models\TravelRequestLeg;
use App\Domain\Expense\Models\TravelRequestPart;
use App\Domain\Expense\Policies\ExpenseConfigPolicy;
use App\Domain\Expense\Policies\ExpensePolicy;
use App\Domain\Expense\Policies\TravelRequestPolicy;
use App\Domain\Expense\Settlement\ExpenseSettlementSource;
use App\Domain\Expense\Support\ReceiptUsage;
use App\Domain\Experience\BulkOperations\AddToCycleOperation;
use App\Domain\Experience\BulkOperations\AdjustPaidDaysOperation;
use App\Domain\Experience\BulkOperations\AdvanceRecoveryPayrollOperation;
use App\Domain\Experience\BulkOperations\AdvanceRecoveryRefundOperation;
use App\Domain\Experience\BulkOperations\ApplyLeaveOperation;
use App\Domain\Experience\BulkOperations\AssignSalaryOperation;
use App\Domain\Experience\BulkOperations\AssignShiftOperation;
use App\Domain\Experience\BulkOperations\CancelClaimOperation;
use App\Domain\Experience\BulkOperations\CancelClearanceOperation;
use App\Domain\Experience\BulkOperations\CancelLeaveOperation;
use App\Domain\Experience\BulkOperations\CarryForwardLeaveOperation;
use App\Domain\Experience\BulkOperations\ChangeAttendanceStatusOperation;
use App\Domain\Experience\BulkOperations\ChangeHolidayOperation;
use App\Domain\Experience\BulkOperations\ChangeManagerOperation;
use App\Domain\Experience\BulkOperations\CompleteExitOperation;
use App\Domain\Experience\BulkOperations\ConfirmReviewOperation;
use App\Domain\Experience\BulkOperations\EmployeeCompleteExitOperation;
use App\Domain\Experience\BulkOperations\EmployeeStartClearanceOperation;
use App\Domain\Experience\BulkOperations\GenerateLettersOperation;
use App\Domain\Experience\BulkOperations\HoldArrearOperation;
use App\Domain\Experience\BulkOperations\InitiateBgvOperation;
use App\Domain\Experience\BulkOperations\InitiateExitOperation;
use App\Domain\Experience\BulkOperations\InviteUsersOperation;
use App\Domain\Experience\BulkOperations\MarkPresentOperation;
use App\Domain\Experience\BulkOperations\NormaliseRatingsOperation;
use App\Domain\Experience\BulkOperations\NormaliseReviewRatingsOperation;
use App\Domain\Experience\BulkOperations\PublishLettersOperation;
use App\Domain\Experience\BulkOperations\ReleaseArrearOperation;
use App\Domain\Experience\BulkOperations\RemindReviewersOperation;
use App\Domain\Experience\BulkOperations\RemoveShiftOperation;
use App\Domain\Experience\BulkOperations\ReopenReviewsOperation;
use App\Domain\Experience\BulkOperations\SendEmailOperation;
use App\Domain\Experience\BulkOperations\SendResetLinksOperation;
use App\Domain\Experience\BulkOperations\StartClearanceOperation;
use App\Domain\Experience\BulkOperations\StartOnboardingOperation;
use App\Domain\Experience\BulkOperations\StartStageClearanceOperation;
use App\Domain\Experience\BulkOperations\UserResendInvitationsOperation;
use App\Domain\Experience\BulkOperations\UserResetLinksOperation;
use App\Domain\Experience\BulkOperations\WithdrawFromCycleOperation;
use App\Domain\Experience\Models\BulkRun;
use App\Domain\Experience\Models\BulkRunItem;
use App\Domain\Experience\MySpaceItems\PageItem;
use App\Domain\Experience\MySpaceItems\SelfPages;
use App\Domain\Experience\Policies\BulkRunPolicy;
use App\Domain\Experience\RequestTypes\AssetRequestType;
use App\Domain\Experience\RequestTypes\CompOffRequestType;
use App\Domain\Experience\RequestTypes\ExpenseAdvanceRequestType;
use App\Domain\Experience\RequestTypes\ExpenseReceiptRequestType;
use App\Domain\Experience\RequestTypes\ExpenseReportRequestType;
use App\Domain\Experience\RequestTypes\FormSubmissionRequestType;
use App\Domain\Experience\RequestTypes\GoalChangeRequestType;
use App\Domain\Experience\RequestTypes\HelpdeskTicketRequestType;
use App\Domain\Experience\RequestTypes\HrServiceRequestType;
use App\Domain\Experience\RequestTypes\InvestmentDeclarationRequestType;
use App\Domain\Experience\RequestTypes\InvestmentProofRequestType;
use App\Domain\Experience\RequestTypes\LeaveEncashmentRequestType;
use App\Domain\Experience\RequestTypes\LeaveRequestType;
use App\Domain\Experience\RequestTypes\LoanRequestType;
use App\Domain\Experience\RequestTypes\LocationRequestType;
use App\Domain\Experience\RequestTypes\OnDutyRequestType;
use App\Domain\Experience\RequestTypes\OvertimeRequestType;
use App\Domain\Experience\RequestTypes\ProfileChangeRequestType;
use App\Domain\Experience\RequestTypes\RegularisationRequestType;
use App\Domain\Experience\RequestTypes\ReimbursementClaimRequestType;
use App\Domain\Experience\RequestTypes\ResignationRequestType;
use App\Domain\Experience\RequestTypes\ResignationWithdrawalRequestType;
use App\Domain\Experience\RequestTypes\RestrictedHolidayRequestType;
use App\Domain\Experience\RequestTypes\ShiftChangeRequestType;
use App\Domain\Experience\RequestTypes\ShortLeaveRequestType;
use App\Domain\Experience\RequestTypes\TravelRequestType;
use App\Domain\Experience\RequestTypes\VariablePayRequestType;
use App\Domain\Experience\RequestTypes\WeekOffChangeRequestType;
use App\Domain\Experience\Services\ActionCatalogue;
use App\Domain\Experience\Services\BulkOperations;
use App\Domain\Experience\Services\MySpace;
use App\Domain\Experience\Services\RequestApprovers;
use App\Domain\Experience\Services\RequestPreview;
use App\Domain\Experience\Services\RequestRegistry;
use App\Domain\Experience\Services\UnifiedInbox;
use App\Domain\Experience\Sources\AssetRequestApprovalSource;
use App\Domain\Experience\Sources\ExitApprovalSource;
use App\Domain\Experience\Sources\ExpenseApprovalSource;
use App\Domain\Experience\Sources\Feedback360ApprovalSource;
use App\Domain\Experience\Sources\LeaveEncashmentBatchApprovalSource;
use App\Domain\Experience\Sources\PayrollRequestsApprovalSource;
use App\Domain\Experience\Sources\PerformanceApprovalSource;
use App\Domain\Experience\Sources\ProfileChangeApprovalSource;
use App\Domain\Experience\Sources\TimeOfficeApprovalSource;
use App\Domain\Experience\Sources\TransferApprovalSource;
use App\Domain\Feedback\Models\FeedbackRequest;
use App\Domain\Feedback\Models\FeedbackTemplate;
use App\Domain\Feedback\Models\QuestionBank;
use App\Domain\Feedback\Models\QuestionBankQuestion;
use App\Domain\Feedback\Policies\FeedbackConfigPolicy;
use App\Domain\Feedback\Policies\FeedbackRequestPolicy;
use App\Domain\Identity\Listeners\DisableLoginOnStatus;
use App\Domain\Identity\Listeners\QueueRoleMembershipSync;
use App\Domain\Identity\Models\AccessScopeGroup;
use App\Domain\Identity\Policies\AccessScopeGroupPolicy;
use App\Domain\Identity\Services\AccessScopes;
use App\Domain\Integration\Models\JvCode;
use App\Domain\Integration\Policies\JvCodePolicy;
use App\Domain\Learning\Events\LearningEvent;
use App\Domain\Leave\Contracts\EncashmentsBeingPaid;
use App\Domain\Leave\Listeners\CompOffClaimBridge;
use App\Domain\Leave\Listeners\LeaveExtrasWorkflowBridge;
use App\Domain\Leave\Listeners\ShortLeaveBalanceBridge;
use App\Domain\Leave\Models\LeaveDonation;
use App\Domain\Leave\Models\LeaveEncashmentBatch;
use App\Domain\Leave\Policies\LeaveEncashmentBatchPolicy;
use App\Domain\Leave\Policies\LeaveRequestPolicy;
use App\Domain\Leave\Services\AttendanceLeaveOnDay;
use App\Domain\Leave\Services\AttendancePendingLeaveReader;
use App\Domain\Letters\Contracts\LetterSignatureProvider;
use App\Domain\Letters\Listeners\LetterWorkflowBridge;
use App\Domain\Letters\Models\LetterSchedule;
use App\Domain\Letters\Models\LetterScheduleItem;
use App\Domain\Letters\Policies\LetterSchedulePolicy;
use App\Domain\Letters\Support\NoSignatureProvider;
use App\Domain\Lifecycle\Events\EmployeeLifecycleChanged;
use App\Domain\Lifecycle\Events\EmployeeReminderDue;
use App\Domain\Lifecycle\Models\ConfirmationReview;
use App\Domain\Lifecycle\Policies\ConfirmationReviewPolicy;
use App\Domain\Loans\Models\Loan;
use App\Domain\Loans\Models\LoanGuarantor;
use App\Domain\Loans\Models\LoanInstallment;
use App\Domain\Loans\Policies\LoanPolicy;
use App\Domain\Loans\Settlement\LoanSettlementSource;
use App\Domain\Organisation\Models\BusinessRegion;
use App\Domain\Organisation\Models\DimensionValue;
use App\Domain\Organisation\Models\GeoPlace;
use App\Domain\Organisation\Models\OrganisationContact;
use App\Domain\Organisation\Models\Region;
use App\Domain\Organisation\Policies\OrganisationContactPolicy;
use App\Domain\Organisation\Policies\OrganisationStructurePolicy;
use App\Domain\Organisation\Policies\PeopleSetupPolicy;
use App\Domain\Partners\Models\BusinessPartner;
use App\Domain\Partners\Models\Vendor;
use App\Domain\Partners\Policies\PartnerPolicy;
use App\Domain\Payroll\Contracts\PaidPayReader;
use App\Domain\Payroll\Contracts\PayrollSummaryReader;
use App\Domain\Payroll\Contracts\SalaryHoldReader;
use App\Domain\Payroll\Events\PayrollEvent;
use App\Domain\Payroll\Inputs\AttendanceArrearInput;
use App\Domain\Payroll\Inputs\BonusInput;
use App\Domain\Payroll\Inputs\ExpenseAdvancePayoutInput;
use App\Domain\Payroll\Inputs\ExpenseAdvanceRecoveryInput;
use App\Domain\Payroll\Inputs\ExpensePayrollMonths;
use App\Domain\Payroll\Inputs\ExpenseReportInput;
use App\Domain\Payroll\Inputs\FundraiserDeductionInput;
use App\Domain\Payroll\Inputs\FundraiserPayrollMonths;
use App\Domain\Payroll\Inputs\LeaveEncashmentInput;
use App\Domain\Payroll\Inputs\LoanDisbursementInput;
use App\Domain\Payroll\Inputs\LoanRecoveryInput;
use App\Domain\Payroll\Inputs\PayrollInputs;
use App\Domain\Payroll\Inputs\PendingEncashmentPayments;
use App\Domain\Payroll\Inputs\PendingPayrollInputs;
use App\Domain\Payroll\Inputs\ReimbursementClaimInput;
use App\Domain\Payroll\Inputs\RewardPointsInput;
use App\Domain\Payroll\Inputs\ShiftAllowanceInput;
use App\Domain\Payroll\Inputs\TransferAllowanceInput;
use App\Domain\Payroll\Inputs\VariablePayInput;
use App\Domain\Payroll\Listeners\PayrollRequestWorkflowBridge;
use App\Domain\Payroll\Models\BankFileLayout;
use App\Domain\Payroll\Models\CompanyBankAccount;
use App\Domain\Payroll\Models\EmployeePaymentMode;
use App\Domain\Payroll\Models\SalaryHold;
use App\Domain\Payroll\Models\VariablePayPlan;
use App\Domain\Payroll\Models\VariablePayRequest;
use App\Domain\Payroll\Policies\PayrollSetupPolicy;
use App\Domain\Payroll\Policies\VariablePayPlanPolicy;
use App\Domain\Payroll\Services\AttendanceMonthCalendar;
use App\Domain\Payroll\Services\OpenSalaryHolds;
use App\Domain\Payroll\Services\PaidPay;
use App\Domain\Payroll\Services\PayrollSummary;
use App\Domain\People\Listeners\ProfileChangeWorkflowBridge;
use App\Domain\People\Models\InsurancePolicy;
use App\Domain\People\Models\InsuredMember;
use App\Domain\People\Models\ProfileChangeRequest;
use App\Domain\People\Models\Qualification;
use App\Domain\People\Models\QualificationSpecialisation;
use App\Domain\People\Policies\InsurancePolicyPolicy;
use App\Domain\People\Policies\ProfileChangeRequestPolicy;
use App\Domain\Performance\Events\PerformanceEvent;
use App\Domain\Performance\Listeners\PerformanceWorkflowBridge;
use App\Domain\Performance\Models\Appraisal;
use App\Domain\Performance\Models\FeedbackNomination;
use App\Domain\Performance\Models\FeedbackParticipant;
use App\Domain\Performance\Models\FeedbackResponse;
use App\Domain\Performance\Models\FeedbackRound;
use App\Domain\Performance\Models\Goal;
use App\Domain\Performance\Models\GoalPlan;
use App\Domain\Performance\Models\GoalTemplate;
use App\Domain\Performance\Models\GoalTemplateItem;
use App\Domain\Performance\Models\Initiative;
use App\Domain\Performance\Models\KpiRatingFormula;
use App\Domain\Performance\Models\OneOnOne;
use App\Domain\Performance\Models\OneOnOneFeedback;
use App\Domain\Performance\Models\PerformanceDistributionTarget;
use App\Domain\Performance\Models\PerformanceLookup;
use App\Domain\Performance\Models\PerformanceNineBoxBand;
use App\Domain\Performance\Models\PerformancePeriodReview;
use App\Domain\Performance\Models\PerformanceUnit;
use App\Domain\Performance\Policies\AppraisalPolicy;
use App\Domain\Performance\Policies\EmployeeOwnedPolicy;
use App\Domain\Performance\Policies\Feedback360Policy;
use App\Domain\Performance\Policies\GoalPolicy;
use App\Domain\Performance\Policies\InitiativePolicy;
use App\Domain\Performance\Policies\OneOnOnePolicy;
use App\Domain\Performance\Policies\PerformanceConfigPolicy;
use App\Domain\Performance\Services\PerformancePolicyRules;
use App\Domain\Platform\Services\SettingsRepository;
use App\Domain\ProductFeedback\Models\ProductIdea;
use App\Domain\ProductFeedback\Policies\ProductIdeaPolicy;
use App\Domain\Projects\Models\Customer;
use App\Domain\Projects\Models\Project;
use App\Domain\Projects\Models\ProjectMember;
use App\Domain\Projects\Policies\ProjectPolicy;
use App\Domain\Recognition\Events\RecognitionEvent;
use App\Domain\Recognition\Models\Badge;
use App\Domain\Recognition\Models\Recognition;
use App\Domain\Recognition\Models\RewardPointEntry;
use App\Domain\Recognition\Models\WallComment;
use App\Domain\Recognition\Models\WallPost;
use App\Domain\Recognition\Policies\BadgePolicy;
use App\Domain\Recognition\Policies\RecognitionPolicy;
use App\Domain\Recognition\Policies\RewardPointEntryPolicy;
use App\Domain\Recognition\Policies\WallPostPolicy;
use App\Domain\Recognition\Services\RewardPoints;
use App\Domain\Recognition\Services\SocialWall;
use App\Domain\ServiceDesk\Models\ServiceResolverRule;
use App\Domain\ServiceDesk\Policies\ServiceDeskConfigPolicy;
use App\Domain\Workflow\Events\WorkflowCompleted;
use App\Domain\Workflow\Listeners\CompensationWorkflowBridge;
use App\Domain\Workflow\Models\InboxArchive;
use App\Domain\Workflow\Models\TaskDelegation;
use App\Domain\Workflow\Policies\TaskDelegationPolicy;
use App\Domain\Workflow\Services\InboxArchives;
use App\Domain\Workflow\Services\RequestReturns;
use App\Domain\Workflow\Services\TaskComments;
use App\Domain\Workflow\Services\TaskDelegations;
use Illuminate\Foundation\Http\Events\RequestHandled;
use Illuminate\Queue\Events\JobProcessing;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;

/**
 * Wiring for the HROne parity programme (docs/hrone-parity): policies, listeners and Approval Center sources of
 * the capabilities added to close the gap register, kept apart from AppServiceProvider so each phase's additions
 * stay in one place.
 */
class ParityServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(PaidPayReader::class, PaidPay::class);
        // HROne parity TX-3: attendance months come from the pay cycle (Payroll answers Attendance's contract); payroll
        // reads whether a month is closed through AttendancePeriodState; Leave answers which leave still waits on its days.
        $this->app->bind(AttendanceCycle::class, AttendanceMonthCalendar::class);
        $this->app->bind(AttendancePeriodState::class, AttendanceMonthCloses::class);
        $this->app->bind(PendingLeaveReader::class, AttendancePendingLeaveReader::class);
        // HROne parity TX-4: payroll reads HR's attendance paid-day adjustments (quantities) through this contract.
        $this->app->bind(PaidDayAdjustmentReader::class, PaidDayAdjustments::class);
        // HROne parity TX-7: the day drawer reads a day's leave and cancels it through Leave's own cancellation.
        $this->app->bind(LeaveOnDay::class, AttendanceLeaveOnDay::class);
        // HROne parity CA-10: dashboard payroll figures (finalized payroll only, within the reader's access) for shared views.
        $this->app->bind(PayrollSummaryReader::class, PayrollSummary::class);
        $this->app->tag([TimeOfficeApprovalSource::class, PayrollRequestsApprovalSource::class, TransferApprovalSource::class, ExpenseApprovalSource::class,
            // HROne setup parity HP-8d / HP-8f: goal sheets, improvement plans and PIP outcomes waiting for approval.
            PerformanceApprovalSource::class,
            // HROne setup parity AS-B: asset requests waiting for approval.
            AssetRequestApprovalSource::class,
            // HROne setup parity HP-8h: 360 feedback nominations waiting for approval.
            Feedback360ApprovalSource::class,
            // HROne parity MS-3: self-service profile changes waiting for approval.
            ProfileChangeApprovalSource::class,
            // HROne parity WO-11: resignation withdrawals and acceptances (only where the organisation switched them on).
            ExitApprovalSource::class,
            // HROne parity PO-8: HR leave encashment batches waiting for a second person's approval.
            LeaveEncashmentBatchApprovalSource::class], 'peopleos.approval_sources');
        // HROne parity PO-8: a batch unprocess leaves alone the encashments a payroll run not yet finalized carries.
        $this->app->bind(EncashmentsBeingPaid::class, PendingEncashmentPayments::class);
        $this->app->tag([LeaveEncashmentInput::class, AttendanceArrearInput::class, LoanDisbursementInput::class, LoanRecoveryInput::class, VariablePayInput::class, ReimbursementClaimInput::class, ShiftAllowanceInput::class,
            // HROne setup parity EX-E: expense reports and advances paid, and advances recovered, through payroll.
            ExpenseReportInput::class, ExpenseAdvancePayoutInput::class, ExpenseAdvanceRecoveryInput::class,
            // HROne setup parity 08.6: transfer allowances granted on approved transfer requests, paid once.
            TransferAllowanceInput::class,
            // HROne setup parity HP-9d: reward points redeemed through payroll (none unless the organisation allows it).
            RewardPointsInput::class,
            // HROne parity PO-22: approved statutory bonus and ex gratia, paid once with the chosen payroll month.
            BonusInput::class,
            // HROne parity GO-18 (D-GO3): fundraiser pledges deducted once from salary (none unless the organisation allows it).
            FundraiserDeductionInput::class], 'peopleos.payroll_inputs');
        $this->app->tag([LoanSettlementSource::class, ExpenseSettlementSource::class,
            // HROne parity WO-11: notice shortfall on the exit policy's own rule (nothing unless the policy sets one).
            NoticeRecoverySettlementSource::class], 'peopleos.settlement_sources');
        // HROne parity WO-11: salary still on hold, for the exit case's relieving review (read only).
        $this->app->bind(SalaryHoldReader::class, OpenSalaryHolds::class);
        // HROne setup parity EX-B/EX-C: what employees have claimed, for expense limits — their expense receipts.
        $this->app->bind(ExpenseUsage::class, ReceiptUsage::class);
        // EX-E: which expense records a payroll run not yet finalized already pays or recovers (read from payroll entries).
        $this->app->bind(PayrollCommitments::class, PendingPayrollInputs::class);
        // HROne parity XO-4 (D-XO1): the payroll months a report may be paid in and the paying accounts (read from payroll).
        $this->app->bind(PayrollMonths::class, ExpensePayrollMonths::class);
        // HROne parity GO-18: the payroll months a fundraiser pledge may be deducted in, and the pledges a run being prepared
        // already deducts (read from payroll); the fundraisers' per-request answers (audiences, visible campaigns).
        $this->app->bind(PledgePayroll::class, FundraiserPayrollMonths::class);
        $this->app->scoped(Fundraisers::class);
        // HROne setup parity P3: send-backs and discussions keep their per-request memo (open returns, threads).
        $this->app->scoped(RequestReturns::class);
        $this->app->scoped(TaskComments::class);
        // HROne setup parity P4: running delegations and archived items, memoised per request.
        $this->app->scoped(TaskDelegations::class);
        $this->app->scoped(InboxArchives::class);
        // HROne setup parity HP-8i: the performance policy of each employee, resolved once per request (visibility is asked per row).
        $this->app->scoped(PerformancePolicyRules::class);
        // HROne parity WO-1: bulk actions registers can run (each forwards to the record's owning service).
        $this->app->singleton(BulkOperations::class);
        $this->app->tag([
            InitiateExitOperation::class,
            StartOnboardingOperation::class,
            InitiateBgvOperation::class,
            SendEmailOperation::class,
            ConfirmReviewOperation::class,
            StartClearanceOperation::class,
            CompleteExitOperation::class,
            // HROne parity GO-1: performance review requests (remind reviewers; reopen reviews while PER-1 / D-GO1 is switched on).
            RemindReviewersOperation::class,
            ReopenReviewsOperation::class,
            // HROne parity GO-2: add employees to a running performance cycle; withdraw appraisals from their cycle.
            AddToCycleOperation::class,
            WithdrawFromCycleOperation::class,
            // HROne parity GO-3: large normalise grids of the calibration board (performance ratings; periodic review ratings).
            NormaliseRatingsOperation::class,
            NormaliseReviewRatingsOperation::class,
            // HROne parity RQ-1: HR bulk cancel where the module already cancels (leave, reimbursement claims).
            CancelLeaveOperation::class,
            CancelClaimOperation::class,
            // HROne parity TX-3: Run attendance — mark absent days present, apply leave on them (TIM-5, off by default),
            // hold / release attendance arrears.
            MarkPresentOperation::class,
            ApplyLeaveOperation::class,
            HoldArrearOperation::class,
            ReleaseArrearOperation::class,
            // HROne parity TX-4: Paid days — adjust the paid days of the selected employees' month (TIM-2, off by default).
            AdjustPaidDaysOperation::class,
            // HROne parity TX-5: Roster board — assign a shift (weekly-off weekdays, automatic shift correction) or remove the
            // rostered days of a range for large selections.
            AssignShiftOperation::class,
            RemoveShiftOperation::class,
            // HROne parity TX-6: Change attendance status — a date range, the whole day or each half, for large selections.
            ChangeAttendanceStatusOperation::class,
            // HROne parity TX-12: Change holiday — assign, remove or replace a holiday for large selections.
            ChangeHolidayOperation::class,
            // HROne parity TX-8a: Carry forward leaves — the year close of a leave year for large selections.
            CarryForwardLeaveOperation::class,
            // HROne parity WO-13: bulk letter generation (employees) and "Generate & publish" (letters not yet issued).
            GenerateLettersOperation::class,
            PublishLettersOperation::class,
            // HROne parity WO-11: start / cancel clearance stages (Clearances register; D-WO3: cancel, never delete) and start
            // clearance / complete exit from the Employees register.
            StartStageClearanceOperation::class,
            CancelClearanceOperation::class,
            EmployeeStartClearanceOperation::class,
            EmployeeCompleteExitOperation::class,
            // HROne parity WO-16: bulk change manager (with the moved people's open manager approvals, when asked), invite to
            // sign in / password reset links from the Employees register, new invitations / reset links from the Users list.
            ChangeManagerOperation::class,
            InviteUsersOperation::class,
            SendResetLinksOperation::class,
            UserResendInvitationsOperation::class,
            UserResetLinksOperation::class,
            // HROne parity PO-2: "Assign salary" for the employees selected on the salary structure register (proposals only).
            AssignSalaryOperation::class,
            // HROne parity XO-6: Advance recovery desk — recover through payroll / record recovery outside payroll.
            AdvanceRecoveryPayrollOperation::class,
            AdvanceRecoveryRefundOperation::class,
        ], 'peopleos.bulk_operations');
        // HROne parity WO-13: the e-sign seam — no provider until SV-17, so e-signing is never offered.
        $this->app->bind(LetterSignatureProvider::class, NoSignatureProvider::class);
        // HROne parity RQ-1: the request registry (one adapter per module request; each reads and links, never writes).
        // Request-scoped: its answers ("what can this person raise now") are memoised for one request only.
        $this->app->scoped(RequestRegistry::class);
        // HROne parity AC-1: the Actions panel's catalogue (request-scoped like the registry it reads for on-behalf forms).
        $this->app->scoped(ActionCatalogue::class);
        $this->app->tag([
            LeaveRequestType::class, LeaveEncashmentRequestType::class,
            RegularisationRequestType::class, OnDutyRequestType::class, ShortLeaveRequestType::class, RestrictedHolidayRequestType::class,
            ShiftChangeRequestType::class, WeekOffChangeRequestType::class, OvertimeRequestType::class, CompOffRequestType::class, LocationRequestType::class,
            LoanRequestType::class, ReimbursementClaimRequestType::class, VariablePayRequestType::class,
            InvestmentDeclarationRequestType::class, InvestmentProofRequestType::class,
            ExpenseReceiptRequestType::class, ExpenseReportRequestType::class, ExpenseAdvanceRequestType::class, TravelRequestType::class,
            AssetRequestType::class,
            HelpdeskTicketRequestType::class, HrServiceRequestType::class,
            ResignationRequestType::class,
            GoalChangeRequestType::class,
            FormSubmissionRequestType::class,
            // HROne parity MS-3: self-service profile changes (My profile).
            ProfileChangeRequestType::class,
            // HROne parity WO-11: a request to withdraw one's resignation (only while withdrawals need approval).
            ResignationWithdrawalRequestType::class,
        ], 'peopleos.request_types');
        // HROne parity RQ-2: whom a raised request waits with and what the chrome previews are memoised for one request only.
        $this->app->scoped(RequestApprovers::class);
        $this->app->scoped(RequestPreview::class);
        // HROne parity RQ-8: the unified inbox's rows are memoised for one request only (like the Approval Center they read).
        $this->app->scoped(UnifiedInbox::class);
        // HROne parity MS-1: My space — one item per existing self page (each opened under the destination's own
        // canAccess()). Request-scoped like the request registry; other packages tag their own MySpaceItem classes.
        $this->app->scoped(MySpace::class);
        $items = [];
        foreach (SelfPages::ITEMS as $key => $definition) {
            $this->app->bind($items[] = 'peopleos.myspace_item.'.$key, fn () => PageItem::fromDefinition($key, $definition));
        }
        $this->app->tag($items, 'peopleos.myspace_items');
    }

    public function boot(): void
    {
        // HROne parity RQ-1: the request registry's per-request answers never carry into the next request.
        Event::listen(RequestHandled::class, fn () => $this->app->forgetInstance(RequestRegistry::class));
        // HROne parity MS-1: nor do My space's.
        Event::listen(RequestHandled::class, fn () => $this->app->forgetInstance(MySpace::class));
        // HROne parity RQ-2: nor do the request chrome's (approvers found, previews).
        Event::listen(RequestHandled::class, function () {
            $this->app->forgetInstance(RequestApprovers::class);
            $this->app->forgetInstance(RequestPreview::class);
            $this->app->forgetInstance(UnifiedInbox::class);
        });
        // HP-1 Time Office: employee-linked records follow the attendance record rules (scope, own records, no self-approval).
        foreach ([TimeOfficeRequest::class, LocationRequest::class, ShiftOverride::class, AttendanceOverride::class, AttendancePenalty::class, AttendanceArrear::class, EmployeeDevice::class] as $model) {
            Gate::policy($model, AttendanceRecordPolicy::class);
        }
        Gate::policy(GeoFence::class, AttendanceConfigPolicy::class);
        Gate::policy(LeaveDonation::class, LeaveRequestPolicy::class);
        // HROne parity PO-8: HR leave encashment batches.
        Gate::policy(LeaveEncashmentBatch::class, LeaveEncashmentBatchPolicy::class);
        // HP-2 payroll requests.
        Gate::policy(Loan::class, LoanPolicy::class);
        Gate::policy(LoanInstallment::class, LoanPolicy::class);
        Gate::policy(SalaryHold::class, LoanPolicy::class);
        Gate::policy(VariablePayRequest::class, LoanPolicy::class);
        Gate::policy(ReimbursementClaim::class, LoanPolicy::class);
        Gate::policy(ReimbursementLedgerEntry::class, LoanPolicy::class);
        // Data import DI-4: bills behind pay-by-default reimbursements.
        Gate::policy(ReimbursementBill::class, LoanPolicy::class);
        // HP-3 tax and statutory.
        Gate::policy(TaxIncomeEntry::class, LoanPolicy::class);
        Gate::policy(EmployeePfOption::class, LoanPolicy::class);
        Gate::policy(StatutoryOverride::class, StatutoryOverridePolicy::class);
        // HROne parity PO-13: the challan register follows the statutory return rules (compliance.returns.view in the company /
        // establishment scope; changes only through StatutoryChallans).
        Gate::policy(StatutoryChallan::class, StatutoryReturnPolicy::class);
        Gate::policy(StatutoryChallanAllocation::class, StatutoryReturnPolicy::class);
        // HROne setup parity: Workforce.
        Gate::policy(ConfirmationReview::class, ConfirmationReviewPolicy::class);
        Gate::policy(WorkforceReason::class, WorkforceReasonPolicy::class);
        Gate::policy(ClearanceChecklist::class, ClearanceChecklistPolicy::class);
        Gate::policy(TransferRequest::class, TransferRequestPolicy::class);
        // HROne parity WO-11: resignation withdrawal requests follow their exit case (ExitCasePolicy).
        Gate::policy(ExitWithdrawalRequest::class, ExitCasePolicy::class);
        // HROne parity WO-1: bulk runs (own runs; bulk.view_all sees every run).
        Gate::policy(BulkRun::class, BulkRunPolicy::class);
        // HROne parity WO-13: letter schedules (and what each did per employee) for people who issue letters.
        Gate::policy(LetterSchedule::class, LetterSchedulePolicy::class);
        Gate::policy(LetterScheduleItem::class, LetterSchedulePolicy::class);
        Gate::policy(BulkRunItem::class, BulkRunPolicy::class);
        // HROne parity WO-14: document share batches and the documents they shared, for people who share documents.
        Gate::policy(DocumentShareBatch::class, DocumentSharePolicy::class);
        Gate::policy(DocumentShareItem::class, DocumentSharePolicy::class);
        // HROne setup parity: Helpdesk (resolver rules follow the service desk configuration) and Asset (sub types follow
        // the asset masters).
        Gate::policy(ServiceResolverRule::class, ServiceDeskConfigPolicy::class);
        Gate::policy(AssetSubtype::class, AssetPolicy::class);
        // HROne setup parity EX-A: expense masters.
        foreach ([ExpenseGroup::class, ExpenseCategory::class, ExpenseCategoryLimit::class, ExpenseLocationGroup::class, ExpenseLocationGroupPlace::class, ExpenseTaxType::class] as $model) {
            Gate::policy($model, ExpenseConfigPolicy::class);
        }
        // HROne setup parity EX-C/EX-D: receipts, reports, advances (own records, scope, never deciding or paying one's own).
        foreach ([ExpenseReceipt::class, ExpenseReport::class, ExpenseReportPart::class, ExpenseAdvance::class, ExpenseAdvanceEntry::class] as $model) {
            Gate::policy($model, ExpensePolicy::class);
        }
        // HROne setup parity EX-F: travel requests and their legs (expense records plus the travel desk).
        Gate::policy(TravelRequest::class, TravelRequestPolicy::class);
        Gate::policy(TravelRequestLeg::class, TravelRequestPolicy::class);
        // Owner decision 2026-10-29: the approval parts of trips spanning several workflow groups.
        Gate::policy(TravelRequestPart::class, TravelRequestPolicy::class);
        // HROne setup parity AS-B: asset requests (own, team, raised, the asset team and the asset types' owner roles).
        Gate::policy(AssetRequest::class, AssetRequestPolicy::class);

        Event::listen(AttendanceEvent::class, CompOffClaimBridge::class);
        Event::listen(AttendanceEvent::class, ShortLeaveBalanceBridge::class);
        Event::listen(AttendanceEvent::class, function (AttendanceEvent $event): void {
            // Late-mark penalties follow every recalculated day of the month.
            if ($event->name === 'attendance.processed') {
                app(LatePenalties::class)->evaluate($event->employee, $event->subject->date);
            }
        });
        Event::listen(AttendanceEvent::class, function (AttendanceEvent $event): void {
            // HP-1b sandwich rule: a working day decided after the off days before it re-decides them.
            if ($event->name !== 'attendance.processed' || app(SettingsRepository::class)->get('attendance.sandwich_rule', 'none') === 'none') {
                return;
            }
            $record = $event->subject;
            if (in_array($record->status, ['weekly_off', 'holiday'], true) || in_array('sandwich', $record->exceptions ?? [], true)) {
                return;
            }
            $before = AttendanceRecord::query()->where('employee_id', $record->employee_id)->where('is_locked', false)
                ->whereDate('date', '<', $record->date->toDateString())->whereDate('date', '>=', $record->date->copy()->subDays(7)->toDateString())
                ->orderByDesc('date')->get(['id', 'date', 'status', 'exceptions']);
            foreach ($before as $day) {
                if (! in_array($day->status, ['weekly_off', 'holiday'], true) && ! in_array('sandwich', $day->exceptions ?? [], true)) {
                    break;
                }
                app(AttendanceProcessor::class)->process($event->employee, $day->date);
            }
        });
        Event::listen(AttendanceEvent::class, function (AttendanceEvent $event): void {
            // HROne setup parity (TO-4): a working day decided after the week's off days re-decides their entitlement.
            if ($event->name !== 'attendance.processed' || in_array($event->subject->status, ['weekly_off', 'holiday'], true)
                || array_intersect(['weekly_off_not_earned', 'holiday_not_earned'], $event->subject->exceptions ?? []) !== []) {
                return;
            }
            $policy = app(PolicyResolver::class)->resolve('attendance', $event->employee, $event->subject->date);
            if (! RequestRules::isYes($policy?->setting('weekly_off_entitlement')) && ! RequestRules::isYes($policy?->setting('holiday_entitlement'))) {
                return;
            }
            $date = $event->subject->date;
            AttendanceRecord::query()->where('employee_id', $event->employee->id)->where('is_locked', false)
                ->whereDate('date', '>=', $date->copy()->startOfWeek()->toDateString())->whereDate('date', '<=', $date->copy()->endOfWeek()->toDateString())
                ->whereDate('date', '!=', $date->toDateString())->get(['id', 'date', 'status', 'exceptions'])
                ->filter(fn (AttendanceRecord $d) => in_array($d->status, ['weekly_off', 'holiday'], true) || array_intersect(['weekly_off_not_earned', 'holiday_not_earned'], $d->exceptions ?? []) !== [])
                ->each(fn (AttendanceRecord $d) => app(AttendanceProcessor::class)->process($event->employee, $d->date));
        });
        Event::listen(AttendanceEvent::class, CompOffGenerator::class);
        Event::listen(WorkflowCompleted::class, TimeOfficeWorkflowBridge::class);
        Event::listen(PayrollEvent::class, [PayrollInputs::class, 'onFinalized']);
        Event::listen(PayrollEvent::class, [ReimbursementClaims::class, 'onFinalized']);
        Event::listen(WorkflowCompleted::class, LeaveExtrasWorkflowBridge::class);
        Event::listen(WorkflowCompleted::class, ExpenseWorkflowBridge::class);
        // HROne setup parity AS-B: workflows decide asset requests; joiners' entitled assets and exit returns are raised.
        Event::listen(WorkflowCompleted::class, AssetRequestWorkflowBridge::class);
        Event::listen(EmployeeLifecycleChanged::class, [AssetRequestTriggers::class, 'onLifecycle']);
        Event::listen(ExitEvent::class, [AssetRequestTriggers::class, 'onExit']);
        // HROne setup parity P2 (Import approval → workflow): a workflow on an upload approves or discards it.
        Event::listen(WorkflowCompleted::class, ImportWorkflowBridge::class);

        // HROne setup parity (Security → Disable login): sign-in follows the lifecycle states the tenant lists.
        Event::listen(EmployeeLifecycleChanged::class, DisableLoginOnStatus::class);
        // HROne parity SV-7: role membership rules follow people as they join, move and leave (queued, after the change).
        Event::listen(EmployeeLifecycleChanged::class, QueueRoleMembershipSync::class);
        Event::listen(EmploymentEvent::class, QueueRoleMembershipSync::class);
        // HROne parity CA-9: data-access groups are managed through AccessScopeGroups (user.view / user.assign_roles).
        Gate::policy(AccessScopeGroup::class, AccessScopeGroupPolicy::class);
        // CA-9: the access-scope answers (scope rows, data-access groups) never carry into the next request or queued job.
        Event::listen([RequestHandled::class, JobProcessing::class], function (): void {
            if ($this->app->resolved(AccessScopes::class)) {
                $this->app->make(AccessScopes::class)->forget();
            }
        });
        // HROne setup parity (Forms & templates → Code series).
        Gate::policy(CodeSeries::class, PeopleSetupPolicy::class);
        // HROne setup parity CA-2 / CA-3 (Core HR): organisation contacts follow the company permissions; qualification
        // masters and option lists follow people setup.
        Gate::policy(OrganisationContact::class, OrganisationContactPolicy::class);
        // HROne parity WO-15: business partners and the vendor master (partner.view / partner.manage; never deleted).
        Gate::policy(BusinessPartner::class, PartnerPolicy::class);
        Gate::policy(Vendor::class, PartnerPolicy::class);
        Gate::policy(Qualification::class, PeopleSetupPolicy::class);
        Gate::policy(QualificationSpecialisation::class, PeopleSetupPolicy::class);
        Gate::policy(OptionList::class, PeopleSetupPolicy::class);
        // Data import DI-1: regions, business regions, places and dimension values follow the organisation structure;
        // customers, projects and their members need project.view / project.manage; JV codes follow the integrations.
        foreach ([Region::class, BusinessRegion::class, GeoPlace::class, DimensionValue::class] as $model) {
            Gate::policy($model, OrganisationStructurePolicy::class);
        }
        foreach ([Customer::class, Project::class, ProjectMember::class] as $model) {
            Gate::policy($model, ProjectPolicy::class);
        }
        Gate::policy(JvCode::class, JvCodePolicy::class);
        // Data import (Workforce → Contract): contracts of contract staff follow the employee record.
        Gate::policy(EmployeeContract::class, EmployeeContractPolicy::class);
        // HROne setup parity HP-8a / HP-8c: performance masters and KPI rating formulas follow the performance
        // configuration (rating scales, KRA library); one-on-one feedback cards follow the employee-owned records.
        Gate::policy(PerformanceLookup::class, PerformanceConfigPolicy::class);
        Gate::policy(PerformanceUnit::class, PerformanceConfigPolicy::class);
        Gate::policy(KpiRatingFormula::class, PerformanceConfigPolicy::class);
        Gate::policy(OneOnOneFeedback::class, EmployeeOwnedPolicy::class);
        // HP-8b: the employee who scheduled a one-on-one and hosts it may open and update it (organisation scope).
        Gate::policy(OneOnOne::class, OneOnOnePolicy::class);
        // HROne parity GO-4: goals add private visibility (D-GO5), the organisation goal owner's check-in and the achievement
        // override (D-GO7) to the employee-owned rules.
        Gate::policy(Goal::class, GoalPolicy::class);
        // HROne setup parity HP-9b / HP-9c (Engage): mission statements follow the engagement configuration; badges are
        // set up by HR (recognition.manage); recognitions are seen as their visibility allows and never edited.
        Gate::policy(MissionStatement::class, MissionStatementPolicy::class);
        Gate::policy(Badge::class, BadgePolicy::class);
        Gate::policy(Recognition::class, RecognitionPolicy::class);
        // HROne setup parity HP-9d / HP-9e / HP-9f: the reward points ledger (own rows; HR with recognition.points), the
        // social wall (as SocialWall decides) and the mood bot reasons (engagement configuration).
        Gate::policy(RewardPointEntry::class, RewardPointEntryPolicy::class);
        Gate::policy(WallPost::class, WallPostPolicy::class);
        Gate::policy(WallComment::class, WallPostPolicy::class);
        Gate::policy(MoodReason::class, MoodReasonPolicy::class);
        // HROne parity GO-15 (Engage → Events): events are run by engagement.events (host and invitees open their own);
        // sessions, invitations and attendance are written only through CompanyEvents.
        Gate::policy(CompanyEvent::class, CompanyEventPolicy::class);
        foreach ([CompanyEventSession::class, CompanyEventInvitation::class, CompanyEventAttendance::class] as $model) {
            Gate::policy($model, CompanyEventRecordPolicy::class);
        }
        // HROne parity GO-18: fundraiser campaigns and pledges.
        Gate::policy(FundraiserCampaign::class, FundraiserPolicy::class);
        Gate::policy(FundraiserPledge::class, FundraiserPolicy::class);
        Event::listen(RequestHandled::class, fn () => $this->app->forgetInstance(Fundraisers::class));
        // Badge points are posted before the wall post that may show them.
        Event::listen(RecognitionEvent::class, [RewardPoints::class, 'onRecognition']);
        Event::listen(RecognitionEvent::class, [SocialWall::class, 'onRecognition']);
        Event::listen(EmployeeReminderDue::class, [SocialWall::class, 'onReminder']);
        Event::listen(CommunicationEvent::class, [SocialWall::class, 'onCommunication']);
        Event::listen(EmployeeLifecycleChanged::class, [SocialWall::class, 'onLifecycle']);
        Event::listen(PerformanceEvent::class, [SocialWall::class, 'onPerformance']);
        Event::listen(LearningEvent::class, [SocialWall::class, 'onLearning']);
        // HROne parity GO-15: a published company event is posted to its invitees (wall post type `event`); hidden when cancelled.
        Event::listen(EngagementEvent::class, [SocialWall::class, 'onEngagement']);
        // HROne setup parity P13 (Forms & templates → Feedback templates): banks and templates are configuration
        // (feedback.view / feedback.manage); feedback is read only as FeedbackRequests::canRead allows.
        foreach ([QuestionBank::class, QuestionBankQuestion::class, FeedbackTemplate::class] as $model) {
            Gate::policy($model, FeedbackConfigPolicy::class);
        }
        Gate::policy(FeedbackRequest::class, FeedbackRequestPolicy::class);
        // HROne setup parity BP-9: loan guarantors and payment modes follow the payroll requests (payroll in scope, own
        // records); variable pay plans add the employee's manager. BP-13 / CA-5: paying accounts, bank file layouts and
        // account number rules are payroll configuration.
        Gate::policy(LoanGuarantor::class, LoanPolicy::class);
        Gate::policy(EmployeePaymentMode::class, LoanPolicy::class);
        Gate::policy(VariablePayPlan::class, VariablePayPlanPolicy::class);
        foreach ([CompanyBankAccount::class, BankFileLayout::class, BankAccountRule::class] as $model) {
            Gate::policy($model, PayrollSetupPolicy::class);
        }
        // HROne setup parity HP-8d / HP-8e / HP-8f: goal templates follow the performance configuration; goal plans (goal
        // sheets submitted for approval) the employee-owned records; workflows decide goal plans and improvement plans.
        Gate::policy(GoalTemplate::class, PerformanceConfigPolicy::class);
        Gate::policy(GoalTemplateItem::class, PerformanceConfigPolicy::class);
        Gate::policy(GoalPlan::class, EmployeeOwnedPolicy::class);
        // HP-8d: the approver (third review level) may open the appraisal they review.
        Gate::policy(Appraisal::class, AppraisalPolicy::class);
        Event::listen(WorkflowCompleted::class, PerformanceWorkflowBridge::class);
        // HROne setup parity HP-8g: distribution targets (bell curve) and 9-box bands are performance configuration;
        // HP-8j: initiatives are seen and changed as Initiatives decides (employee, performance managers, HR in scope).
        Gate::policy(PerformanceDistributionTarget::class, PerformanceConfigPolicy::class);
        Gate::policy(PerformanceNineBoxBand::class, PerformanceConfigPolicy::class);
        Gate::policy(Initiative::class, InitiativePolicy::class);
        // HROne setup parity HP-8h / HP-8i: 360 feedback rounds, nomination sets, nominations and answers (answers are read
        // only through FeedbackRoundResults); periodic reviews follow the employee-owned performance records.
        foreach ([FeedbackRound::class, FeedbackParticipant::class, FeedbackNomination::class, FeedbackResponse::class] as $model) {
            Gate::policy($model, Feedback360Policy::class);
        }
        Gate::policy(PerformancePeriodReview::class, EmployeeOwnedPolicy::class);

        // HROne setup parity P4 (Inbox → Delegate, Archive inbox): out-of-office periods and archived items are personal.
        Gate::policy(TaskDelegation::class, TaskDelegationPolicy::class);
        Gate::policy(InboxArchive::class, TaskDelegationPolicy::class);
        // HROne setup parity P5 (Workflow per request type): workflows that run on these requests decide them
        // (RequestWorkflows), in the name of the person who completed the last task.
        Event::listen(WorkflowCompleted::class, RegularisationWorkflowBridge::class);
        Event::listen(WorkflowCompleted::class, PayrollRequestWorkflowBridge::class);
        Event::listen(WorkflowCompleted::class, CompensationWorkflowBridge::class);
        Event::listen(WorkflowCompleted::class, LetterWorkflowBridge::class);
        Event::listen(WorkflowCompleted::class, ResignationWorkflowBridge::class);
        // HROne parity WO-11: a workflow deciding a resignation withdrawal request.
        Event::listen(WorkflowCompleted::class, ExitWithdrawalWorkflowBridge::class);
        Event::listen(WorkflowCompleted::class, TaxDeclarationWorkflowBridge::class);
        // HROne parity MS-3: a workflow deciding a self-service profile change (P5).
        Event::listen(WorkflowCompleted::class, ProfileChangeWorkflowBridge::class);
        Gate::policy(ProfileChangeRequest::class, ProfileChangeRequestPolicy::class);
        // HROne parity MS-5: health insurance policies and insured members (employee.sensitive.view / update, own record).
        Gate::policy(InsurancePolicy::class, InsurancePolicyPolicy::class);
        Gate::policy(InsuredMember::class, InsurancePolicyPolicy::class);
        // HROne parity AC-3: product ideas to Markedge (product.feedback; answered by operators on the platform page).
        Gate::policy(ProductIdea::class, ProductIdeaPolicy::class);
    }
}
