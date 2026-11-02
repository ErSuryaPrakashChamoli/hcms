<?php

namespace App\Filament\Pages;

use App\Domain\Bgv\Models\BgvCase;
use App\Domain\Employment\Models\Employee;
use App\Domain\Employment\Models\TransferRequest;
use App\Domain\Employment\Services\UpcomingJoiners as UpcomingJoinersService;
use App\Domain\Exit\Models\ExitCase;
use App\Domain\Exit\Models\ExitClearance;
use App\Domain\Exit\Models\ExitWithdrawalRequest;
use App\Domain\Experience\Services\BulkOperations;
use App\Domain\Letters\Models\Letter;
use App\Domain\Lifecycle\Models\ConfirmationReview;
use App\Domain\Onboarding\Models\OnboardingPlan;
use App\Domain\Partners\Models\BusinessPartner;
use App\Filament\Resources\BankAccountImports\BankAccountImportResource;
use App\Filament\Resources\BgvCases\BgvCaseResource;
use App\Filament\Resources\BulkRuns\BulkRunResource;
use App\Filament\Resources\BusinessPartners\BusinessPartnerResource;
use App\Filament\Resources\ConfirmationReviews\ConfirmationReviewResource;
use App\Filament\Resources\DocumentShareBatches\DocumentShareBatchResource;
use App\Filament\Resources\EmployeeImports\EmployeeImportResource;
use App\Filament\Resources\Employees\EmployeeResource;
use App\Filament\Resources\ExitCases\ExitCaseResource;
use App\Filament\Resources\ExitClearances\ExitClearanceResource;
use App\Filament\Resources\Letters\LetterResource;
use App\Filament\Resources\LetterSchedules\LetterScheduleResource;
use App\Filament\Resources\OnboardingPlans\OnboardingPlanResource;
use App\Filament\Resources\ProductIdeas\ProductIdeaResource;
use App\Filament\Resources\PublishedDocuments\PublishedDocumentResource;
use App\Filament\Resources\Reports\ReportResource;
use App\Filament\Resources\TransferRequests\TransferRequestResource;
use App\Filament\Resources\Vendors\VendorResource;
use App\Filament\Support\Pages\OperationsDeskPage;
use BackedEnum;
use Filament\Support\Icons\Heroicon;
use UnitEnum;

/**
 * HROne parity WO-1 (HR Desk → Workforce panel, matrix 23 §23.1 #1): the workforce registers in the order of the
 * employee journey. Every entry is an existing register (some opened on a preset view); nothing here is a new register.
 * Opens for people who run HR operations (employee.view plus an operations permission) and lists only what they can
 * open; WO-15 added business partners and vendors. Labelled "Lifecycle desk": it is the front page of the Lifecycle area
 * (ExperienceNavigation::DESKS), and "Workforce" is the Organisation area of positions and workforce plans.
 */
