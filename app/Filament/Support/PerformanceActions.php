<?php

namespace App\Filament\Support;

use App\Domain\Employment\Models\Employee;
use App\Domain\Performance\Models\Appraisal;
use App\Domain\Performance\Models\AppraisalReview;
use App\Domain\Performance\Models\Goal;
use App\Domain\Performance\Models\GoalCheckIn;
use App\Domain\Performance\Models\PerformanceCycle;
use App\Domain\Performance\Policies\EmployeeOwnedPolicy;
use App\Domain\Performance\Services\Appraisals;
use App\Domain\Performance\Services\Goals;
use Filament\Actions\Action;
use Filament\Forms\Components\Checkbox;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
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
                ->action(fn (PerformanceCycle $record) => self::run(fn () => app(Appraisals::class)->advance($record, auth()->user()), fn (PerformanceCycle $c) => $c->status === 'closed' ? 'Cycle closed' : 'Now at '.config("peopleos.performance.stages.{$c->current_stage}"))),
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
                ->schema(fn (Appraisal $record) => self::reviewForm($record))
                ->action(fn (Appraisal $record, array $data) => self::submit($record->review('self'), $data)),
            Action::make('managerReview')->label('Manager review')->icon(Heroicon::OutlinedPencilSquare)->color('primary')
                ->visible(fn (Appraisal $record) => ($r = $record->review('manager')) && ! $r->isSubmitted() && ($r->reviewer_id === self::me()?->id || $user()->can('performance.manage')) && ! $record->isFinal())
                ->schema(fn (Appraisal $record) => self::reviewForm($record))
                ->action(fn (Appraisal $record, array $data) => self::submit($record->review('manager'), $data)),
            Action::make('peerReview')->label('My peer review')->icon(Heroicon::OutlinedPencilSquare)->color('primary')
                ->visible(fn (Appraisal $record) => self::pendingPeer($record) !== null && ! $record->isFinal())
                ->schema(fn (Appraisal $record) => self::reviewForm($record, false))
                ->action(fn (Appraisal $record, array $data) => self::submit(self::pendingPeer($record), $data)),
            Action::make('addPeer')->label('Add reviewer')->icon(Heroicon::OutlinedUserPlus)->color('gray')
                ->visible(fn (Appraisal $record) => ! $record->isFinal() && ($user()->can('performance.manage') || $record->manager_id === self::me()?->id))
                ->schema([
                    Select::make('reviewer_id')->label('Reviewer')->required()->searchable()->options(fn () => Employee::query()->with('person')->employed()->get()->mapWithKeys(fn ($e) => [$e->id => "{$e->employee_code} · {$e->person?->full_name}"])->all()),
                    Select::make('type')->options(collect(config('peopleos.performance.review_types'))->except(['self', 'manager'])->all())->default('peer')->required(),
                    Checkbox::make('is_anonymous')->label('Anonymous to the employee'),
                ])
                ->action(fn (Appraisal $record, array $data) => self::run(fn () => app(Appraisals::class)->addPeerReview($record, Employee::query()->findOrFail($data['reviewer_id']), $data['type'], (bool) ($data['is_anonymous'] ?? false)), 'Reviewer added')),
            Action::make('calibrate')->label('Calibrate')->icon(Heroicon::OutlinedAdjustmentsVertical)->color('warning')
                ->visible(fn (Appraisal $record) => ! $record->isFinal() && $user()->can('performance.calibrate'))
                ->schema(fn (Appraisal $record) => [
                    Select::make('rating')->label('Calibrated rating')->options($record->cycle->ratingScale()->options())->default(fn () => $record->effectiveRating() === null ? null : (string) round($record->effectiveRating()))->required(),
                    Textarea::make('note')->required()->maxLength(500),
                ])
                ->action(fn (Appraisal $record, array $data) => self::run(fn () => app(Appraisals::class)->calibrate($record, (float) $data['rating'], $data['note'], auth()->user()), 'Calibrated')),
            Action::make('finalize')->label('Finalize')->icon(Heroicon::OutlinedCheckBadge)->color('success')
                ->visible(fn (Appraisal $record) => ! $record->isFinal() && $user()->can('performance.calibrate'))
                ->schema(fn (Appraisal $record) => [
                    Select::make('rating')->label('Final rating')->options($record->cycle->ratingScale()->options())->default(fn () => $record->effectiveRating() === null ? null : (string) round($record->effectiveRating()))->placeholder('Use computed / calibrated rating'),
                    Textarea::make('summary')->label('Summary for the employee')->maxLength(1000),
                    Checkbox::make('promotion')->label('Recommend for promotion'),
                    Checkbox::make('pip')->label('Recommend an improvement plan'),
                ])
                ->action(fn (Appraisal $record, array $data) => self::run(fn () => app(Appraisals::class)->finalize($record, auth()->user(), isset($data['rating']) && $data['rating'] !== null && $data['rating'] !== '' ? (float) $data['rating'] : null, $data['summary'] ?? null, (bool) ($data['promotion'] ?? false), (bool) ($data['pip'] ?? false)), 'Appraisal finalized')),
            Action::make('acknowledge')->label('Acknowledge')->icon(Heroicon::OutlinedHandThumbUp)->color('success')
                ->visible(fn (Appraisal $record) => $record->status === 'finalized' && $record->employee_id === self::me()?->id)
                ->schema([Textarea::make('comment')->maxLength(500)])
                ->action(fn (Appraisal $record, array $data) => self::run(fn () => app(Appraisals::class)->acknowledge($record, $data['comment'] ?? null), 'Acknowledged')),
        ];
    }

    private static function pendingPeer(Appraisal $record): ?AppraisalReview
    {
        $me = self::me();

        return $me ? $record->reviews()->whereNotIn('type', ['self', 'manager'])->where('reviewer_id', $me->id)->where('status', 'pending')->first() : null;
    }

    /** Goals of the employee in this cycle + the cycle's competencies, each with a rating select and comment. */
    public static function reviewForm(Appraisal $record, bool $withGoals = true): array
    {
        $record->loadMissing('cycle.scale');
        $options = $record->cycle->ratingScale()->options();
        $components = [];

        if ($withGoals) {
            $goals = app(Goals::class)->forEmployee($record->employee, $record->performance_cycle_id)->where('status', '!=', 'cancelled');
            $components[] = Section::make('Goals')->columns(2)->schema($goals->flatMap(fn (Goal $g) => [
                Select::make("goals.{$g->id}")->label("{$g->title} (weight {$g->weight}, progress {$g->progress}%)")->options($options)->required(),
                TextInput::make("goal_comments.{$g->id}")->label('Comment')->maxLength(255),
            ])->all())->visible($goals->isNotEmpty());
        }

        $components[] = Section::make('Competencies')->columns(2)->schema($record->cycle->competencies()->flatMap(fn ($c) => [
            Select::make("competencies.{$c->id}")->label($c->name)->options($options)->required(),
            TextInput::make("competency_comments.{$c->id}")->label('Comment')->maxLength(255),
        ])->all());

        $components[] = Section::make('Overall')->columns(1)->schema([
            Select::make('overall')->label('Overall rating')->options($options)->required(),
            Textarea::make('strengths')->maxLength(1000),
            Textarea::make('improvements')->label('Areas to develop')->maxLength(1000),
            Textarea::make('comments')->maxLength(1000),
        ]);

        return $components;
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
            ['goal_comments' => $data['goal_comments'] ?? [], 'competency_comments' => $data['competency_comments'] ?? [], 'strengths' => $data['strengths'] ?? null, 'improvements' => $data['improvements'] ?? null, 'comments' => $data['comments'] ?? null],
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
                        Select::make('key_result_id')->label('Key result')->options($krs->pluck('title', 'id')->all())->placeholder('The goal itself')->visible($krs->isNotEmpty()),
                        TextInput::make('value')->label('Current value')->numeric()->required(),
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
                ->visible(fn (Goal $record) => $record->isOpen() && auth()->user()->can('update', $record))
                ->schema([Select::make('status')->options(['completed' => 'Completed', 'cancelled' => 'Cancelled'])->required(), Textarea::make('reason')->maxLength(255)])
                ->action(fn (Goal $record, array $data) => self::run(fn () => app(Goals::class)->close($record, $data['status'], $data['reason'] ?? null), 'Goal closed')),
        ];
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
