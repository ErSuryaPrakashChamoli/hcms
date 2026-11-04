<?php

namespace App\Domain\Experience\Services;

use App\Domain\Experience\Contracts\RequestType;
use App\Domain\Identity\Models\User;
use App\Domain\Performance\Services\FeedbackRounds;
use App\Filament\Pages\AdvanceRecovery;
use App\Filament\Pages\AttendanceStatusChange;
use App\Filament\Pages\BonusProcessing;
use App\Filament\Pages\CalibrationBoard;
use App\Filament\Pages\ComplianceReportsPage;
use App\Filament\Pages\HolidayChanges;
use App\Filament\Pages\IncrementCalculator;
use App\Filament\Pages\LeaveCarryForward;
use App\Filament\Pages\PayExpenses;
use App\Filament\Pages\PayrollControlRoom;
use App\Filament\Pages\RosterBoard;
use App\Filament\Pages\RunAttendance;
use App\Filament\Pages\SalaryStructureRegister;
use App\Filament\Pages\StatutoryChallansPage;
use App\Filament\Resources\Announcements\AnnouncementResource;
use App\Filament\Resources\AppraisalReviews\AppraisalReviewResource;
use App\Filament\Resources\Articles\ArticleResource;
use App\Filament\Resources\Assets\AssetResource;
use App\Filament\Resources\BusinessPartners\BusinessPartnerResource;
use App\Filament\Resources\CompanyEvents\CompanyEventResource;
use App\Filament\Resources\CompensationCycles\CompensationCycleResource;
use App\Filament\Resources\DocumentShareBatches\DocumentShareBatchResource;
use App\Filament\Resources\Employees\EmployeeResource;
use App\Filament\Resources\FeedbackRounds\FeedbackRoundResource;
use App\Filament\Resources\FundraiserCampaigns\FundraiserCampaignResource;
use App\Filament\Resources\GoalPlans\GoalPlanResource;
use App\Filament\Resources\Goals\GoalResource;
use App\Filament\Resources\ImprovementPlans\ImprovementPlanResource;
use App\Filament\Resources\LeaveEncashmentBatches\LeaveEncashmentBatchResource;
use App\Filament\Resources\Letters\LetterResource;
use App\Filament\Resources\PerformanceCycles\PerformanceCycleResource;
use App\Filament\Resources\ProductIdeas\ProductIdeaResource;
use App\Filament\Resources\Surveys\SurveyResource;
use App\Filament\Resources\TransferRequests\TransferRequestResource;
use Closure;
use Filament\Pages\Page;
use Filament\Resources\Resource;
use Throwable;

/**
 * HROne parity AC-1: the HR action catalogue behind the Actions panel (HROne's HR Desk "Actions": search, recent and
 * pinned actions, every action the role may run, grouped by module). Each action opens an existing screen or an existing
 * Filament action on it (`?action=`), so the form, validation, approvals and audit are the module's own; the catalogue
 * only decides whether to offer it. An action is listed only when its destination's own canAccess / canCreate /
 * permission / action visibility lets the signed-in person in — it hides nothing that is otherwise reachable and opens
 * nothing that is otherwise blocked. "… for an employee" entries come from the request registry's on-behalf forms
 * (RequestType::onBehalfUrl), so a request type that gains an HR form appears here without a change.
 *
 * Recruitment (requisitions, candidates, interviews, offers, candidate engagement) is out of scope (owner, 2026-10-31).
 */
final class ActionCatalogue
{
    /** The panel's sections, in HROne's order (recruitment excluded). */
    public const SECTIONS = [
        'workforce' => 'Workforce',
        'time_office' => 'Time office',
        'payroll' => 'Payroll',
        'expense' => 'Expense & travel',
        'performance' => 'Performance',
        'engage' => 'Engage',
        'asset' => 'Assets',
        'helpdesk' => 'Helpdesk',
    ];

    public const ICONS = [
        'workforce' => 'heroicon-o-users',
        'time_office' => 'heroicon-o-clock',
        'payroll' => 'heroicon-o-banknotes',
        'expense' => 'heroicon-o-receipt-percent',
        'performance' => 'heroicon-o-chart-bar',
        'engage' => 'heroicon-o-megaphone',
        'asset' => 'heroicon-o-computer-desktop',
        'helpdesk' => 'heroicon-o-lifebuoy',
    ];

    /** @var array<string, list<array<string, mixed>>> */
    private array $memo = [];