class WorkforceDesk extends OperationsDeskPage
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedRectangleGroup;

    protected static string|UnitEnum|null $navigationGroup = 'People';

    protected static ?string $navigationLabel = 'Lifecycle desk';

    protected static ?string $title = 'Lifecycle desk';

    protected static ?string $slug = 'workforce-desk';

    protected static ?int $navigationSort = 5;

    /** Any one of these, with employee.view, makes someone an HR operator for the desk. */
    public const OPERATOR_PERMISSIONS = ['employee.lifecycle', 'employee.create', 'onboarding.manage', 'bgv.view', 'exit.view', 'exit.manage', 'letter.view', 'letter.issue', 'partner.view', 'partner.manage',
        // HROne parity WO-14: people who share documents with many employees.
        'document.share'];

    public static function canAccess(): bool
    {
        $user = auth()->user();
        if ($user === null || ! $user->hasPermission('employee.view')) {
            return false;
        }
        foreach (self::OPERATOR_PERMISSIONS as $permission) {
            if ($user->hasPermission($permission)) {
                return static::anyEntryOpens();
            }
        }

        return false;
    }

    /** HROne parity WO-16: whether the viewer may run a bulk operation (bulk.run + its permission). */
    private static function mayRun(string $operation): bool
    {
        $user = auth()->user();

        return $user !== null && app(BulkOperations::class)->allows($user, $operation);
    }

    public function mount(): void
    {
        abort_unless(static::canAccess(), 403);
    }

    public function getSubheading(): ?string
    {
        return 'Every lifecycle register in the order of the employee journey. Filter a register, select records or act on all that match, and save the views you use.';
    }

    protected function registers(): array
    {
        return ['employees', 'confirmation_reviews', 'exit_cases', 'exit_clearances', 'onboarding_plans', 'bgv_cases', 'transfer_requests', 'letters'];
    }

    protected function sections(): array
    {
        return [
            ['label' => 'People', 'items' => [
                ['label' => 'Employees', 'target' => EmployeeResource::class, 'description' => 'Search, filter, save views, act in bulk'],
                ['label' => 'Directory', 'target' => People::class, 'description' => 'Cards by department, location or manager'],
                ['label' => 'Employee changes', 'target' => ChangeIntelligencePage::class, 'description' => 'Every audited change to employee records'],
                // HROne parity WO-16: the bulk wizards of the Employees register (opened with ?action=), for those who may run them.
                ['label' => 'Change manager', 'target' => EmployeeResource::class, 'params' => ['action' => 'bulkChangeManager'], 'description' => 'Move a team or chosen employees to a new manager',
                    'visible' => fn () => self::mayRun('employees.change_manager')],
                ['label' => 'Invite to sign in', 'target' => EmployeeResource::class, 'params' => ['action' => 'bulkInvite'], 'description' => 'New sign-ins from work e-mails, or reset links',
                    'visible' => fn () => self::mayRun('employees.invite_users') || self::mayRun('employees.send_reset_links')],
                ['label' => 'Send password reset links', 'target' => EmployeeResource::class, 'params' => ['action' => 'bulkResetLinks'],
                    'visible' => fn () => self::mayRun('employees.send_reset_links')],
            ]],
            ['label' => 'Onboarding', 'items' => [
                ['label' => 'New hires (not joined yet)', 'target' => EmployeeResource::class, 'params' => ['view' => 'new_hires']],
                // HROne parity WO-4: the upcoming joiners register (expected joining, days to join, onboarding, checks, documents).
                ['label' => 'Upcoming joiners', 'target' => UpcomingJoiners::class, 'description' => 'Joining dates, onboarding, documents; not joining',
                    'count' => fn () => Employee::query()->whereIn('lifecycle_state', UpcomingJoinersService::preJoiningStates())->count()],
                ['label' => 'Background verifications', 'target' => BgvCaseResource::class, 'params' => ['view' => 'open'], 'count' => fn () => BgvCase::query()->whereIn('status', ['initiated', 'in_progress'])->count()],
                ['label' => 'Pre-boarding and onboarding', 'target' => OnboardingPlanResource::class, 'params' => ['view' => 'onboarding'], 'count' => fn () => OnboardingPlan::query()->where('status', 'in_progress')->where('kind', 'onboarding')->count()],
                ['label' => 'Inductions', 'target' => OnboardingPlanResource::class, 'params' => ['view' => 'inductions']],
            ]],
            ['label' => 'Confirmations', 'items' => [
                ['label' => 'Confirmation reviews', 'target' => ConfirmationReviewResource::class, 'params' => ['view' => 'pending'], 'count' => fn () => ConfirmationReview::query()->where('status', 'pending')->count()],
                ['label' => 'Probation ending this month', 'target' => ConfirmationReviewResource::class, 'params' => ['view' => 'due_this_month']],
            ]],
            ['label' => 'Transfer', 'items' => [
                ['label' => 'Transfer requests', 'target' => TransferRequestResource::class, 'params' => ['view' => 'pending'], 'count' => fn () => TransferRequest::query()->where('status', 'pending')->count()],
                ['label' => 'Transfers done', 'target' => TransferRequestResource::class, 'params' => ['view' => 'approved']],
            ]],
            ['label' => 'Separation', 'items' => [
                ['label' => 'Resignations', 'target' => ExitCaseResource::class, 'params' => ['view' => 'resignations'], 'count' => fn () => ExitCase::query()->where('type', 'resignation')->whereIn('status', ExitCase::OPEN)->count()],
                ['label' => 'Resignation withdrawals', 'target' => ExitCaseResource::class, 'params' => ['view' => 'withdrawn']],
                ['label' => 'Clearances', 'target' => ExitCaseResource::class, 'params' => ['view' => 'clearance'], 'count' => fn () => ExitCase::query()->where('status', 'clearance')->count()],
                ['label' => 'Final clearances', 'target' => ExitCaseResource::class, 'params' => ['view' => 'final']],
                // HROne parity WO-11: the stage register, exits ready to complete and withdrawal requests waiting for approval.
                ['label' => 'Clearance stages', 'target' => ExitClearanceResource::class, 'params' => ['view' => 'open'],
                    'count' => fn () => ExitClearance::query()->whereIn('status', ['pending', 'blocked'])->whereHas('exitCase', fn ($c) => $c->where('status', 'clearance'))->count()],
                ['label' => 'Ready to complete', 'target' => ExitCaseResource::class, 'params' => ['view' => 'ready_to_complete'],
                    'count' => fn () => ExitCase::query()->whereIn('status', ['clearance', 'settlement'])->whereHas('clearances')->whereDoesntHave('clearances', fn ($c) => $c->whereIn('status', ['pending', 'blocked']))->count()],
                ['label' => 'Withdrawal requests waiting', 'target' => ExitCaseResource::class, 'params' => ['view' => 'withdrawal_requests'],
                    'count' => fn () => ExitWithdrawalRequest::query()->where('status', 'pending')->count()],
            ]],
            ['label' => 'Workforce letters', 'items' => [
                ['label' => 'Confirmation letters', 'target' => LetterResource::class, 'params' => ['view' => 'confirmation']],
                ['label' => 'Transfer letters', 'target' => LetterResource::class, 'params' => ['view' => 'transfer']],
                ['label' => 'Relieving letters', 'target' => LetterResource::class, 'params' => ['view' => 'relieving']],
                ['label' => 'Experience letters', 'target' => LetterResource::class, 'params' => ['view' => 'experience']],
                // HROne parity WO-13: the other process letter registers, the generation queue and letter schedules.
                ['label' => 'Extension letters', 'target' => LetterResource::class, 'params' => ['view' => 'probation_extension']],
                ['label' => 'Termination letters', 'target' => LetterResource::class, 'params' => ['view' => 'termination']],
                ['label' => 'Contract letters', 'target' => LetterResource::class, 'params' => ['view' => 'contract']],
                ['label' => 'Transfer intimation letters', 'target' => LetterResource::class, 'params' => ['view' => 'transfer_intimation']],
                ['label' => 'Resignation acceptance letters', 'target' => LetterResource::class, 'params' => ['view' => 'resignation_acceptance']],
                ['label' => 'Letters ready to issue', 'target' => LetterResource::class, 'params' => ['view' => 'to_issue'], 'count' => fn () => Letter::query()->where('status', 'approved')->count()],
                ['label' => 'Letter schedules', 'target' => LetterScheduleResource::class, 'description' => 'Letters generated later or around employee dates'],
                ['label' => 'All letters', 'target' => LetterResource::class],
                // HROne parity WO-14: ready-made files shared with many employees (the wizard opens with ?action=), the share
                // register and every document shared per employee.
                ['label' => 'Share documents', 'target' => DocumentShareBatchResource::class, 'params' => ['action' => 'shareDocuments'],
                    'description' => 'A zip with a sheet of employee codes, or one file for everyone chosen'],
                ['label' => 'Shared documents', 'target' => DocumentShareBatchResource::class, 'description' => 'Each share: counts shared / failed, errors, unpublish'],
                ['label' => 'Published documents', 'target' => PublishedDocumentResource::class, 'description' => 'What each employee received; unpublish'],
            ]],
            // HROne parity WO-15 (matrix 23 §23.8, §23.9 #21): people outside the workforce who may sign in, and the vendor master.
            ['label' => 'Business partners', 'items' => [
                ['label' => 'Business partners', 'target' => BusinessPartnerResource::class, 'description' => 'Customers, BGV agents, vendors\' staff, consultants',
                    'count' => fn () => BusinessPartner::query()->where('status', 'active')->count()],
                ['label' => 'Add business partner', 'target' => BusinessPartnerResource::class, 'params' => ['action' => 'create'],
                    'visible' => fn () => (bool) auth()->user()?->can('create', BusinessPartner::class)],
                ['label' => 'Not invited yet', 'target' => BusinessPartnerResource::class, 'params' => ['view' => 'not_invited']],
                ['label' => 'Vendors', 'target' => VendorResource::class, 'description' => 'Suppliers, agencies and consultants'],
            ]],
            ['label' => 'Reports and uploads', 'items' => [
                ['label' => 'Report library', 'target' => ReportLibraryPage::class, 'description' => 'Ready-made workforce reports (People, Joining & exit)'],
                ['label' => 'My reports', 'target' => ReportResource::class],
                ['label' => 'Employee upload', 'target' => EmployeeImportResource::class],
                ['label' => 'Bank details upload', 'target' => BankAccountImportResource::class],
                ['label' => 'Recent uploads', 'target' => RecentUploads::class],
                ['label' => 'Bulk runs', 'target' => BulkRunResource::class, 'description' => 'Each bulk action you ran and every record\'s outcome'],
            ]],
            // HROne parity AC-3: HROne's "Product enhancement request" (a Workforce action) — an idea to Markedge, and the ideas sent.
            ['label' => 'Ideas for PeopleOS', 'items' => [
                ['label' => 'Suggest an improvement', 'target' => ProductIdeaResource::class, 'params' => ['action' => 'create'], 'description' => 'Tell Markedge what PeopleOS should do better',
                    'visible' => fn () => ProductIdeaResource::canCreate()],
                ['label' => 'Product ideas', 'target' => ProductIdeaResource::class, 'description' => 'Ideas your organisation sent, their status and Markedge\'s responses'],
            ]],
        ];
    }
}
