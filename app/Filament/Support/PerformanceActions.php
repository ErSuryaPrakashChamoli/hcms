<?php

namespace App\Filament\Support;

use App\Domain\Employment\Models\Employee;
use App\Domain\Performance\Models\Appraisal;
use App\Domain\Performance\Models\AppraisalReview;
use App\Domain\Performance\Models\Competency;
use App\Domain\Performance\Models\Goal;
use App\Domain\Performance\Models\GoalCheckIn;
use App\Domain\Performance\Models\GoalTemplate;
use App\Domain\Performance\Models\PerformanceCycle;
use App\Domain\Performance\Models\PerformancePeriodReview;
use App\Domain\Performance\Models\RatingScale;
use App\Domain\Performance\Policies\EmployeeOwnedPolicy;
use App\Domain\Performance\Services\Appraisals;
use App\Domain\Performance\Services\Goals;
use App\Domain\Performance\Services\GoalSheetRules;
use App\Domain\Performance\Services\GoalTemplates;
use App\Domain\Performance\Services\KpiRatings;
use App\Domain\Performance\Services\PerformanceDistribution;
use App\Domain\Performance\Services\PerformancePolicyRules;
use App\Domain\Performance\Services\PerformanceRelationships;
use App\Domain\Performance\Services\PeriodicReviews;
use App\Domain\Performance\Support\AppraisalVisibility;
use App\Domain\Performance\Support\PerformanceLabels;
use App\Domain\Performance\Support\RatingRules;
use App\Domain\Platform\Services\SettingsRepository;
use Filament\Actions\Action;
use Filament\Forms\Components\Checkbox;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Component;
use Filament\Schemas\Components\Section;
use Filament\Support\Icons\Heroicon;
use RuntimeException;
use Throwable;

/** Review, calibration, finalization and goal check-in actions shared across pages. */
final class PerformanceActions
{
    public static function me(): ?Employee
    {
        return EmployeeOwnedPolicy::employeeOf(auth()->user());
    }

    /** @return array<int, Action> */
    public static function forCycle(): array
    {
        return [
            Action::make('schedule')->label('Schedule')->icon(Heroicon::OutlinedCalendarDays)->color('gray')
                ->visible(fn (PerformanceCycle $record) => $record->status === 'draft' && auth()->user()->can('performance.manage'))
                ->schema([DatePicker::make('scheduled_for')->label('Launch on')->required()->native(false)])
                ->modalDescription('Freezes the cycle configuration until launch.')
                ->action(fn (PerformanceCycle $record, array $data) => self::run(fn () => app(Appraisals::class)->schedule($record, $data['scheduled_for'], auth()->user()), 'Cycle scheduled')),
            Action::make('launch')->label('Launch cycle')->icon(Heroicon::OutlinedRocketLaunch)->color('success')
                ->visible(fn (PerformanceCycle $record) => in_array($record->status, ['draft', 'scheduled'], true) && auth()->user()->can('performance.manage'))
                ->requiresConfirmation()->modalDescription(fn (PerformanceCycle $record) => 'Pins the template version and creates an appraisal for every eligible employee ('.app(Appraisals::class)->eligible($record)->count().'), then opens the first stage. The configuration cannot change afterwards.')
                ->action(fn (PerformanceCycle $record) => self::run(fn () => app(Appraisals::class)->launch($record, auth()->user()), 'Cycle launched')),
            Action::make('advance')->label(fn (PerformanceCycle $record) => $record->nextStage() ? 'Move to '.config('peopleos.performance.stages.'.$record->nextStage()) : 'Close cycle')->icon(Heroicon::OutlinedForward)->color('primary')
                ->visible(fn (PerformanceCycle $record) => $record->status === 'active' && auth()->user()->can('performance.manage'))
                ->requiresConfirmation()
                // HP-8g: closing shows how the ratings compare with the bell curve targets when the check is on.
                ->modalDescription(fn (PerformanceCycle $record) => $record->nextStage() === null ? app(PerformanceDistribution::class)->warning($record) : null)
                ->action(fn (PerformanceCycle $record) => self::run(fn () => app(Appraisals::class)->advance($record, auth()->user()), fn (PerformanceCycle $c) => $c->status === 'closed' ? self::withDistributionWarning($c, 'Cycle closed') : 'Now at '.config("peopleos.performance.stages.{$c->current_stage}"))),
            // HP-8b: manual publishing — show the finalized ratings of the cycle to the employees.
            Action::make('publishRatings')->label('Publish ratings')->icon(Heroicon::OutlinedMegaphone)->color('success')
                ->visible(fn (PerformanceCycle $record) => $record->status === 'active' && auth()->user()->can('performance.calibrate') && self::unpublished($record) > 0)
                ->requiresConfirmation()->modalDescription(fn (PerformanceCycle $record) => self::unpublished($record).' finalized rating(s) will be shown to the employees, who are told.')
                ->action(fn (PerformanceCycle $record) => self::run(fn () => app(Appraisals::class)->publish($record, null, auth()->user()), fn (int $n) => "{$n} rating(s) published")),
            // HROne setup parity HP-8i: open a period's periodic reviews by hand (performance policy: manual initiation).
            Action::make('initiatePeriodicReview')->label('Initiate periodic review')->icon(Heroicon::OutlinedPlayCircle)->color('gray')
                ->visible(fn (PerformanceCycle $record) => $record->status === 'active' && PerformancePeriodReview::query()->where('performance_cycle_id', $record->id)->where('status', 'scheduled')->exists())
                ->schema(fn (PerformanceCycle $record) => [
                    Select::make('sequence')->label('Review period')->required()->native(false)
                        ->options(PerformancePeriodReview::query()->where('performance_cycle_id', $record->id)->where('status', 'scheduled')->orderBy('sequence')->get()
                            ->mapWithKeys(fn (PerformancePeriodReview $r) => [$r->sequence => '#'.$r->sequence.' · '.$r->label()])->all()),
                    Select::make('employee_ids')->label('Employees (blank = everyone in the period)')->multiple()->searchable()
                        ->options(fn () => $record->appraisals()->with('employee.person')->get()->mapWithKeys(fn ($a) => [$a->employee_id => $a->employee?->employee_code.' · '.$a->employee?->person?->full_name])->all()),
                ])
                ->action(fn (PerformanceCycle $record, array $data) => self::run(fn () => app(PeriodicReviews::class)->initiate($record, (int) $data['sequence'], ($data['employee_ids'] ?? []) === [] ? null : array_map('intval', $data['employee_ids']), auth()->user()), fn (int $n) => "{$n} periodic review(s) opened")),
            Action::make('archive')->label('Archive')->icon(Heroicon::OutlinedArchiveBox)->color('gray')
                ->visible(fn (PerformanceCycle $record) => $record->status === 'closed' && auth()->user()->can('performance.manage'))
                ->requiresConfirmation()->modalDescription('An archived cycle is read-only.')
                ->action(fn (PerformanceCycle $record) => self::run(fn () => app(Appraisals::class)->archive($record, auth()->user()), 'Cycle archived')),
        ];
    }

