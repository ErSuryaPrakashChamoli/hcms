<?php

namespace App\Filament\Support;

use App\Domain\Employment\Models\Employee;
use App\Domain\Performance\Models\AppraisalReview;
use App\Domain\Performance\Models\Goal;
use App\Domain\Performance\Models\Initiative;
use App\Domain\Performance\Models\PerformanceCycle;
use App\Domain\Performance\Services\Appraisals;
use App\Domain\Performance\Services\FeedbackRounds;
use App\Domain\Performance\Services\GoalSheets;
use App\Domain\Performance\Services\PerformanceRelationships;
use App\Domain\Performance\Services\PerformanceReminders;
use App\Domain\Performance\Support\AppraisalVisibility;
use App\Domain\Workflow\Models\WorkflowInstance;
use App\Filament\Pages\Approvals;
use App\Filament\Pages\CalibrationBoard;
use App\Filament\Resources\AppraisalReviews\AppraisalReviewResource;
use App\Filament\Resources\FeedbackRounds\FeedbackRoundResource;
use App\Filament\Resources\Goals\GoalResource;
use App\Filament\Resources\ImprovementPlans\ImprovementPlanResource;
use App\Filament\Resources\PerformanceCycles\PerformanceCycleResource;
use App\Filament\Resources\WorkflowInstances\WorkflowInstanceResource;
use Closure;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Filters\BaseFilter;
use Illuminate\Database\Eloquent\Model;
use Throwable;

/**
 * HROne parity GO-1 (matrix 27 §27.1, HR Desk → Performance panel): what the performance registers share.
 *
 * - hrSide(): performance HR (performance.view / performance.calibrate). New filters, columns and header tools on
 *   registers employees and managers also use are shown only to them, so an employee's own lists stay as they were.
 * - actionsMenu(): HROne's split button / "ACTIONS" menu of the panel (Initiate goal or review cycle, Create company goal,
 *   Normalise and publish ratings, Initiate 360 feedback, Add PIP, Remind reviewers) as links to the screens where each is
 *   done today, each listed only when that screen opens for the viewer; nothing new is granted.
 * - approval(): the approval trail of a goal plan or an improvement plan (HROne "View workflow") — the workflow run with a
 *   link to its tracker when the viewer may open it, else the Approval Center while it waits.
 * - reopenReviewAction() / remindReviewerAction(): HR's actions on one review (owner decision PER-1 / D-GO1 for reopen).
 * - goalsAndInitiativesAction(): HROne's "Goals and initiative" flyer — one employee's goals and initiatives of a cycle.
 */
final class PerformanceRegisters
{
    public static function hrSide(): bool
    {
        return AppraisalVisibility::isHr(auth()->user());
    }

    /**
     * @param  list<BaseFilter>  $filters
     * @return list<BaseFilter> the filters, shown only to performance HR
     */
    public static function hrOnly(array $filters): array
    {
        return array_map(fn (BaseFilter $filter) => $filter->visible(fn () => self::hrSide()), $filters);
    }

    public static function actionsMenu(): ActionGroup
    {
        $user = fn () => auth()->user();
        $opens = fn (Closure $check) => fn () => (bool) rescue($check, false, false);

        return ActionGroup::make([
            Action::make('posPerfInitiateCycle')->label('Initiate goal or review cycle')->icon(Heroicon::OutlinedRocketLaunch)
                ->visible($opens(fn () => $user()->can('performance.manage') && PerformanceCycleResource::canAccess()))
                ->url(fn () => PerformanceCycleResource::getUrl('index')),
            Action::make('posPerfCompanyGoal')->label('Create company goal')->icon(Heroicon::OutlinedFlag)
                ->visible($opens(fn () => $user()->can('performance.manage') && GoalResource::canCreate()))
                ->url(fn () => GoalResource::getUrl('create')),
            Action::make('posPerfNormalise')->label('Normalise and publish ratings')->icon(Heroicon::OutlinedAdjustmentsVertical)
                ->visible($opens(fn () => CalibrationBoard::canAccess()))
                ->url(fn () => CalibrationBoard::getUrl()),
            Action::make('posPerf360')->label('Initiate 360 feedback')->icon(Heroicon::OutlinedUserGroup)
                ->visible($opens(fn () => FeedbackRoundResource::canAccess() && FeedbackRounds::mayManage($user())))
                ->url(fn () => FeedbackRoundResource::getUrl('index', ['action' => 'launchRound'])),
            Action::make('posPerfPip')->label('Add PIP')->icon(Heroicon::OutlinedLifebuoy)
                ->visible($opens(fn () => ImprovementPlanResource::canAccess() && ImprovementPlanResource::canCreate()))
                ->url(fn () => ImprovementPlanResource::getUrl('index', ['action' => 'create'])),
            Action::make('posPerfRemind')->label('Remind reviewers')->icon(Heroicon::OutlinedBellAlert)
                ->visible($opens(fn () => $user()->can('performance.manage') && AppraisalReviewResource::canAccess()))
                ->url(fn () => AppraisalReviewResource::getUrl('index', ['view' => 'pending'])),
        ])->label('Performance actions')->icon(Heroicon::OutlinedSquares2x2)->button()->color('gray')
            ->visible(fn () => self::hrSide() || ($user()?->can('performance.manage') ?? false));
    }

