<?php

namespace App\Filament\Pages;

use App\Domain\Bgv\Models\BgvCase;
use App\Domain\Employment\Models\TransferRequest;
use App\Domain\Exit\Models\ExitCase;
use App\Domain\Lifecycle\Models\ConfirmationReview;
use App\Domain\Onboarding\Models\OnboardingPlan;
use App\Filament\Resources\BankAccountImports\BankAccountImportResource;
use App\Filament\Resources\BgvCases\BgvCaseResource;
use App\Filament\Resources\BulkRuns\BulkRunResource;
use App\Filament\Resources\ConfirmationReviews\ConfirmationReviewResource;
use App\Filament\Resources\EmployeeImports\EmployeeImportResource;
use App\Filament\Resources\Employees\EmployeeResource;
use App\Filament\Resources\ExitCases\ExitCaseResource;
use App\Filament\Resources\Letters\LetterResource;
use App\Filament\Resources\OnboardingPlans\OnboardingPlanResource;
use App\Filament\Resources\Reports\ReportResource;
use App\Filament\Resources\TransferRequests\TransferRequestResource;
use App\Filament\Support\Pages\OperationsDeskPage;
use BackedEnum;
use Filament\Support\Icons\Heroicon;
use UnitEnum;

/**
 * HROne parity WO-1 (HR Desk → Workforce panel, matrix 23 §23.1 #1): the workforce registers in the order of the
 * employee journey. Every entry is an existing register (some opened on a preset view); nothing here is a new register.
 * Opens for people who run HR operations (employee.view plus an operations permission) and lists only what they can
 * open; business partners (WO-15) are not built yet.
 */
class WorkforceDesk extends OperationsDeskPage
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedRectangleGroup;

    protected static string|UnitEnum|null $navigationGroup = 'People';

    protected static ?string $navigationLabel = 'Workforce desk';

    protected static ?string $title = 'Workforce desk';

    protected static ?string $slug = 'workforce-desk';

    protected static ?int $navigationSort = 5;

    /** Any one of these, with employee.view, makes someone an HR operator for the desk. */
    public const OPERATOR_PERMISSIONS = ['employee.lifecycle', 'employee.create', 'onboarding.manage', 'bgv.view', 'exit.view', 'exit.manage', 'letter.view', 'letter.issue'];

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

    public function mount(): void
    {
        abort_unless(static::canAccess(), 403);
    }

    public function getSubheading(): ?string
    {
        return 'Every workforce register in the order of the employee journey. Filter a register, select records or act on all that match, and save the views you use.';
    }

    protected function registers(): array
    {
        return ['employees', 'confirmation_reviews', 'exit_cases', 'onboarding_plans', 'bgv_cases', 'transfer_requests', 'letters'];
    }

    protected function sections(): array
    {
        return [
            ['label' => 'People', 'items' => [
                ['label' => 'Employees', 'target' => EmployeeResource::class, 'description' => 'Search, filter, save views, act in bulk'],
                ['label' => 'Directory', 'target' => People::class, 'description' => 'Cards by department, location or manager'],
                ['label' => 'Employee changes', 'target' => ChangeIntelligencePage::class, 'description' => 'Every audited change to employee records'],
            ]],
            ['label' => 'Onboarding', 'items' => [
                ['label' => 'New hires (not joined yet)', 'target' => EmployeeResource::class, 'params' => ['view' => 'new_hires']],
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
            ]],
            ['label' => 'Workforce letters', 'items' => [
                ['label' => 'Confirmation letters', 'target' => LetterResource::class, 'params' => ['view' => 'confirmation']],
                ['label' => 'Transfer letters', 'target' => LetterResource::class, 'params' => ['view' => 'transfer']],
                ['label' => 'Relieving letters', 'target' => LetterResource::class, 'params' => ['view' => 'relieving']],
                ['label' => 'Experience letters', 'target' => LetterResource::class, 'params' => ['view' => 'experience']],
                ['label' => 'All letters', 'target' => LetterResource::class],
            ]],
            ['label' => 'Reports and uploads', 'items' => [
                ['label' => 'Report library', 'target' => ReportLibraryPage::class, 'description' => 'Ready-made workforce reports (People, Joining & exit)'],
                ['label' => 'My reports', 'target' => ReportResource::class],
                ['label' => 'Employee upload', 'target' => EmployeeImportResource::class],
                ['label' => 'Bank details upload', 'target' => BankAccountImportResource::class],
                ['label' => 'Recent uploads', 'target' => RecentUploads::class],
                ['label' => 'Bulk runs', 'target' => BulkRunResource::class, 'description' => 'Each bulk action you ran and every record\'s outcome'],
            ]],
        ];
    }
}