    /** @return array<int, Action> */
    public static function forAppraisal(): array
    {
        $user = fn () => auth()->user();

        return [
            Action::make('selfReview')->label('Self review')->icon(Heroicon::OutlinedPencilSquare)->color('primary')
                ->visible(fn (Appraisal $record) => ($r = $record->review('self')) && ! $r->isSubmitted() && $r->reviewer_id === self::me()?->id && ! $record->isFinal())
                ->schema(fn (Appraisal $record) => self::reviewForm($record, true, 'self'))
                ->action(fn (Appraisal $record, array $data) => self::submit($record->review('self'), $data)),
            Action::make('managerReview')->label('Manager review')->icon(Heroicon::OutlinedPencilSquare)->color('primary')
                ->visible(fn (Appraisal $record) => ($r = $record->review('manager')) && ! $r->isSubmitted() && ($r->reviewer_id === self::me()?->id || $user()->can('performance.manage')) && ! $record->isFinal())
                ->schema(fn (Appraisal $record) => self::reviewForm($record, true, 'manager'))
                ->action(fn (Appraisal $record, array $data) => self::submit($record->review('manager'), $data)),
            // HP-8d: the approver (third review level) of cycles with an "Approver review" stage.
            Action::make('approverReview')->label('Approver review')->icon(Heroicon::OutlinedPencilSquare)->color('primary')
                ->visible(fn (Appraisal $record) => ($r = $record->review('approver')) && ! $r->isSubmitted() && ($r->reviewer_id === self::me()?->id || $user()->can('performance.manage')) && ! $record->isFinal())
                ->schema(fn (Appraisal $record) => self::reviewForm($record, true, 'approver'))
                ->action(fn (Appraisal $record, array $data) => self::submit($record->review('approver'), $data)),
            Action::make('peerReview')->label('My peer review')->icon(Heroicon::OutlinedPencilSquare)->color('primary')
                ->visible(fn (Appraisal $record) => self::pendingPeer($record) !== null && ! $record->isFinal())
                ->schema(fn (Appraisal $record) => self::reviewForm($record, false))
                ->action(fn (Appraisal $record, array $data) => self::submit(self::pendingPeer($record), $data)),
            Action::make('addPeer')->label('Add reviewer')->icon(Heroicon::OutlinedUserPlus)->color('gray')
                ->visible(fn (Appraisal $record) => ! $record->isFinal() && ($user()->can('performance.manage') || $record->manager_id === self::me()?->id))
                ->schema(fn (Appraisal $record) => [
                    Select::make('reviewer_id')->label('Reviewer')->required()->searchable()->options(fn () => Employee::query()->with('person')->employed()->get()->mapWithKeys(fn ($e) => [$e->id => "{$e->employee_code} · {$e->person?->full_name}"])->all()),
                    Select::make('type')->options(collect(config('peopleos.performance.review_types'))->except(['self', 'manager', 'approver'])->all())->default('peer')->required(),
                    // HP-8b: the cycle's "Peer reviews anonymous by default" option.
                    Checkbox::make('is_anonymous')->label('Anonymous to the employee')->default(fn () => (bool) $record->cycle?->setting('peer_anonymous', false)),
                ])
                ->action(fn (Appraisal $record, array $data) => self::run(fn () => app(Appraisals::class)->addPeerReview($record, Employee::query()->findOrFail($data['reviewer_id']), $data['type'], (bool) ($data['is_anonymous'] ?? false)), 'Reviewer added')),
            Action::make('calibrate')->label('Calibrate')->icon(Heroicon::OutlinedAdjustmentsVertical)->color('warning')
                ->visible(fn (Appraisal $record) => ! $record->isFinal() && $user()->can('performance.calibrate'))
                ->schema(fn (Appraisal $record) => [
                    self::ratingField('rating', 'Calibrated rating', $record)->required(),
                    Textarea::make('note')->required()->maxLength(500),
                    // Optimistic concurrency: the rating this person saw when opening the form.
                    Hidden::make('expected')->default(fn () => $record->effectiveRating()),
                ])
                ->action(fn (Appraisal $record, array $data) => self::run(fn () => app(Appraisals::class)->calibrate($record, (float) $data['rating'], $data['note'], auth()->user(), isset($data['expected']) && $data['expected'] !== '' ? (float) $data['expected'] : null), 'Calibrated')),
            Action::make('finalize')->label('Finalize')->icon(Heroicon::OutlinedCheckBadge)->color('success')
                ->visible(fn (Appraisal $record) => ! $record->isFinal() && $user()->can('performance.calibrate'))
                ->schema(fn (Appraisal $record) => [
                    self::ratingField('rating', 'Final rating', $record)->placeholder('Use computed / calibrated rating'),
                    Textarea::make('summary')->label('Summary for the employee')->maxLength(1000),
                    // HP-8i: starts from the reviewers' promotion recommendation (performance policy), when there is one.
                    Checkbox::make('promotion')->label('Recommend for promotion')->default((bool) ($record->recommendations['promotion'] ?? false)),
                    Checkbox::make('pip')->label('Recommend an improvement plan'),
                ])
                ->action(fn (Appraisal $record, array $data) => self::run(fn () => app(Appraisals::class)->finalize($record, auth()->user(), isset($data['rating']) && $data['rating'] !== null && $data['rating'] !== '' ? (float) $data['rating'] : null, $data['summary'] ?? null, (bool) ($data['promotion'] ?? false), (bool) ($data['pip'] ?? false)),
                    // HP-8g: with the distribution check on, the gap to the bell curve targets is shown after finalizing.
                    fn (Appraisal $a) => self::withDistributionWarning($a, 'Appraisal finalized'))),
            Action::make('publishRating')->label('Publish rating')->icon(Heroicon::OutlinedMegaphone)->color('success')
                ->visible(fn (Appraisal $record) => $record->isFinal() && ! $record->isPublished() && $user()->can('performance.calibrate'))
                ->requiresConfirmation()->modalDescription('The employee sees the rating, the summary and the reviews by others, and is told.')
                ->action(fn (Appraisal $record) => self::run(fn () => app(Appraisals::class)->publish($record->cycle, [$record->id], auth()->user()), fn (int $n) => $n > 0 ? 'Rating published' : 'Already published')),
            // HP-8b: the employee acknowledges a published rating.
            Action::make('acknowledge')->label('Acknowledge')->icon(Heroicon::OutlinedHandThumbUp)->color('success')
                ->visible(fn (Appraisal $record) => $record->status === 'finalized' && $record->isPublished() && $record->employee_id === self::me()?->id)
                ->schema([Textarea::make('comment')->maxLength(500)])
                ->action(fn (Appraisal $record, array $data) => self::run(fn () => app(Appraisals::class)->acknowledge($record, $data['comment'] ?? null), 'Acknowledged')),
        ];
    }