    // --- Approval trail (goal plans, improvement plans) -------------------------------------------------

    public static function workflowRun(Model $record): ?WorkflowInstance
    {
        return WorkflowInstance::query()->with('workflow')->where('subject_type', $record->getMorphClass())->where('subject_id', $record->getKey())->latest('id')->first();
    }

    /** "Workflow · run #12 · Running", "Approval Center" while it waits there, or null once decided without a workflow. */
    public static function approvalLabel(Model $record, bool $waiting): ?string
    {
        $run = self::workflowRun($record);
        if ($run !== null) {
            return ($run->workflow?->name ?? 'Workflow').' · run #'.$run->id.' · '.$run->status->getLabel();
        }

        return $waiting ? 'Approval Center' : null;
    }

    public static function approvalUrl(Model $record, bool $waiting): ?string
    {
        try {
            $run = self::workflowRun($record);
            if ($run !== null) {
                return WorkflowInstanceResource::canView($run) ? WorkflowInstanceResource::getUrl('view', ['record' => $run]) : null;
            }

            return $waiting && Approvals::canAccess() ? Approvals::getUrl() : null;
        } catch (Throwable) {
            return null;
        }
    }

    // --- Reviews --------------------------------------------------------------------------------------

    /** Owner decision PER-1 / D-GO1: the setting is on, HR holds performance.manage, the review is submitted and the appraisal still open. */
    public static function mayReopen(AppraisalReview $review): bool
    {
        $user = auth()->user();
        if ($user === null || ! Appraisals::reopenEnabled() || ! $user->hasPermission('performance.manage') || ! $review->isSubmitted() || ! in_array($review->type, Appraisals::REOPENABLE, true)) {
            return false;
        }
        $appraisal = $review->loadMissing('appraisal.cycle')->appraisal;
        $me = app(PerformanceRelationships::class)->forUser($user);

        return $appraisal !== null && ! $appraisal->isFinal() && ! $appraisal->isLocked()
            && ($me === null || ((int) $me->id !== (int) $review->reviewer_id && (int) $me->id !== (int) $appraisal->employee_id));
    }

    public static function reopenReviewAction(): Action
    {
        return Action::make('reopenReview')->label('Reopen')->icon(Heroicon::OutlinedArrowUturnLeft)->color('warning')
            ->visible(fn (AppraisalReview $record) => self::mayReopen($record))
            ->modalHeading('Reopen this review')
            ->modalDescription('The review goes back to its reviewer to fill in again. The earlier submission stays in the audit trail and the reviewer is told. HR does not fill in the review.')
            ->schema([Textarea::make('reason')->label('Why is the review reopened?')->required()->maxLength(500)])
            ->action(fn (AppraisalReview $record, array $data) => PerformanceActions::run(fn () => app(Appraisals::class)->reopenReview($record, (string) $data['reason'], auth()->user()), 'Review reopened; the reviewer was told'));
    }

    public static function mayRemind(AppraisalReview $review): bool
    {
        $appraisal = $review->loadMissing('appraisal.cycle')->appraisal;

        return (auth()->user()?->hasPermission('performance.manage') ?? false) && $review->status === 'pending' && $review->reviewer_id !== null
            && $appraisal !== null && ! $appraisal->isFinal() && ! $appraisal->isLocked() && $appraisal->cycle?->status === 'active';
    }

