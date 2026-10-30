<?php

namespace App\Filament\Pages;

use App\Domain\Performance\Support\AppraisalVisibility;
use App\Filament\Resources\AppraisalReviews\AppraisalReviewResource;
use App\Filament\Resources\Appraisals\AppraisalResource;
use App\Filament\Resources\BulkRuns\BulkRunResource;
use App\Filament\Resources\CalibrationSessions\CalibrationSessionResource;
use App\Filament\Resources\Competencies\CompetencyResource;
use App\Filament\Resources\FeedbackEntries\FeedbackEntryResource;
use App\Filament\Resources\FeedbackParticipants\FeedbackParticipantResource;
use App\Filament\Resources\FeedbackRounds\FeedbackRoundResource;
use App\Filament\Resources\GoalPlans\GoalPlanResource;
use App\Filament\Resources\Goals\GoalResource;
use App\Filament\Resources\GoalSheets\GoalSheetResource;
use App\Filament\Resources\GoalTemplates\GoalTemplateResource;
use App\Filament\Resources\ImprovementPlans\ImprovementPlanResource;
use App\Filament\Resources\Initiatives\InitiativeResource;
use App\Filament\Resources\Kras\KraResource;
use App\Filament\Resources\Letters\LetterResource;
use App\Filament\Resources\OneOnOnes\OneOnOneResource;
use App\Filament\Resources\PerformanceCheckIns\PerformanceCheckInResource;
use App\Filament\Resources\PerformanceCycles\PerformanceCycleResource;
use App\Filament\Resources\Policies\PolicyResource;
use App\Filament\Resources\RatingScales\RatingScaleResource;
use App\Filament\Resources\Reports\ReportResource;
use App\Filament\Support\Pages\OperationsDeskPage;
use BackedEnum;
use Filament\Support\Icons\Heroicon;
use UnitEnum;

/**
 * HROne parity GO-1 (HR Desk → Performance panel, matrix 27 §27.1 #1): every performance register in HROne's order —
 * Goals (employee goals, goal sheets, company goal, goal requests) · Reviews (cycles, performance reviews, review requests,
 * normalise and publish, 9-box) · Continuous feedback (360 rounds and nominations, initiatives, 1-on-1 meetings,
 * feedback, check-ins) · Performance improvement (PIP, PIP evaluations) · Performance letters (performance, PIP and PIP
 * evaluation letters as views of the Letters register) · Reports · Performance setup. Every entry is an existing screen
 * (some opened on a preset view); the desk adds no register and grants nothing: an entry is listed only when its screen
 * opens for the viewer, and counts come from each register's own (scoped) query. Opens for performance HR
 * (performance.view / performance.calibrate) who also run a performance process — not for managers, employees or a
 * read-only auditor.
 */