    /**
     * HP-8g: when the distribution check is on (warn / block) and the cycle's ratings differ from its bell curve targets
     * by more than the tolerance, a warning notification shows the gap. Returns $title for the success notification.
     */
    public static function withDistributionWarning(Appraisal|PerformanceCycle $subject, string $title): string
    {
        $distribution = app(PerformanceDistribution::class);
        if ($distribution->mode() === 'off') {
            return $title;
        }
        $cycle = $subject instanceof Appraisal ? $subject->cycle()->first() : $subject;
        if ($cycle !== null && ($warning = $distribution->warning($cycle)) !== null) {
            Notification::make()->warning()->title('Rating distribution outside the targets')->body($warning)->persistent()->send();
        }

        return $title;
    }

    /** Finalized ratings of a cycle not yet published (HP-8b). */
    public static function unpublished(PerformanceCycle $cycle): int
    {
        return $cycle->appraisals()->whereIn('status', ['finalized', 'acknowledged'])->whereNull('published_at')->count();
    }

    /**
     * HP-8b: the calibrated / final rating field — a scale level (performance.rating.final_decimals = 0, as before) or
     * a number with one or two decimals; it starts from the effective rating rounded by performance.rating.rounding.
     */
    public static function ratingField(string $name, string $label, Appraisal $record): Select|TextInput
    {
        $scale = $record->cycle->ratingScale();
        $default = RatingRules::defaultFor($scale, $record->effectiveRating());
        if (RatingRules::decimals() === 0) {
            return Select::make($name)->label($label)->options($scale->options())->default($default);
        }

        return TextInput::make($name)->label($label)->numeric()->step(RatingRules::decimals() === 1 ? 0.1 : 0.01)
            ->minValue($scale->min())->maxValue($scale->max())->default($default)
            ->helperText("Between {$scale->min()} and {$scale->max()}, up to ".RatingRules::decimals().' decimal(s).');
    }