    public function __construct(private readonly RequestRegistry $requests) {}

    /**
     * Every action the signed-in person may run now, in panel order.
     *
     * @return list<array{key: string, section: string, label: string, hint: string, hrone: string, keywords: list<string>, icon: string, url: string}>
     */
    public function for(User $user): array
    {
        // Destinations check access with static canAccess()/canCreate(), which read the signed-in user: answer only for
        // that user (fail-closed for anyone else).
        if ((int) auth()->id() !== (int) $user->id) {
            return [];
        }

        return $this->memo[$user->id.':'.$user->updated_at?->getTimestamp()] ??= $this->resolve($user);
    }

    /**
     * The panel's sections with their actions (sections without an action the person may run are left out).
     *
     * @return list<array{key: string, label: string, icon: string, items: list<array<string, mixed>>}>
     */
    public function sections(User $user): array
    {
        $grouped = [];
        foreach ($this->for($user) as $action) {
            $grouped[$action['section']][] = $action;
        }
        $sections = [];
        foreach (self::SECTIONS as $key => $label) {
            if (($grouped[$key] ?? []) !== []) {
                $sections[] = ['key' => $key, 'label' => $label, 'icon' => self::ICONS[$key], 'items' => $grouped[$key]];
            }
        }

        return $sections;
    }

    /** One action the person may run now (re-resolved: a key from the browser or from preferences is never trusted). */
    public function find(User $user, string $key): ?array
    {
        foreach ($this->for($user) as $action) {
            if ($action['key'] === $key) {
                return $action;
            }
        }

        return null;
    }

    /** Whether the person has any HR action to run (the top-bar "Actions" button and the page). */
    public function hasAny(User $user): bool
    {
        return $this->for($user) !== [];
    }

    /** Forget the per-request answers (after the person's permissions or the data behind them changed). */
    public function forget(): void
    {
        $this->memo = [];
    }

    /** @return list<array<string, mixed>> */
    private function resolve(User $user): array
    {
        $behalf = [];
        foreach ($this->requests->onBehalf($user) as ['type' => $type, 'url' => $url]) {
            /** @var RequestType $type */
            $behalf[$type->key()] = $url;
        }
        $onBehalf = fn (string $type) => fn () => $behalf[$type] ?? null;
        $bulk = fn (string $operation) => (bool) rescue(fn () => app(BulkOperations::class)->allows($user, $operation), false, false);

        $actions = [];
        foreach ($this->definitions($user, $onBehalf, $bulk) as [$key, $section, $label, $hint, $hrone, $keywords, $url]) {
            try {
                $target = $url();
            } catch (Throwable) {
                $target = null;
            }
            if (is_string($target) && $target !== '') {
                $actions[] = [
                    'key' => $key, 'section' => $section, 'label' => $label, 'hint' => $hint, 'hrone' => $hrone,
                    'keywords' => $keywords, 'icon' => self::ICONS[$section], 'url' => $target,
                ];
            }
        }

        return $actions;
    }

