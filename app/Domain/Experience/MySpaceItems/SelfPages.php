<?php

namespace App\Domain\Experience\MySpaceItems;

use App\Filament\Pages\AlumniPortal;
use App\Filament\Pages\AnnouncementsFeed;
use App\Filament\Pages\AssistantPage;
use App\Filament\Pages\AttendanceCalendar;
use App\Filament\Pages\Birthdays;
use App\Filament\Pages\CareerPassportPage;
use App\Filament\Pages\DeclareInvestments;
use App\Filament\Pages\MyCareer;
use App\Filament\Pages\MyCompensation;
use App\Filament\Pages\MyDay;
use App\Filament\Pages\MyHr;
use App\Filament\Pages\MyLearning;
use App\Filament\Pages\MyProfile;
use App\Filament\Pages\MyRequests;
use App\Filament\Pages\NewRequest;
use App\Filament\Pages\OutOfOffice;
use App\Filament\Pages\Preferences;
use App\Filament\Pages\SocialWall;
use App\Filament\Resources\Appraisals\AppraisalResource;
use App\Filament\Resources\Articles\ArticleResource;
use App\Filament\Resources\AssetRequests\AssetRequestResource;
use App\Filament\Resources\Assets\AssetResource;
use App\Filament\Resources\AttendancePenalties\AttendancePenaltyResource;
use App\Filament\Resources\AttendanceRecords\AttendanceRecordResource;
use App\Filament\Resources\AttendanceRegularisations\AttendanceRegularisationResource;
use App\Filament\Resources\Courses\CourseResource;
use App\Filament\Resources\DevelopmentNeeds\DevelopmentNeedResource;
use App\Filament\Resources\DevelopmentPlans\DevelopmentPlanResource;
use App\Filament\Resources\ExitCases\ExitCaseResource;
use App\Filament\Resources\ExpenseAdvances\ExpenseAdvanceResource;
use App\Filament\Resources\ExpenseReceipts\ExpenseReceiptResource;
use App\Filament\Resources\ExpenseReports\ExpenseReportResource;
use App\Filament\Resources\FeedbackEntries\FeedbackEntryResource;
use App\Filament\Resources\FeedbackNominations\FeedbackNominationResource;
use App\Filament\Resources\FeedbackParticipants\FeedbackParticipantResource;
use App\Filament\Resources\FeedbackRequests\FeedbackRequestResource;
use App\Filament\Resources\Goals\GoalResource;
use App\Filament\Resources\Grievances\GrievanceResource;
use App\Filament\Resources\ImprovementPlans\ImprovementPlanResource;
use App\Filament\Resources\Initiatives\InitiativeResource;
use App\Filament\Resources\LearningCertificates\LearningCertificateResource;
use App\Filament\Resources\LearningEnrolments\LearningEnrolmentResource;
use App\Filament\Resources\LeaveBalances\LeaveBalanceResource;
use App\Filament\Resources\LeaveDonations\LeaveDonationResource;
use App\Filament\Resources\LeaveRequests\LeaveRequestResource;
use App\Filament\Resources\LeaveTransactions\LeaveTransactionResource;
use App\Filament\Resources\Letters\LetterResource;
use App\Filament\Resources\Loans\LoanResource;
use App\Filament\Resources\LocationRequests\LocationRequestResource;
use App\Filament\Resources\MyInvestmentProofs\MyInvestmentProofResource;
use App\Filament\Resources\MyLeaveEncashments\MyLeaveEncashmentResource;
use App\Filament\Resources\MyTaxCertificates\MyTaxCertificateResource;
use App\Filament\Resources\OneOnOnes\OneOnOneResource;
use App\Filament\Resources\Payslips\PayslipResource;
use App\Filament\Resources\PerformanceCheckIns\PerformanceCheckInResource;
use App\Filament\Resources\Recognitions\RecognitionResource;
use App\Filament\Resources\ReimbursementClaims\ReimbursementClaimResource;
use App\Filament\Resources\RewardPoints\RewardPointEntryResource;
use App\Filament\Resources\SkillAssessments\SkillAssessmentResource;
use App\Filament\Resources\TaxDeclarations\TaxDeclarationResource;
use App\Filament\Resources\TimeOfficeRequests\TimeOfficeRequestResource;
use App\Filament\Resources\TrainingSessions\TrainingSessionResource;
use App\Filament\Resources\TravelRequests\TravelRequestResource;
use App\Filament\Resources\VariablePayPlans\VariablePayPlanResource;
use App\Filament\Resources\VariablePayRequests\VariablePayRequestResource;