    private static function pendingPeer(Appraisal $record): ?AppraisalReview
    {
        $me = self::me();

        return $me ? $record->reviews()->whereNotIn('type', ['self', 'manager'])->where('reviewer_id', $me->id)->where('status', 'pending')->first() : null;
    }

    /**
     * Goals of the employee in this cycle + the cycle's competencies, each with a rating select and comment.
     * HP-8c: competencies use the cycle's competency scale; with performance.review.show_formula_rating, each goal shows
     * the rating its KPI rating formula gives (suggested, filled in or fixed by formula_rating_mode); reverse KPIs are
     * marked when performance.review.show_reverse_rating is on. HP-8d ($type self / manager / approver): the employee's
     * goal sheet policy decides whether each KPI is rated (rolling up into its KRA), whether an achievement % is entered
     * instead of a rating, whether evidence is uploaded, which competencies are rated, and whether the form starts from
     * the previous level's inputs.
     */
    public static function reviewForm(Appraisal $record, bool $withGoals = true, ?string $type = null): array
    {
        $record->loadMissing(['cycle.scale', 'employee']);
        $scale = $record->cycle->ratingScale();
        // HP-8i: the performance policy may allow goal ratings above the scale's maximum (whole numbers up to its limit).
        $policyRules = app(PerformancePolicyRules::class);
        $options = self::aboveMax($scale->options(), $scale->max(), $policyRules->maxRating($record, $scale));
        $components = [];
        $sheet = app(GoalSheetRules::class);
        $config = $sheet->reviewConfig($record, $type);
        $previous = self::previousInputs($record, $type);

        if ($withGoals) {
            $goals = app(Goals::class)->forEmployee($record->employee, $record->performance_cycle_id)->where('status', '!=', 'cancelled');
            $components[] = Section::make('Goals')->columns(2)->schema($goals->flatMap(fn (Goal $g) => self::goalReviewFields($g, $options, $scale, $config, $previous))->all())->visible($goals->isNotEmpty());
        }

        $competencyScale = $record->cycle->competencyScale();
        $competencyOptions = self::aboveMax($competencyScale->options(), $competencyScale->max(), $policyRules->maxRating($record, $competencyScale, 'competency'));
        $competencies = $sheet->competenciesFor($record);
        $components[] = Section::make(PerformanceLabels::plural('competency', 'Competencies'))->columns(2)->schema($competencies->flatMap(fn ($c) => [
            Select::make("competencies.{$c->id}")->label($c->name)->options($competencyOptions)->required()->default($previous['competencies'][$c->id] ?? null),
            TextInput::make("competency_comments.{$c->id}")->label('Comment')->maxLength(255)->default($previous['competency_comments'][$c->id] ?? null),
        ])->all())->visible($competencies->isNotEmpty() || $sheet->includesCompetencies($record));

        $components[] = Section::make('Overall')->columns(1)->schema([
            Select::make('overall')->label('Overall rating')->options($scale->options())->required(),
            Textarea::make('strengths')->maxLength(1000),
            Textarea::make('improvements')->label('Areas to develop')->maxLength(1000),
            Textarea::make('comments')->maxLength(1000),
        ]);
        // HP-8i: the recommendations the performance policy enables, with the manager and approver reviews.
        if (in_array($type, ['manager', 'approver'], true) && $policyRules->recommends($record)) {
            $recommend = $policyRules->recommendationOptions($record);
            $current = (array) ($record->recommendations ?? []);
            $amount = $recommend['increment_type'] === 'amount';
            $components[] = Section::make('Recommendations')->columns(2)->schema(array_values(array_filter([
                $recommend['training'] ? Select::make('recommendations.training_competencies')->label('Training needs (TNI): '.mb_strtolower(PerformanceLabels::plural('competency', 'Competencies')))->multiple()
                    ->options(Competency::query()->where('status', 'active')->orderBy('name')->pluck('name', 'id')->all())->default($current['training']['competency_ids'] ?? []) : null,
                $recommend['training'] ? TextInput::make('recommendations.training_note')->label('Training needs: note')->maxLength(500)->default($current['training']['note'] ?? null) : null,
                $recommend['promotion'] ? Checkbox::make('recommendations.promotion')->label('Recommend for promotion')->default((bool) ($current['promotion'] ?? false)) : null,
                $recommend['increment'] ? TextInput::make('recommendations.increment')->label('Salary increment '.($amount ? '(amount)' : '(%)'))->numeric()->minValue(0)->maxValue($amount ? null : 100)->default($current['increment']['value'] ?? null) : null,
                $recommend['variable'] ? TextInput::make('recommendations.variable')->label('Variable pay '.($amount ? '(amount)' : '(%)'))->numeric()->minValue(0)->maxValue($amount ? null : 100)->default($current['variable']['value'] ?? null) : null,
            ])));
        }
        if ($config['evidence'] !== 'off') {
            $components[] = Section::make('Evidence')->schema([
                FileUpload::make('evidence')->label('Upload evidence')->multiple()->maxFiles(5)->disk(config('peopleos.documents.disk', 'local'))->directory('performance-evidence')->visibility('private')
                    ->acceptedFileTypes(['image/jpeg', 'image/png', 'application/pdf'])->maxSize((int) config('peopleos.documents.max_kb', 10240))->required($config['evidence'] === 'required'),
            ]);
        }

        return $components;
    }