    public static function remindReviewerAction(): Action
    {
        return Action::make('remindReviewer')->label('Remind')->icon(Heroicon::OutlinedBellAlert)->color('gray')
            ->visible(fn (AppraisalReview $record) => self::mayRemind($record))
            ->requiresConfirmation()->modalHeading('Remind the reviewer')
            ->modalDescription('The reviewer is reminded now (at most once a day for each review).')
            ->action(fn (AppraisalReview $record) => PerformanceActions::run(fn () => app(PerformanceReminders::class)->remindReviewer($record), fn (bool $sent) => $sent ? 'Reviewer reminded' : 'Already reminded today'));
    }

    // --- Goals and initiatives of one employee (HROne "Goals and initiative" flyer) ------------------------

    /**
     * @param  Closure(Model): ?Employee  $employee  the row's employee
     * @param  Closure(Model): ?int|null  $cycle  the cycle the drawer opens on (else the employee's latest)
     */
    public static function goalsAndInitiativesAction(Closure $employee, ?Closure $cycle = null): Action
    {
        $sheets = fn () => app(GoalSheets::class);

        return Action::make('goalsAndInitiatives')->label('Goals and initiatives')->icon(Heroicon::OutlinedRectangleStack)->color('gray')
            ->visible(fn (Model $record) => ($e = $employee($record)) !== null && auth()->user() !== null && GoalSheets::mayViewGoalsOf(auth()->user(), $e))
            ->slideOver()->modalSubmitAction(false)->modalCancelActionLabel('Close')
            ->modalHeading(fn (Model $record) => 'Goals and initiatives · '.($employee($record)?->person?->full_name ?? ''))
            ->fillForm(fn (Model $record) => ['cycle_id' => ($cycle !== null ? $cycle($record) : null) ?? array_key_first($sheets()->cyclesOf($employee($record)))])
            ->schema(fn (Model $record) => [
                Select::make('cycle_id')->label('Period (performance cycle)')->placeholder('Every cycle')->native(false)->live()
                    ->options(fn () => $sheets()->cyclesOf($employee($record))),
                Section::make('Goals')->schema([
                    TextEntry::make('drawer_goals')->hiddenLabel()->listWithLineBreaks()->placeholder('No goals in this period')
                        ->state(fn (Get $get) => self::goalLines($sheets()->goalsAndInitiatives(auth()->user(), $employee($record), self::cycle($get('cycle_id')))['goals'])),
                ]),
                Section::make('Initiatives')->schema([
                    TextEntry::make('drawer_initiatives')->hiddenLabel()->listWithLineBreaks()->placeholder('No initiatives in this period')
                        ->state(fn (Get $get) => self::initiativeLines($sheets()->goalsAndInitiatives(auth()->user(), $employee($record), self::cycle($get('cycle_id')))['initiatives'])),
                ]),
            ]);
    }

    private static function cycle(mixed $id): ?PerformanceCycle
    {
        return filled($id) ? PerformanceCycle::query()->find((int) $id) : null;
    }

    /** @return list<string> "Revenue · weight 60% · progress 40% · Active" and one line per KPI */
    public static function goalLines(?iterable $goals): array
    {
        $lines = [];
        foreach ($goals ?? [] as $goal) {
            /** @var Goal $goal */
            $lines[] = $goal->title.' · weight '.self::number($goal->weight).'% · progress '.self::number($goal->progress).'% · '.config("peopleos.performance.goal_statuses.{$goal->status}", $goal->status);
            foreach ($goal->keyResults as $kr) {
                $lines[] = '↳ '.$kr->title.($kr->weight !== null ? ' · weight '.self::number($kr->weight).'%' : '').($kr->target_value !== null ? ' · target '.self::number($kr->target_value) : '');
            }
        }

        return $lines;
    }

    /** @return list<string> "Buddy for joiners · due 2026-11-30 · 40% · In progress" */
    public static function initiativeLines(iterable $initiatives): array
    {
        $lines = [];
        foreach ($initiatives as $initiative) {
            /** @var Initiative $initiative */
            $lines[] = $initiative->title.($initiative->type?->name ? ' ('.$initiative->type->name.')' : '')
                .($initiative->due_date ? ' · due '.$initiative->due_date->toDateString() : '').' · '.(int) $initiative->progress.'% · '
                .config("peopleos.performance.initiative_statuses.{$initiative->status}", $initiative->status)
                .($initiative->goal?->title ? ' · goal: '.$initiative->goal->title : '');
        }

        return $lines;
    }

    public static function number(mixed $value): string
    {
        return $value === null ? '—' : rtrim(rtrim(number_format((float) $value, 2, '.', ''), '0'), '.');
    }
}