    /**
     * [key, section, label, hint, HROne name, keywords, url resolver (null = not offered)].
     *
     * @param  Closure(string): Closure  $onBehalf
     * @param  Closure(string): bool  $bulk
     * @return list<array{0: string, 1: string, 2: string, 3: string, 4: string, 5: list<string>, 6: Closure(): ?string}>
     */
    private function definitions(User $user, Closure $onBehalf, Closure $bulk): array
    {
        $can = fn (string $permission) => $user->hasPermission($permission);

        return [
            // Workforce.
            ['add_business_partner', 'workforce', 'Add business partner', 'A customer, vendor, consultant or verification agency contact', 'Add business partner', ['business partner', 'partner', 'vendor', 'external user', 'consultant', 'bgv agency'],
                fn () => BusinessPartnerResource::canAccess() && $can('partner.manage') ? self::resource(BusinessPartnerResource::class, ['action' => 'create']) : null],
            ['add_employee', 'workforce', 'Add employee', 'Hire into a position with the hire wizard', 'Add employee', ['hire', 'new joiner', 'onboard', 'create employee'],
                fn () => EmployeeResource::canCreate() ? EmployeeResource::getUrl('create') : null],
            ['add_new_hire', 'workforce', 'Add new hire (joining later)', 'A pre-joiner: pre-boarding until the joining date', 'Add new hire (upcoming join)', ['upcoming join', 'pre-joiner', 'pre boarding', 'offer accepted', 'future joining'],
                fn () => EmployeeResource::canCreate() ? EmployeeResource::getUrl('create', ['upcoming' => 1]) : null],
            ['change_manager', 'workforce', 'Change manager', 'Move a team or chosen employees to a new manager', 'Change manager', ['manager', 'reporting', 'reports to', 'line manager', 'functional manager'],
                fn () => $bulk('employees.change_manager') ? self::resource(EmployeeResource::class, ['action' => 'bulkChangeManager']) : null],
            ['generate_letter', 'workforce', 'Generate letter', 'Any letter from an approved template', 'Generate letter (misc. letter)', ['letter', 'certificate', 'misc letter', 'document'],
                fn () => $can('letter.issue') ? self::resource(LetterResource::class, ['action' => 'generate']) : null],
            ['invite_to_sign_in', 'workforce', 'Invite to sign in', 'Sign-ins from work e-mails, or reset links', 'Invite to HROne', ['invite', 'login', 'password', 'access', 'sign in', 'activate'],
                fn () => $bulk('employees.invite_users') || $bulk('employees.send_reset_links') ? self::resource(EmployeeResource::class, ['action' => 'bulkInvite']) : null],
            ['suggest_improvement', 'workforce', 'Suggest a product improvement', 'Tell Markedge what PeopleOS should do better', 'Product enhancement request', ['feature request', 'product enhancement', 'suggestion', 'idea', 'improvement', 'feedback to markedge'],
                fn () => ProductIdeaResource::canAccess() && ProductIdeaResource::canCreate() ? ProductIdeaResource::suggestUrl() : null],
            ['resignation_for_employee', 'workforce', 'Resignation for an employee', 'Record a resignation and start the exit', 'Resignation request', ['resign', 'resignation', 'exit', 'separation', 'notice'],
                $onBehalf('resignation')],
            ['share_documents', 'workforce', 'Share letters and documents', 'Send files to many employees at once, or publish letters', 'Share letters/ documents', ['share documents', 'publish letters', 'bulk documents', 'send files', 'form 16', 'documents'],
                fn () => self::resource(DocumentShareBatchResource::class, ['action' => 'shareDocuments'])],
            ['transfer_request', 'workforce', 'Transfer request', 'Move someone to another unit, location or role', 'Transfer request', ['transfer', 'relocate', 'move', 'promotion'],
                fn () => $can('employee.position') ? self::resource(TransferRequestResource::class, ['action' => 'request']) : null],

            // Time office.
            ['regularisation_for_employee', 'time_office', 'Attendance regularisation for an employee', 'Missed punch or wrong time, raised by HR', 'Attendance regularization request', ['regularise', 'regularize', 'attendance', 'missed punch', 'ar'],
                $onBehalf('attendance_regularisation')],
            ['carry_forward_leaves', 'time_office', 'Carry forward leaves', 'Year-end: preview what carries and lapses, then post', 'Carry forward leaves', ['carry forward', 'year end', 'lapse', 'leave year close', 'cf'],
                fn () => self::page(LeaveCarryForward::class)],
            ['change_attendance_status', 'time_office', 'Change attendance status', 'Set present, absent or half days for chosen people', 'Change attendance status', ['attendance status', 'mark present', 'mark absent', 'half day', 'override'],
                fn () => self::page(AttendanceStatusChange::class)],
            ['change_holiday', 'time_office', 'Change holiday for employees', 'Give, take away or swap a holiday for chosen people', 'Change holiday', ['holiday', 'change holiday', 'assign holiday', 'manage holiday', 'swap holiday'],
                fn () => self::page(HolidayChanges::class, ['action' => 'changeHoliday'])],
            ['change_shift', 'time_office', 'Assign shift', 'Roster board: shifts and weekly offs for chosen people', 'Change shift', ['shift', 'roster', 'assign shift', 'weekly off', 'change shift'],
                fn () => self::page(RosterBoard::class)],
            ['leave_for_employee', 'time_office', 'Leave for an employee', 'Apply leave on someone’s behalf', 'Leave request', ['leave', 'apply leave', 'on behalf', 'absence'],
                $onBehalf('leave')],
            ['leave_encashment_for_employee', 'time_office', 'Leave encashment for an employee', 'Encash leave on someone’s behalf', 'Leave encashment request', ['encash', 'encashment', 'leave encashment'],
                $onBehalf('leave_encashment')],
            ['on_duty_for_employee', 'time_office', 'On duty for an employee', 'Outdoor or client work, raised by HR', 'On duty request', ['on duty', 'od', 'outdoor', 'field work'],
                $onBehalf('time_office.on_duty')],
            ['restricted_holiday_for_employee', 'time_office', 'Restricted holiday for an employee', 'Choose an optional holiday for someone', 'Restricted holiday request', ['restricted holiday', 'optional holiday', 'rh', 'floating holiday'],
                $onBehalf('time_office.restricted_holiday')],
            ['run_attendance', 'time_office', 'Run attendance', 'Process days, close the month', 'Run attendance (attendance dashboard)', ['process attendance', 'attendance dashboard', 'month close', 'run attendance'],
                fn () => self::page(RunAttendance::class)],
            ['shift_request_for_employee', 'time_office', 'Shift change for an employee', 'Ask for a different shift on someone’s behalf', 'Shift request', ['shift request', 'shift change', 'swap shift'],
                $onBehalf('time_office.shift_change')],
            ['short_leave_for_employee', 'time_office', 'Short leave for an employee', 'A few hours away, raised by HR', 'Short leave request', ['short leave', 'permission', 'early leaving', 'late coming'],
                $onBehalf('time_office.short_leave')],

            // Payroll.
            ['assign_salary_structure', 'payroll', 'Assign salary structure', 'Employees without a structure first', 'Assign salary structure', ['salary structure', 'ctc', 'assign salary', 'pay structure', 'compensation'],
                fn () => self::page(SalaryStructureRegister::class, ['view' => 'not_assigned'])],
            ['investment_proofs_for_employee', 'payroll', 'Investment proofs for an employee', 'Confirmed investments with proofs', 'Confirmed investment', ['investment proof', 'confirmed investment', 'tax proof', '80c', 'proof'],
                $onBehalf('investment_proof')],
            ['process_bonus', 'payroll', 'Process bonus', 'Statutory bonus and ex gratia for the accounting year', 'Process bonus', ['bonus', 'statutory bonus', 'ex gratia', 'bonus act', 'annual bonus'],
                fn () => self::page(BonusProcessing::class, ['action' => 'prepareBonus'])],
            ['encash_leaves', 'payroll', 'Encash leaves', 'Encash a leave type for many employees, paid with payroll', 'Encash leaves', ['encash leaves', 'bulk encashment', 'leave encashment', 'encashment batch'],
                // Batches are made by the "Encash leaves" wizard, not a create page (canCreate() is always false): its own gate.
                fn () => LeaveEncashmentBatchResource::canAccess() && $can('leave.manage') ? self::resource(LeaveEncashmentBatchResource::class, ['action' => 'encashLeaves']) : null],
            ['generate_challan', 'payroll', 'Generate challan', 'PF, ESI, PT, LWF and TDS by month: generate, pay, record the challan', 'Generate challan', ['challan', 'pf challan', 'esi challan', 'ecr', 'tds challan', 'statutory'],
                fn () => self::page(StatutoryChallansPage::class) ?? self::page(ComplianceReportsPage::class)],
            ['increment_calculator', 'payroll', 'Increment calculator', 'Plan increments by rating and band', 'Increment calculator', ['increment', 'appraisal hike', 'raise', 'salary revision', 'merit'],
                fn () => self::page(IncrementCalculator::class, ['action' => 'create']) ?? self::resource(CompensationCycleResource::class)],
            ['loan_for_employee', 'payroll', 'Loan for an employee', 'Request a loan on someone’s behalf', 'Loan request', ['loan', 'salary advance', 'emi'],
                $onBehalf('loan')],
            ['investment_declaration_for_employee', 'payroll', 'Investment declaration for an employee', 'Proposed investments for the year', 'Propose investment request', ['investment declaration', 'proposed investment', 'tax declaration', '80c', 'hra'],
                $onBehalf('investment_declaration')],
            ['reimbursement_for_employee', 'payroll', 'Reimbursement for an employee', 'Claim a reimbursement on someone’s behalf', 'Reimbursement request', ['reimbursement', 'claim', 'bills', 'allowance claim'],
                $onBehalf('reimbursement')],
            ['run_payroll', 'payroll', 'Run payroll', 'Current run, readiness, exceptions and sign-off', 'Run payroll (salary dashboard)', ['payroll', 'salary dashboard', 'process salary', 'run payroll'],
                fn () => self::page(PayrollControlRoom::class)],
            ['variable_pay_for_employee', 'payroll', 'Variable pay for an employee', 'Incentive or bonus amounts for chosen people', 'Variable pay request', ['variable pay', 'incentive', 'commission', 'one time payment'],
                $onBehalf('variable_pay')],

            // Expense & travel.
            ['expense_for_employee', 'expense', 'Add expense for an employee', 'Record an expense on someone’s behalf', 'Add expense', ['expense', 'receipt', 'bill', 'add expense'],
                $onBehalf('expense')],
            ['advance_for_employee', 'expense', 'Advance for an employee', 'Request an advance on someone’s behalf', 'Advance request', ['advance', 'expense advance', 'travel advance', 'imprest'],
                $onBehalf('expense_advance')],
            ['pay_expenses', 'expense', 'Pay expenses', 'Approved reports waiting for payment', 'Pay expenses', ['pay expenses', 'reimburse', 'payout', 'settle expenses'],
                fn () => self::page(PayExpenses::class)],
            ['recover_advance', 'expense', 'Recover advance', 'Unsettled advances and their recovery', 'Recover advance', ['recover advance', 'advance recovery', 'unsettled advance'],
                fn () => self::page(AdvanceRecovery::class)],
            ['travel_for_employee', 'expense', 'Travel for an employee', 'Request a trip on someone’s behalf', 'Travel request', ['travel', 'trip', 'booking', 'tour'],
                $onBehalf('travel')],

            // Performance.
            ['add_pip', 'performance', 'Add improvement plan (PIP)', 'Goals, check-ins and an outcome', 'Add PIP', ['pip', 'improvement plan', 'performance improvement', 'underperformance'],
                fn () => ImprovementPlanResource::canAccess() && ImprovementPlanResource::canCreate() ? self::resource(ImprovementPlanResource::class, ['action' => 'create']) : null],
            ['change_goal', 'performance', 'Change goals', 'Revise an employee’s goals', 'Change goal', ['change goal', 'edit goal', 'revise goals', 'goal sheet'],
                fn () => $can('performance.manage') ? self::resource(GoalResource::class, ['filters' => ['level' => ['value' => 'employee']]]) : null],
            ['create_company_goal', 'performance', 'Create company goal', 'An organisation goal or OKR to align to', 'Create company goal', ['company goal', 'okr', 'organisation goal', 'objective'],
                fn () => $can('performance.manage') && GoalResource::canCreate() ? GoalResource::getUrl('create') : null],
            ['withdraw_goals', 'performance', 'Withdraw goals from a cycle', 'Cancel chosen people’s goal setting (nothing is deleted)', 'Delete goals', ['delete goals', 'remove goals', 'withdraw', 'cancel goals'],
                fn () => $can('performance.manage') ? self::resource(PerformanceCycleResource::class, ['filters' => ['status' => ['value' => 'active']]]) : null],
            ['withdraw_reviews', 'performance', 'Withdraw reviews from a cycle', 'Withdraw chosen people’s open reviews (nothing is deleted)', 'Delete reviews', ['delete reviews', 'remove reviews', 'withdraw', 'cancel review'],
                fn () => $can('performance.manage') ? self::resource(PerformanceCycleResource::class, ['filters' => ['status' => ['value' => 'active']]]) : null],
            ['extend_360', 'performance', 'Extend 360 feedback', 'More time for everyone or chosen people', 'Extend 360', ['extend 360', '360 deadline', 'feedback deadline'],
                fn () => FeedbackRounds::mayManage($user) ? self::resource(FeedbackRoundResource::class) : null],
            ['extend_goal', 'performance', 'Extend goal setting', 'A later goal-setting date for everyone or chosen people', 'Extend goal', ['extend goal', 'goal deadline', 'goal window'],
                fn () => $can('performance.manage') ? self::resource(GoalPlanResource::class, ['action' => 'posPerfExtendGoal']) : null],
            ['extend_review', 'performance', 'Extend review', 'A later review date for everyone or chosen people', 'Extend review', ['extend review', 'review deadline', 'review window'],
                fn () => $can('performance.manage') ? self::resource(AppraisalReviewResource::class, ['action' => 'posPerfExtendReview']) : null],
            ['initiate_360', 'performance', 'Start 360 feedback', 'Launch a 360 round for chosen people', 'Initiate 360 feedback', ['360', 'multi rater', 'initiate 360', 'peer feedback'],
                fn () => FeedbackRounds::mayManage($user) ? self::resource(FeedbackRoundResource::class, ['action' => 'launchRound']) : null],
            ['initiate_goal_cycle', 'performance', 'Start goal cycle', 'Create a cycle, or add people to a running one', 'Initiate goal cycle', ['goal cycle', 'goal setting', 'initiate goals', 'start goals'],
                fn () => $can('performance.manage') && PerformanceCycleResource::canCreate() ? PerformanceCycleResource::getUrl('create') : null],
            ['initiate_review_cycle', 'performance', 'Start review cycle', 'Move a running cycle to review, or start a periodic review', 'Initiate review cycle', ['review cycle', 'appraisal', 'initiate review', 'start review'],
                fn () => $can('performance.manage') ? self::resource(PerformanceCycleResource::class, ['filters' => ['status' => ['value' => 'active']]]) : null],
            ['normalise_performance_rating', 'performance', 'Normalise performance ratings', 'Calibrate final ratings against the target curve', 'Normalize performance rating', ['normalise', 'normalize', 'calibrate', 'bell curve', 'rating'],
                fn () => self::page(CalibrationBoard::class, ['action' => 'normalise'])],
            ['normalise_review_rating', 'performance', 'Normalise review ratings', 'Calibrate the ratings of one review stage', 'Normalize review rating', ['normalise review', 'normalize review', 'review rating', 'calibrate'],
                fn () => self::page(CalibrationBoard::class, ['action' => 'normaliseReviews'])],
            ['publish_performance_rating', 'performance', 'Publish ratings', 'Release final ratings to employees', 'Publish performance rating', ['publish rating', 'release rating', 'final rating'],
                fn () => self::page(CalibrationBoard::class, ['action' => 'publishRatings'])],

            // Engage.
            ['add_announcement', 'engage', 'Add announcement', 'Targeted, with acknowledgement', 'Add announcement', ['announcement', 'news', 'circular', 'notice'],
                fn () => AnnouncementResource::canCreate() ? AnnouncementResource::getUrl('create') : null],
            ['add_event', 'engage', 'Add event', 'Town hall, training or celebration: sessions, invitations, attendance', 'Add event', ['event', 'town hall', 'celebration', 'webinar', 'offsite', 'invite'],
                fn () => CompanyEventResource::canAccess() && CompanyEventResource::canCreate() ? CompanyEventResource::getUrl('create') : null],
            ['initiate_fundraiser', 'engage', 'Start a fundraiser', 'A cause with a target; employees pledge or donate', 'Initiate fundraise', ['fundraiser', 'fundraise', 'donation', 'charity', 'csr', 'pledge'],
                fn () => self::resource(FundraiserCampaignResource::class, ['action' => 'startFundraiser'])],
            ['add_handbook', 'engage', 'Add to HR handbook', 'A policy or article with acknowledgements', 'Add HR handbook', ['handbook', 'policy', 'article', 'hr policy'],
                fn () => ArticleResource::canCreate() ? ArticleResource::getUrl('create') : null],
            ['add_survey', 'engage', 'Add survey', 'Pulse, engagement or event feedback', 'Add survey', ['survey', 'pulse', 'poll', 'questionnaire'],
                fn () => SurveyResource::canCreate() ? SurveyResource::getUrl('create') : null],

            // Assets.
            ['add_asset', 'asset', 'Add asset to inventory', 'A laptop, phone or any other asset', 'Add asset (inventory)', ['asset', 'inventory', 'laptop', 'hardware', 'add asset'],
                fn () => AssetResource::canCreate() ? AssetResource::getUrl('create') : null],
            ['asset_request_for_employee', 'asset', 'Asset request for an employee', 'Ask for an asset on someone’s behalf', 'Asset request', ['asset request', 'laptop request', 'equipment'],
                $onBehalf('asset')],

            // Helpdesk.
            ['ticket_for_employee', 'helpdesk', 'Raise a ticket for an employee', 'A helpdesk ticket on someone’s behalf', 'Add ticket', ['ticket', 'helpdesk', 'query', 'issue', 'grievance'],
                $onBehalf('helpdesk')],
        ];
    }

    /** @param  class-string<resource>  $resource */
    private static function resource(string $resource, array $params = []): ?string
    {
        return $resource::canAccess() && array_key_exists('index', $resource::getPages()) ? $resource::getUrl('index', $params) : null;
    }

    /** @param  class-string<Page>  $page */
    private static function page(string $page, array $params = []): ?string
    {
        return $page::canAccess() ? $page::getUrl($params) : null;
    }
}
