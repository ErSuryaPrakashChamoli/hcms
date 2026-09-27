<?php

namespace App\Filament\Support;

use App\Domain\Employment\Models\Employee;
use App\Domain\Learning\Models\Course;
use App\Domain\Learning\Models\LearningEnrolment;
use App\Domain\Learning\Models\LearningPath;
use App\Domain\Learning\Policies\EnrolmentPolicy;
use App\Domain\Learning\Services\Assessments;
use App\Domain\Learning\Services\Learning;
use Filament\Actions\Action;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Radio;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Section;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Carbon;
use RuntimeException;
use Throwable;

/** Enrol, learn (modules, assessment), withdraw. */
final class LearningActions
{
    public static function me(): ?Employee
    {
        return Employee::query()->where('user_id', auth()->id())->first();
    }

    public static function peopleOptions(): array
    {
        return Employee::query()->with('person')->employed()->get()->mapWithKeys(fn ($e) => [$e->id => "{$e->employee_code} · {$e->person?->full_name}"])->all();
    }

    public static function enrol(): Action
    {
        return Action::make('enrol')->label('Enrol employee')->icon(Heroicon::OutlinedUserPlus)
            ->visible(fn () => auth()->user()->can('learning.assign') || auth()->user()->can('learning.manage'))
            ->schema([
                Select::make('employee_id')->label('Employee')->required()->searchable()->options(fn () => self::peopleOptions()),
                Select::make('course_id')->label('Course')->searchable()->options(fn () => Course::query()->where('status', 'published')->orderBy('title')->pluck('title', 'id')->all())->requiredWithout('learning_path_id'),
                Select::make('learning_path_id')->label('or learning path')->options(fn () => LearningPath::query()->where('status', 'active')->pluck('name', 'id')->all()),
                DatePicker::make('due_on')->native(false)->default(now()->addDays(30)),
            ])
            ->action(function (array $data) {
                $employee = Employee::query()->findOrFail($data['employee_id']);
                $due = isset($data['due_on']) ? Carbon::parse($data['due_on']) : null;
                self::run(function () use ($data, $employee, $due) {
                    if (! empty($data['learning_path_id'])) {
                        return app(Learning::class)->enrolPath($employee, LearningPath::query()->findOrFail($data['learning_path_id']), $due, null, auth()->user())->count().' enrolment(s) created';
                    }
                    app(Learning::class)->enrol($employee, Course::query()->findOrFail($data['course_id']), $due, null, null, auth()->user());

                    return 'Enrolled';
                }, fn ($msg) => $msg);
            });
    }

    /** @return array<int, Action> */
    public static function forEnrolment(): array
    {
        $own = fn (LearningEnrolment $record) => EnrolmentPolicy::isOwn(auth()->user(), $record);
        $staff = fn () => auth()->user()->can('learning.assign') || auth()->user()->can('learning.manage');

        return [
            Action::make('completeModule')->label('Mark module complete')->icon(Heroicon::OutlinedCheck)->color('primary')
                ->visible(fn (LearningEnrolment $record) => $record->isOpen() && ($own($record) || $staff()) && $record->course->modules()->where('type', '!=', 'assessment')->count() > 0)
                ->schema(fn (LearningEnrolment $record) => [
                    Select::make('module_id')->label('Module')->required()
                        ->options(fn () => $record->course->modules()->where('type', '!=', 'assessment')->get()->reject(fn ($m) => $record->hasCompletedModule($m->id))->pluck('title', 'id')->all()),
                ])
                ->action(fn (LearningEnrolment $record, array $data) => self::run(fn () => app(Learning::class)->completeModule($record, $record->course->modules()->findOrFail($data['module_id'])), 'Module completed')),
            Action::make('takeAssessment')->label('Take assessment')->icon(Heroicon::OutlinedAcademicCap)->color('success')
                ->visible(fn (LearningEnrolment $record) => $record->isOpen() && $own($record) && $record->course->assessment !== null && $record->attempts < $record->course->attempts_allowed)
                ->modalHeading(fn (LearningEnrolment $record) => $record->course->assessment->title)
                ->modalDescription(fn (LearningEnrolment $record) => 'Pass mark '.($record->course->assessment->passing_score ?? $record->course->passing_score).'% · attempt '.($record->attempts + 1).' of '.$record->course->attempts_allowed)
                ->schema(fn (LearningEnrolment $record) => [
                    Section::make()->schema(collect($record->course->assessment->questionsForLearner())->map(fn ($q) => Radio::make("answers.{$q['index']}")->label($q['question'])->options(array_combine(array_keys($q['options']), $q['options']))->required())->all()),
                ])
                ->action(fn (LearningEnrolment $record, array $data) => self::run(function () use ($record, $data) {
                    $attempt = app(Assessments::class)->submit($record, array_map(fn ($v) => $v === null ? null : (int) $v, $data['answers'] ?? []));

                    return ($attempt->passed ? 'Passed' : 'Not passed').' — score '.rtrim(rtrim((string) $attempt->score, '0'), '.').'%';
                }, fn ($m) => $m)),
            Action::make('withdraw')->label('Withdraw')->icon(Heroicon::OutlinedXMark)->color('danger')
                ->visible(fn (LearningEnrolment $record) => $record->isOpen() && $staff())
                ->schema([Textarea::make('reason')->required()->maxLength(255)])
                ->action(fn (LearningEnrolment $record, array $data) => self::run(fn () => app(Learning::class)->withdraw($record, $data['reason'], auth()->user()), 'Withdrawn')),
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