    /**
     * HP-8d: one goal's fields on the review form — a rating (or an achievement %) and a comment, or one per KPI when the
     * review level is "KPI" and the goal has key results.
     *
     * @param  array<int|string, string>  $options
     * @param  array{level: string, input: string, evidence: string}  $config
     * @return list<Component>
     */
    private static function goalReviewFields(Goal $goal, array $options, RatingScale $scale, array $config, array $previous): array
    {
        $percent = $config['input'] === 'percentage';
        if ($config['level'] === 'key_result' && $goal->keyResults->isNotEmpty()) {
            return $goal->keyResults->flatMap(fn ($kr) => [
                $percent
                    ? TextInput::make("key_result_pcts.{$kr->id}")->label("{$goal->title} · {$kr->title}: ".mb_strtolower(PerformanceLabels::get('achievement')).' %')->numeric()->minValue(0)->maxValue(1000)->suffix('%')->required()->default($previous['key_result_pcts'][$kr->id] ?? null)
                    : Select::make("key_results.{$kr->id}")->label("{$goal->title} · {$kr->title} (weight {$kr->weight})")->options($options)->required()->default($previous['key_results'][$kr->id] ?? null),
                TextInput::make("key_result_comments.{$kr->id}")->label('Comment')->maxLength(255)->default($previous['key_result_comments'][$kr->id] ?? null),
            ])->all();
        }
        $field = $percent
            ? TextInput::make("goal_pcts.{$goal->id}")->label("{$goal->title} (weight {$goal->weight}): ".mb_strtolower(PerformanceLabels::get('achievement')).' %')->numeric()->minValue(0)->maxValue(1000)->suffix('%')->required()->default($previous['goal_pcts'][$goal->id] ?? null)
            : self::goalRatingField($goal, $options, $scale, $previous['goals'][$goal->id] ?? null);

        return [$field, TextInput::make("goal_comments.{$goal->id}")->label('Comment')->maxLength(255)->default($previous['goal_comments'][$goal->id] ?? null)];
    }