class PerformanceDesk extends OperationsDeskPage
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedRectangleGroup;

    protected static string|UnitEnum|null $navigationGroup = 'Performance';

    protected static ?string $navigationLabel = 'Performance desk';

    protected static ?string $title = 'Performance desk';

    protected static ?string $slug = 'performance-desk';

    protected static ?int $navigationSort = 1;

    /** Any one of these, with performance.view or performance.calibrate, makes someone a performance operator for the desk. */
    public const OPERATOR_PERMISSIONS = ['performance.manage', 'performance.calibrate', 'performance.pip', 'performance.initiatives_manage', 'performance.feedback_360'];

    public static function canAccess(): bool
    {
        $user = auth()->user();
        if ($user === null || ! AppraisalVisibility::isHr($user)) {
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
        return 'Every goal, review, feedback and improvement-plan register in one place, in the order HR works through a performance year. Open a register on its view, filter by employee, organisation or dates, save the views you use and report on what you see.';
    }

    protected function registers(): array
    {
        return ['goal_sheets', 'goal_requests', 'review_requests', 'appraisals', 'improvement_plans', 'letters'];
    }

    protected function sections(): array
    {
        $letters = fn (string $view, string $label) => ['label' => $label, 'target' => LetterResource::class, 'params' => ['view' => $view]];

        return [
            ['label' => 'Goals', 'items' => [
                ['label' => 'Employee goals', 'target' => GoalResource::class, 'params' => ['filters' => ['level' => ['value' => 'employee']]], 'description' => 'Every goal, KRA and KPI of every employee'],
                ['label' => 'Goal sheets', 'target' => GoalSheetResource::class, 'description' => 'One sheet per employee and cycle: not created, draft, waiting, sent back, active'],
                ['label' => 'Company goal', 'target' => GoalResource::class, 'params' => ['filters' => ['level' => ['value' => 'company']]], 'description' => 'Organisation objectives and their key results'],
                ['label' => 'Goal requests', 'target' => GoalPlanResource::class, 'params' => ['view' => 'pending'], 'description' => 'Goal sheets submitted for approval and their decisions',
                    'count' => fn () => GoalPlanResource::getEloquentQuery()->where('status', 'pending')->count()],
            ]],
            ['label' => 'Reviews', 'items' => [
                ['label' => 'Performance cycles', 'target' => PerformanceCycleResource::class, 'description' => 'Launch a cycle, move it to the next stage, close it'],
                ['label' => 'Performance reviews', 'target' => AppraisalResource::class, 'description' => 'One appraisal per employee and cycle with its ratings'],
                ['label' => 'Performance review requests', 'target' => AppraisalReviewResource::class, 'params' => ['view' => 'pending'], 'description' => 'Who still has to review; remind reviewers',
                    'count' => fn () => AppraisalReviewResource::getEloquentQuery()->where('status', 'pending')
                        ->whereHas('appraisal', fn ($a) => $a->whereNotIn('status', ['finalized', 'acknowledged'])->whereNull('locked_at'))->count()],
                ['label' => 'Normalise and publish ratings', 'target' => CalibrationBoard::class, 'description' => 'Calibration board: bell curve, calibrate, publish'],
                ['label' => '9-box', 'target' => NineBoxGrid::class],
                ['label' => 'Calibration sessions', 'target' => CalibrationSessionResource::class],
            ]],
            ['label' => 'Continuous feedback', 'items' => [
                ['label' => '360 feedbacks', 'target' => FeedbackRoundResource::class, 'description' => '360 feedback rounds: launch, extend, close, results'],
                ['label' => '360 nominations', 'target' => FeedbackParticipantResource::class, 'params' => ['tab' => 'all'], 'description' => 'Who was nominated for each employee, and approvals'],
                ['label' => 'Initiatives', 'target' => InitiativeResource::class],
                ['label' => '1-on-1 meetings', 'target' => OneOnOneResource::class],
                ['label' => 'Continuous feedback', 'target' => FeedbackEntryResource::class],
                ['label' => 'Check-ins', 'target' => PerformanceCheckInResource::class],
            ]],
            ['label' => 'Performance improvement', 'items' => [
                ['label' => 'PIP', 'target' => ImprovementPlanResource::class, 'description' => 'Performance improvement plans'],
                ['label' => 'PIP evaluations', 'target' => ImprovementPlanResource::class, 'params' => ['view' => 'evaluations'], 'description' => 'Plans at or after their evaluation',
                    'count' => fn () => ImprovementPlanResource::getEloquentQuery()->where('status', 'pending_outcome')->count()],
            ]],
            ['label' => 'Performance letters', 'items' => [
                $letters('performance', 'Performance letters'),
                $letters('pip', 'PIP letters'),
                $letters('pip_outcome', 'PIP evaluation letters'),
            ]],
            ['label' => 'Reports', 'items' => [
                ['label' => 'Report library', 'target' => ReportLibraryPage::class, 'description' => 'Ready-made goal, appraisal, PIP and initiative reports'],
                ['label' => 'My reports', 'target' => ReportResource::class],
                ['label' => 'Performance analytics', 'target' => PerformanceAnalyticsPage::class],
                ['label' => 'Bulk runs', 'target' => BulkRunResource::class, 'description' => 'Each bulk action you ran and every record\'s outcome'],
            ]],
            ['label' => 'Performance setup', 'items' => [
                ['label' => 'Setup centre: performance', 'target' => SetupCentre::class, 'params' => ['module' => 'performance'], 'description' => 'Settings and policies (goal sheet, PIP, 360, performance)'],
                ['label' => 'Policies', 'target' => PolicyResource::class],
                ['label' => 'Goal templates', 'target' => GoalTemplateResource::class],
                ['label' => 'KRA library', 'target' => KraResource::class],
                ['label' => 'Rating scales', 'target' => RatingScaleResource::class],
                ['label' => 'Competencies', 'target' => CompetencyResource::class],
            ]],
        ];
    }
}