/**
 * HROne parity MS-1: the built-in My space items — one per existing self page (a page, a resource list, or a tab of My
 * HR), each opened under its own canAccess() (PageItem). Keywords carry HROne's item names (matrix 22 §22.0), so a person
 * coming from HROne finds "Payslip", "Loan history" or "1 on 1" by the old name.
 *
 * HROne items with no PeopleOS page yet are not listed (each owning package adds its item when it builds the page):
 * Attendance ytd (MS-11), Shift (MS-11), Holiday calendar as its own page (MS-12), Salary ytd (MS-10), Fbp approval
 * history (BP-7), Polls and Team announcement (MS-15), Reward store (MS-15 / VEN-3), Social profile (MS-6), Clearance
 * tasks, own Confirmation and Trips (MS-16), QR Code (MS-17); Job requisition and Position assigned are recruitment (N-A).
 */
final class SelfPages
{
    /**
     * key => definition (PageItem::fromDefinition). Groups: MySpace::GROUPS.
     *
     * @var array<string, array{label: string, group: string, icon: string, target: class-string, description?: string, keywords?: list<string>, parameters?: array<string, scalar>}>
     */
    public const ITEMS = [
        // Profile ------------------------------------------------------------------------------------------------------
        'profile' => ['label' => 'My profile', 'group' => 'profile', 'icon' => 'heroicon-o-identification', 'target' => MyProfile::class,
            'description' => 'Your record by section: personal, family, bank, documents, history', 'keywords' => ['profile', 'personal details', 'family', 'bank', 'documents', 'snapshot']],
        'my_hr' => ['label' => 'My HR', 'group' => 'profile', 'icon' => 'heroicon-o-sparkles', 'target' => MyHr::class,
            'description' => 'Requests, tasks, documents, letters and policies in one place', 'keywords' => ['hr', 'documents', 'help']],
        'hr_handbook' => ['label' => 'HR handbook', 'group' => 'profile', 'icon' => 'heroicon-o-book-open', 'target' => MyHr::class, 'parameters' => ['tab' => 'policies'],
            'description' => 'Policies and articles that apply to you; acknowledge new versions', 'keywords' => ['hr handbook', 'policies', 'handbook', 'acknowledge']],
        'knowledge_base' => ['label' => 'Knowledge base', 'group' => 'profile', 'icon' => 'heroicon-o-academic-cap', 'target' => ArticleResource::class,
            'description' => 'Articles HR published for you', 'keywords' => ['articles', 'knowledge', 'faq', 'hr handbook']],
        'letters' => ['label' => 'My letters', 'group' => 'profile', 'icon' => 'heroicon-o-document-text', 'target' => LetterResource::class,
            'description' => 'Letters issued to you', 'keywords' => ['letter', 'offer letter', 'experience letter', 'relieving letter']],
        'my_assets' => ['label' => 'My assets', 'group' => 'profile', 'icon' => 'heroicon-o-computer-desktop', 'target' => AssetResource::class,
            'description' => 'Laptops, phones and other assets in your custody', 'keywords' => ['my assets', 'asset', 'laptop', 'custody']],
        'my_exit' => ['label' => 'My exit', 'group' => 'profile', 'icon' => 'heroicon-o-arrow-right-start-on-rectangle', 'target' => ExitCaseResource::class,
            'description' => 'Your resignation, notice period and clearance', 'keywords' => ['exit', 'resignation', 'notice period', 'clearance', 'last working day']],
        'alumni_portal' => ['label' => 'Alumni portal', 'group' => 'profile', 'icon' => 'heroicon-o-user-group', 'target' => AlumniPortal::class,
            'description' => 'Documents and requests after you left', 'keywords' => ['alumni', 'former employee']],
        'preferences' => ['label' => 'Preferences', 'group' => 'profile', 'icon' => 'heroicon-o-adjustments-horizontal', 'target' => Preferences::class,
            'description' => 'How PeopleOS looks and opens for you', 'keywords' => ['settings', 'density', 'my preferences']],

        // Time ---------------------------------------------------------------------------------------------------------
        'my_day' => ['label' => 'My day', 'group' => 'time', 'icon' => 'heroicon-o-sun', 'target' => MyDay::class,
            'description' => 'Punch in or out, balances, apply leave, fix attendance', 'keywords' => ['mark attendance', 'punch', 'check in', 'check out', 'today']],
        'attendance' => ['label' => 'Attendance calendar', 'group' => 'time', 'icon' => 'heroicon-o-calendar', 'target' => AttendanceCalendar::class,
            'description' => 'Your month: in / out, late marks, shifts and holidays', 'keywords' => ['attendance', 'holiday calendar', 'holidays', 'shift', 'calendar']],
        'regularisations' => ['label' => 'Regularisations', 'group' => 'time', 'icon' => 'heroicon-o-clock', 'target' => AttendanceRegularisationResource::class,
            'description' => 'Corrections to your attendance and their approval', 'keywords' => ['attendance regularization', 'regularise', 'missed punch']],
        'attendance_records' => ['label' => 'Attendance records', 'group' => 'time', 'icon' => 'heroicon-o-table-cells', 'target' => AttendanceRecordResource::class,
            'description' => 'Your processed attendance, day by day', 'keywords' => ['attendance', 'present', 'absent', 'work hours']],
        'late_mark_penalties' => ['label' => 'Late-mark penalties', 'group' => 'time', 'icon' => 'heroicon-o-exclamation-triangle', 'target' => AttendancePenaltyResource::class,
            'description' => 'Penalties from late marks and early exits', 'keywords' => ['late mark', 'penalty', 'early going']],
        'time_office_requests' => ['label' => 'Time-office requests', 'group' => 'time', 'icon' => 'heroicon-o-briefcase', 'target' => TimeOfficeRequestResource::class,
            'description' => 'On duty, short leave, restricted holiday, shift change, overtime, comp-off', 'keywords' => ['on duty', 'short leave', 'restricted holiday', 'change shift', 'overtime', 'compensatory off', 'comp off', 'week off']],
        'location_requests' => ['label' => 'Location requests', 'group' => 'time', 'icon' => 'heroicon-o-map-pin', 'target' => LocationRequestResource::class,
            'description' => 'Places you asked to check in from', 'keywords' => ['geo location', 'location approval', 'geo fence']],
        'leave' => ['label' => 'Leave requests', 'group' => 'time', 'icon' => 'heroicon-o-calendar-days', 'target' => LeaveRequestResource::class,
            'description' => 'Your leave and its approval', 'keywords' => ['leave', 'time off', 'vacation']],
        'leave_balances' => ['label' => 'Leave balances', 'group' => 'time', 'icon' => 'heroicon-o-scale', 'target' => LeaveBalanceResource::class,
            'description' => 'What you have left of each leave type', 'keywords' => ['leave balance', 'balance']],
        'leave_transactions' => ['label' => 'Leave transactions', 'group' => 'time', 'icon' => 'heroicon-o-arrows-up-down', 'target' => LeaveTransactionResource::class,
            'description' => 'Credits and debits behind your balances', 'keywords' => ['leave ledger', 'credits', 'debits']],
        'leave_donations' => ['label' => 'Leave donations', 'group' => 'time', 'icon' => 'heroicon-o-gift', 'target' => LeaveDonationResource::class,
            'description' => 'Leave you gave to or received from colleagues', 'keywords' => ['donate leave']],
        'leave_encashments' => ['label' => 'Leave encashments', 'group' => 'time', 'icon' => 'heroicon-o-currency-rupee', 'target' => MyLeaveEncashmentResource::class,
            'description' => 'Leave you turned into pay', 'keywords' => ['leave encashment', 'encash']],

        // Pay ----------------------------------------------------------------------------------------------------------
        'compensation' => ['label' => 'My compensation', 'group' => 'pay', 'icon' => 'heroicon-o-banknotes', 'target' => MyCompensation::class,
            'description' => 'Your CTC and salary structure', 'keywords' => ['ctc', 'salary', 'salary structure', 'pay']],
        'payslips' => ['label' => 'Payslips', 'group' => 'pay', 'icon' => 'heroicon-o-document-currency-rupee', 'target' => PayslipResource::class,
            'description' => 'Your payslips by month', 'keywords' => ['payslip', 'salary slip']],
        'tax_certificates' => ['label' => 'My tax certificates', 'group' => 'pay', 'icon' => 'heroicon-o-document-check', 'target' => MyTaxCertificateResource::class,
            'description' => 'Form 16 for each financial year', 'keywords' => ['form 16', 'tds certificate', 'tax']],
        'declare_investments' => ['label' => 'Declare investments', 'group' => 'pay', 'icon' => 'heroicon-o-clipboard-document-list', 'target' => DeclareInvestments::class,
            'description' => 'Plan this year\'s tax-saving investments and your regime', 'keywords' => ['proposed investment', 'investment declaration', 'tax regime', '80c', 'previous employer']],
        'tax_declarations' => ['label' => 'Tax declarations', 'group' => 'pay', 'icon' => 'heroicon-o-receipt-percent', 'target' => TaxDeclarationResource::class,
            'description' => 'Your declarations by financial year', 'keywords' => ['proposed investment history', 'investment declaration', 'tax']],
        'investment_proofs' => ['label' => 'My investment proofs', 'group' => 'pay', 'icon' => 'heroicon-o-paper-clip', 'target' => MyInvestmentProofResource::class,
            'description' => 'Proofs for what you declared, and their verification', 'keywords' => ['confirmed investment history', 'proof', 'investment proof']],
        'loans' => ['label' => 'Loans and advances', 'group' => 'pay', 'icon' => 'heroicon-o-building-library', 'target' => LoanResource::class,
            'description' => 'Your loans, salary advances and repayments', 'keywords' => ['loan history', 'loan', 'salary advance', 'emi']],
        'reimbursements' => ['label' => 'Reimbursement claims', 'group' => 'pay', 'icon' => 'heroicon-o-receipt-refund', 'target' => ReimbursementClaimResource::class,
            'description' => 'Claims against your reimbursement components', 'keywords' => ['reimbursement history', 'reimbursement', 'claim', 'fuel']],
        'variable_pay' => ['label' => 'Variable pay', 'group' => 'pay', 'icon' => 'heroicon-o-chart-bar', 'target' => VariablePayRequestResource::class,
            'description' => 'Incentives and bonuses requested for you', 'keywords' => ['variable history', 'incentive', 'bonus']],
        'variable_pay_plans' => ['label' => 'Variable pay plans', 'group' => 'pay', 'icon' => 'heroicon-o-calendar', 'target' => VariablePayPlanResource::class,
            'description' => 'Your planned variable pay by month', 'keywords' => ['variable', 'incentive plan']],
        'expenses' => ['label' => 'Expense receipts', 'group' => 'pay', 'icon' => 'heroicon-o-receipt-percent', 'target' => ExpenseReceiptResource::class,
            'description' => 'Your expense receipts', 'keywords' => ['expense receipt', 'expense', 'bill']],
        'expense_reports' => ['label' => 'Expense reports', 'group' => 'pay', 'icon' => 'heroicon-o-document-chart-bar', 'target' => ExpenseReportResource::class,
            'description' => 'Receipts sent together for approval and payment', 'keywords' => ['expense report', 'expense claim']],
        'expense_advances' => ['label' => 'Expense advances', 'group' => 'pay', 'icon' => 'heroicon-o-wallet', 'target' => ExpenseAdvanceResource::class,
            'description' => 'Money paid ahead of your expenses', 'keywords' => ['expense advance', 'advance']],
        'travel' => ['label' => 'Travel requests', 'group' => 'pay', 'icon' => 'heroicon-o-paper-airplane', 'target' => TravelRequestResource::class,
            'description' => 'Your trips and the tickets booked for them', 'keywords' => ['travel', 'trip', 'ticket', 'booking']],

        // Performance --------------------------------------------------------------------------------------------------
        'goals' => ['label' => 'Goals', 'group' => 'performance', 'icon' => 'heroicon-o-flag', 'target' => GoalResource::class,
            'description' => 'Your goals by cycle, and organisation goals', 'keywords' => ['goal history', 'goals and initiatives', 'kra', 'okr', 'kpi']],
        'initiatives' => ['label' => 'My initiatives', 'group' => 'performance', 'icon' => 'heroicon-o-rocket-launch', 'target' => InitiativeResource::class,
            'description' => 'Actions you took towards your goals', 'keywords' => ['goals and initiatives', 'initiative', 'completed initiatives']],
        'appraisals' => ['label' => 'Appraisals', 'group' => 'performance', 'icon' => 'heroicon-o-star', 'target' => AppraisalResource::class,
            'description' => 'Your reviews and ratings once published', 'keywords' => ['performance scorecard', 'scorecard', 'review', 'rating', 'appraisal']],
        'one_on_ones' => ['label' => 'One-on-ones', 'group' => 'performance', 'icon' => 'heroicon-o-chat-bubble-left-right', 'target' => OneOnOneResource::class,
            'description' => 'Meetings with your manager and their notes', 'keywords' => ['1 on 1', 'one on one', 'meeting']],
        'check_ins' => ['label' => 'Check-ins', 'group' => 'performance', 'icon' => 'heroicon-o-check-circle', 'target' => PerformanceCheckInResource::class,
            'description' => 'Progress updates on your goals', 'keywords' => ['check in', 'progress']],
        'continuous_feedback' => ['label' => 'Continuous feedback', 'group' => 'performance', 'icon' => 'heroicon-o-chat-bubble-bottom-center-text', 'target' => FeedbackEntryResource::class,
            'description' => 'Feedback you gave and received', 'keywords' => ['continuous feedback', 'feedback', 'praise']],
        'feedback_360' => ['label' => '360 feedback', 'group' => 'performance', 'icon' => 'heroicon-o-arrow-path-rounded-square', 'target' => FeedbackParticipantResource::class,
            'description' => 'Your 360 rounds and reviewers', 'keywords' => ['360 feedback', 'multi rater']],
        'feedback_360_to_give' => ['label' => '360 feedback to give', 'group' => 'performance', 'icon' => 'heroicon-o-pencil-square', 'target' => FeedbackNominationResource::class,
            'description' => 'Colleagues who asked for your 360 feedback', 'keywords' => ['360 feedback', 'nomination']],
        'improvement_plans' => ['label' => 'Improvement plans', 'group' => 'performance', 'icon' => 'heroicon-o-arrow-trending-up', 'target' => ImprovementPlanResource::class,
            'description' => 'Your performance improvement plans and their outcome', 'keywords' => ['pip', 'pip evaluation', 'improvement plan']],
        'feedback_forms' => ['label' => 'Feedback forms', 'group' => 'performance', 'icon' => 'heroicon-o-clipboard-document-check', 'target' => FeedbackRequestResource::class,
            'description' => 'Exit interview, confirmation, probation and induction forms for you', 'keywords' => ['exit interview', 'confirmation', 'probation', 'induction', 'feedback form']],
        'career' => ['label' => 'My career', 'group' => 'performance', 'icon' => 'heroicon-o-arrow-trending-up', 'target' => MyCareer::class,
            'description' => 'Career paths, readiness and development', 'keywords' => ['career', 'growth', 'development plan']],
        'career_passport' => ['label' => 'Career passport', 'group' => 'performance', 'icon' => 'heroicon-o-identification', 'target' => CareerPassportPage::class,
            'description' => 'Your skills, achievements and history in one record', 'keywords' => ['passport', 'skills']],
        'learning' => ['label' => 'My learning', 'group' => 'performance', 'icon' => 'heroicon-o-academic-cap', 'target' => MyLearning::class,
            'description' => 'Courses assigned to you and your progress', 'keywords' => ['training', 'course', 'learning']],
        'learning_enrolments' => ['label' => 'Course enrolments', 'group' => 'performance', 'icon' => 'heroicon-o-book-open', 'target' => LearningEnrolmentResource::class,
            'description' => 'Every course you are enrolled in', 'keywords' => ['training', 'enrolment', 'course']],
        'learning_certificates' => ['label' => 'Learning certificates', 'group' => 'performance', 'icon' => 'heroicon-o-trophy', 'target' => LearningCertificateResource::class,
            'description' => 'Certificates you earned', 'keywords' => ['certificate', 'training']],
        'course_catalogue' => ['label' => 'Course catalogue', 'group' => 'performance', 'icon' => 'heroicon-o-rectangle-stack', 'target' => CourseResource::class,
            'description' => 'Courses you can take', 'keywords' => ['training', 'course', 'catalogue']],
        'training_sessions' => ['label' => 'Training sessions', 'group' => 'performance', 'icon' => 'heroicon-o-presentation-chart-bar', 'target' => TrainingSessionResource::class,
            'description' => 'Classroom and virtual sessions; register yourself', 'keywords' => ['training', 'session', 'classroom', 'workshop']],
        'development_plans' => ['label' => 'Development plans', 'group' => 'performance', 'icon' => 'heroicon-o-map', 'target' => DevelopmentPlanResource::class,
            'description' => 'Your development goals, learning and milestones', 'keywords' => ['idp', 'development', 'growth plan']],
        'development_needs' => ['label' => 'Development needs', 'group' => 'performance', 'icon' => 'heroicon-o-light-bulb', 'target' => DevelopmentNeedResource::class,
            'description' => 'Needs found in your performance conversations', 'keywords' => ['development', 'skill gap']],
        'skill_assessments' => ['label' => 'Skill assessments', 'group' => 'performance', 'icon' => 'heroicon-o-adjustments-vertical', 'target' => SkillAssessmentResource::class,
            'description' => 'Assessments of your skills', 'keywords' => ['skills', 'competency', 'proficiency']],

        // Engage -------------------------------------------------------------------------------------------------------
        'recognition' => ['label' => 'Recognition', 'group' => 'engage', 'icon' => 'heroicon-o-hand-thumb-up', 'target' => RecognitionResource::class,
            'description' => 'Badges and appreciation you received and gave', 'keywords' => ['badges and rewards', 'badge', 'kudos', 'appreciation']],
        'reward_points' => ['label' => 'Reward points', 'group' => 'engage', 'icon' => 'heroicon-o-sparkles', 'target' => RewardPointEntryResource::class,
            'description' => 'Your points, giving and redeeming them', 'keywords' => ['badges and rewards', 'reward points', 'reward store', 'redeem', 'points']],
        'social_wall' => ['label' => 'Social wall', 'group' => 'engage', 'icon' => 'heroicon-o-newspaper', 'target' => SocialWall::class,
            'description' => 'Posts, celebrations and recognition across the organisation', 'keywords' => ['wall', 'posts', 'social']],
        'celebrations' => ['label' => 'Birthdays and anniversaries', 'group' => 'engage', 'icon' => 'heroicon-o-cake', 'target' => Birthdays::class,
            'description' => 'Colleagues\' birthdays and work anniversaries; your own celebrated dates', 'keywords' => ['birthday', 'anniversary', 'celebrations']],
        'announcements' => ['label' => 'Announcements', 'group' => 'engage', 'icon' => 'heroicon-o-megaphone', 'target' => AnnouncementsFeed::class,
            'description' => 'News from HR and leadership', 'keywords' => ['announcement', 'news', 'team announcement']],
        'surveys' => ['label' => 'Surveys', 'group' => 'engage', 'icon' => 'heroicon-o-clipboard-document', 'target' => MyHr::class, 'parameters' => ['tab' => 'surveys'],
            'description' => 'Surveys open for you and the ones you answered', 'keywords' => ['survey', 'pulse', 'polls']],
        'assistant' => ['label' => 'Assistant', 'group' => 'engage', 'icon' => 'heroicon-o-chat-bubble-oval-left-ellipsis', 'target' => AssistantPage::class,
            'description' => 'Ask questions about policies and your own data', 'keywords' => ['ai', 'help', 'support assistant']],

        // Requests -----------------------------------------------------------------------------------------------------
        'my_requests' => ['label' => 'My requests', 'group' => 'requests', 'icon' => 'heroicon-o-queue-list', 'target' => MyRequests::class,
            'description' => 'Everything you asked for, with its status', 'keywords' => ['requests', 'request history', 'status', 'pending']],
        'new_request' => ['label' => 'New request', 'group' => 'requests', 'icon' => 'heroicon-o-plus-circle', 'target' => NewRequest::class,
            'description' => 'Everything you can request, in one place', 'keywords' => ['raise', 'apply', 'request']],
        'helpdesk' => ['label' => 'HR helpdesk tickets', 'group' => 'requests', 'icon' => 'heroicon-o-lifebuoy', 'target' => MyHr::class, 'parameters' => ['tab' => 'requests'],
            'description' => 'Questions and services you asked HR for', 'keywords' => ['ticket', 'helpdesk', 'ask hr', 'service request']],
        'asset_requests' => ['label' => 'Asset requests', 'group' => 'requests', 'icon' => 'heroicon-o-inbox-arrow-down', 'target' => AssetRequestResource::class,
            'description' => 'Assets you asked for or are returning', 'keywords' => ['asset request', 'laptop request']],
        'grievances' => ['label' => 'My grievances', 'group' => 'requests', 'icon' => 'heroicon-o-shield-exclamation', 'target' => GrievanceResource::class,
            'description' => 'Concerns you raised, handled confidentially', 'keywords' => ['grievance', 'complaint', 'concern']],
        'out_of_office' => ['label' => 'Out of office', 'group' => 'requests', 'icon' => 'heroicon-o-arrow-uturn-right', 'target' => OutOfOffice::class,
            'description' => 'Hand your approvals to a colleague while you are away', 'keywords' => ['delegate', 'out of office', 'vacation']],
    ];
}