    /**
     * HP-8d (copy last level review inputs): the ratings, percentages and comments of the previous submitted level
     * (self → manager → approver) for the next level's form, as the goal sheet policy (or the organisation setting) says.
     * The self review is never copied to a manager the cycle hides it from.
     *
     * @return array<string, array<int, mixed>>
     */
    public static function previousInputs(Appraisal $record, ?string $type): array
    {
        $order = ['self', 'manager', 'approver'];
        $at = array_search($type, $order, true);
        if ($at === false || $at === 0) {
            return [];
        }
        $sheet = app(GoalSheetRules::class);
        $goals = $sheet->copiesInputs($record, 'goal');
        $competencies = $sheet->copiesInputs($record, 'competency');
        if (! $goals && ! $competencies) {
            return [];
        }
        $source = null;
        for ($i = $at - 1; $i >= 0 && $source === null; $i--) {
            $review = $record->review($order[$i]);
            if ($review?->isSubmitted() && ($order[$i] !== 'self' || (bool) $record->cycle?->setting('show_self_to_manager', true))) {
                $source = $review;
            }
        }
        // HP-8i: nothing is copied from a level whose ratings and comments the performance policy keeps from this reviewer.
        if ($source === null || ! AppraisalVisibility::reviewVisible($record, $source, auth()->user())) {
            return [];
        }
        $key = fn ($value) => rtrim(rtrim(number_format((float) $value, 2, '.', ''), '0'), '.');
        $out = [];
        foreach ($source->ratings()->get() as $rating) {
            $id = (int) $rating->subject_id;
            if ($rating->subject_type === 'competency' && $competencies) {
                $out['competencies'][$id] = $key($rating->rating);
                $out['competency_comments'][$id] = $rating->comment;
            } elseif ($rating->subject_type === 'goal' && $goals) {
                $out['goals'][$id] = $key($rating->rating);
                $out['goal_comments'][$id] = $rating->comment;
                if ($rating->achievement_pct !== null) {
                    $out['goal_pcts'][$id] = (float) $rating->achievement_pct;
                }
            } elseif ($rating->subject_type === 'key_result' && $goals) {
                $out['key_results'][$id] = $key($rating->rating);
                $out['key_result_comments'][$id] = $rating->comment;
                if ($rating->achievement_pct !== null) {
                    $out['key_result_pcts'][$id] = (float) $rating->achievement_pct;
                }
            }
        }

        return $out;
    }

    /**
     * HP-8c: one goal's rating select on the review form — with the KPI formula rating as a hint (suggest), filled in
     * (prefill) or fixed (lock) when performance.review.show_formula_rating is on, and "lower is better" on reverse
     * KPIs when performance.review.show_reverse_rating is on.
     *
     * @param  array<int|string, string>  $options
     */
    public static function goalRatingField(Goal $goal, array $options, RatingScale $scale, ?string $copied = null): Select
    {
        $kpi = app(KpiRatings::class);
        $reverse = $goal->is_reverse && app(SettingsRepository::class)->get('performance.review.show_reverse_rating', false);
        $progress = mb_strtolower(PerformanceLabels::get('achievement', 'progress'));
        $select = Select::make("goals.{$goal->id}")->label("{$goal->title} (weight {$goal->weight}, {$progress} {$goal->progress}%)".($reverse ? ' · lower is better' : ''))->options($options)->required();
        if ($copied !== null && array_key_exists($copied, $options)) {
            // HP-8d: the previous level's rating, unless the KPI formula fills the rating in.
            $select->default($copied);
        }
        $suggestion = $kpi->enabled() ? $kpi->suggest($goal, $scale) : null;
        if ($suggestion === null) {
            return $select;
        }
        $key = rtrim(rtrim(number_format($suggestion['rating'], 2, '.', ''), '0'), '.');
        $select->helperText(self::formulaHint($suggestion));
        if (array_key_exists($key, $options) && in_array($kpi->mode(), ['prefill', 'lock'], true)) {
            $select->default($key);
            if ($kpi->mode() === 'lock') {
                $select->disabled()->dehydrated();
            }
        }

        return $select;
    }

    /** @param  array{pct: float, rating: float}|null  $suggestion  KpiRatings::suggest() */
    public static function formulaHint(?array $suggestion): ?string
    {
        if ($suggestion === null) {
            return null;
        }
        $rating = rtrim(rtrim(number_format($suggestion['rating'], 2, '.', ''), '0'), '.');

        return PerformanceLabels::get('kpi')." formula rating: {$rating} (".mb_strtolower(PerformanceLabels::get('achievement'))." {$suggestion['pct']}%)";
    }

    private static function submit(?AppraisalReview $review, array $data): void
    {
        if ($review === null) {
            Notification::make()->danger()->title('No review to submit')->send();

            return;
        }
        self::run(fn () => app(Appraisals::class)->submitReview(
            $review,
            collect($data['goals'] ?? [])->map(fn ($v) => (float) $v)->all(),
            collect($data['competencies'] ?? [])->map(fn ($v) => (float) $v)->all(),
            isset($data['overall']) ? (float) $data['overall'] : null,
            ['goal_comments' => $data['goal_comments'] ?? [], 'competency_comments' => $data['competency_comments'] ?? [], 'strengths' => $data['strengths'] ?? null, 'improvements' => $data['improvements'] ?? null, 'comments' => $data['comments'] ?? null,
                // HP-8d: achievement percentages, KPI ratings and evidence (goal sheet policy review configuration).
                'goal_pcts' => $data['goal_pcts'] ?? [], 'key_result_ratings' => $data['key_results'] ?? [], 'key_result_pcts' => $data['key_result_pcts'] ?? [],
                'key_result_comments' => $data['key_result_comments'] ?? [], 'evidence' => array_values((array) ($data['evidence'] ?? [])),
                // HP-8i: recommendations (performance policy).
                'recommendations' => (array) ($data['recommendations'] ?? [])],
            auth()->user(),
        ), 'Review submitted');
    }

    /** @return array<int, Action> */
    public static function forGoal(): array
    {
        return [
            Action::make('checkIn')->label('Check in')->icon(Heroicon::OutlinedChartBar)->color('primary')
                ->visible(fn (Goal $record) => $record->isOpen() && auth()->user()->can('update', $record))
                ->schema(function (Goal $record) {
                    $krs = $record->keyResults()->get();

                    return [
                        Select::make('key_result_id')->label(PerformanceLabels::get('kpi', 'Key result'))->options($krs->pluck('title', 'id')->all())->placeholder('The goal itself')->visible($krs->isNotEmpty()),
                        TextInput::make('value')->label(PerformanceLabels::get('achievement', 'Current value'))->numeric()->required(),
                        Select::make('confidence')->options(GoalCheckIn::CONFIDENCE)->default('on_track'),
                        TextInput::make('measurement')->label('How it was measured')->maxLength(255),
                        Textarea::make('note')->maxLength(500),
                    ];
                })
                ->action(function (Goal $record, array $data) {
                    $subject = ! empty($data['key_result_id']) ? $record->keyResults()->findOrFail($data['key_result_id']) : $record;
                    self::run(fn () => app(Goals::class)->checkIn($subject, (float) $data['value'], $data['note'] ?? null, $data['confidence'] ?? null, auth()->user(), 'manual', $data['measurement'] ?? null), 'Progress updated');
                }),
            Action::make('close')->label('Close')->icon(Heroicon::OutlinedCheckCircle)->color('gray')
                // Owner decision 2026-10-29: the reporting manager closes the goals of a system-mapped goal sheet.
                ->visible(fn (Goal $record) => $record->isOpen() && auth()->user()->can('update', $record) && ! app(Goals::class)->proposesFor($record, auth()->user()))
                ->schema([Select::make('status')->options(['completed' => 'Completed', 'cancelled' => 'Cancelled'])->required(), Textarea::make('reason')->maxLength(255)])
                ->action(fn (Goal $record, array $data) => self::run(fn () => app(Goals::class)->close($record, $data['status'], $data['reason'] ?? null), 'Goal closed')),
        ];
    }

    /**
     * HP-8d / HP-8e: submit a goal sheet (the goal sheet policy's checks and approval apply), and "Use template" — create
     * goals from a goal template for oneself or a report.
     *
     * @return array<int, Action>
     */
    public static function forGoalSheet(): array
    {
        $mayPlan = fn () => auth()->user()->can('performance.goals') || auth()->user()->can('performance.team') || auth()->user()->can('performance.manage');
        $cycles = fn () => PerformanceCycle::query()->whereIn('status', ['draft', 'scheduled', 'active'])->orderByDesc('period_start')->pluck('name', 'id')->all();
        $activeCycle = fn () => PerformanceCycle::query()->where('status', 'active')->orderByDesc('period_start')->value('id');

        return [
            Action::make('submitGoalSheet')->label('Submit goal sheet')->icon(Heroicon::OutlinedPaperAirplane)->color('gray')->visible($mayPlan)
                ->modalDescription('The goal sheet is checked (weightage and number of KRAs); its draft goals become active, or wait for approval when the goal sheet policy asks for it. A goal sheet mapped by the system always waits for the reporting manager to confirm the employee\'s changes.')
                ->schema([self::sheetEmployeeField(), Select::make('performance_cycle_id')->label('Cycle')->required()->options($cycles)->default($activeCycle)])
                ->action(fn (array $data) => self::run(fn () => app(Goals::class)->submitPlan(Employee::query()->findOrFail($data['employee_id']), (int) $data['performance_cycle_id'], auth()->user()), fn (int $n) => "{$n} goal(s) submitted")),
            self::useGoalTemplate(),
        ];
    }

    public static function useGoalTemplate(): Action
    {
        return Action::make('useGoalTemplate')->label('Use template')->icon(Heroicon::OutlinedDocumentDuplicate)->color('gray')
            ->visible(fn () => (auth()->user()->can('performance.goals') || auth()->user()->can('performance.team') || auth()->user()->can('performance.manage')) && GoalTemplate::query()->where('status', 'active')->exists())
            ->modalDescription('Adds the template\'s KRAs and KPIs as draft goals (KRAs already on the goal sheet are skipped).')
            ->schema([
                Select::make('goal_template_id')->label('Goal template')->required()->native(false)
                    ->options(fn () => GoalTemplate::query()->where('status', 'active')->orderBy('sort_order')->orderBy('name')->pluck('name', 'id')->all()),
                self::sheetEmployeeField(),
                Select::make('performance_cycle_id')->label('Cycle')->placeholder('Not tied to a cycle')
                    ->options(fn () => PerformanceCycle::query()->whereIn('status', ['draft', 'scheduled', 'active'])->orderByDesc('period_start')->pluck('name', 'id')->all())
                    ->default(fn () => PerformanceCycle::query()->where('status', 'active')->orderByDesc('period_start')->value('id')),
            ])
            ->action(fn (array $data) => self::run(fn () => app(GoalTemplates::class)->assign(
                GoalTemplate::query()->findOrFail($data['goal_template_id']), Employee::query()->findOrFail($data['employee_id']),
                filled($data['performance_cycle_id'] ?? null) ? PerformanceCycle::query()->findOrFail($data['performance_cycle_id']) : null, auth()->user(),
            )->count(), fn (int $n) => "{$n} goal(s) added from the template"));
    }

    /** The employee whose goal sheet an action works on: oneself, or (managers, HR) a report or anyone. */
    private static function sheetEmployeeField(): Select
    {
        return Select::make('employee_id')->label('Employee')->required()->searchable()->default(fn () => self::me()?->id)
            ->options(function () {
                $user = auth()->user();
                $query = Employee::query()->with('person')->employed();
                if (! $user->can('performance.manage')) {
                    $me = self::me();
                    $query->whereIn('id', collect([$me?->id])->merge($me && $user->can('performance.team') ? app(PerformanceRelationships::class)->reportIds($me) : [])->filter());
                }

                return $query->get()->mapWithKeys(fn ($e) => [$e->id => "{$e->employee_code} · {$e->person?->full_name}"])->all();
            });
    }

    /**
     * HP-8i: rating options above the scale's maximum, in whole steps up to the performance policy's limit.
     *
     * @param  array<int|string, string>  $options
     * @return array<int|string, string>
     */
    public static function aboveMax(array $options, float $scaleMax, float $allowed): array
    {
        for ($value = (int) floor($scaleMax) + 1; $value <= $allowed; $value++) {
            $options[(string) $value] = $value.' (above the scale)';
        }

        return $options;
    }

    public static function run(callable $callback, string|callable $success): void
    {
        try {
            $result = $callback();
            Notification::make()->success()->title(is_callable($success) ? $success($result) : $success)->send();
        } catch (RuntimeException $e) {
            Notification::make()->danger()->title('Not allowed')->body($e->getMessage())->persistent()->send();
        } catch (Throwable $e) {
            report($e);
            Notification::make()->danger()->title('Failed')->body($e->getMessage())->persistent()->send();
        }
    }
}
