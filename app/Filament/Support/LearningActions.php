<?php

namespace App\Filament\Support;

use App\Domain\Employment\Models\Employee;
use App\Domain\Learning\Models\Course;
use App\Domain\Learning\Models\LearningEnrolment;
use App\Domain\Learning\Models\LearningPath;
use App\Domain\Learning\Policies\EnrolmentPolicy;
use App\Domain\Learning\Services\Assessments;
use App\Domain\Learning\Services\Completions;
use App\Domain\Learning\Services\Learning;
use App\Domain\Learning\Services\LearningEvidenceService;
use App\Domain\Performance\Services\PerformanceRelationships;
use App\Support\Storage\StagedUpload;
use Filament\Actions\Action;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Radio;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
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

    /** Employees the current user may assign learning to: everyone with learning.manage, else the employees they manage. */
    public static function peopleOptions(): array
    {
        $user = auth()->user();
        $query = Employee::query()->with('person')->employed();
        if (! $user->can('learning.manage')) {
            $query->whereIn('id', app(PerformanceRelationships::class)->reportIds(self::me()));
        }

        return $query->get()->mapWithKeys(fn ($e) => [$e->id => "{$e->employee_code} · {$e->person?->full_name}"])->all();
    }

    public static function allPeopleOptions(): array
    {
        return Employee::query()->with('person')->employed()->get()->mapWithKeys(fn ($e) => [$e->id => "{$e->employee_code} · {$e->person?->full_name}"])->all();
    }

    public static function enrol(): Action
    {
        return Action::make('enrol')->label('Assign learning')->icon(Heroicon::OutlinedUserPlus)
            ->visible(fn () => auth()->user()->can('learning.assign') || auth()->user()->can('learning.manage'))
            ->schema([
                Select::make('employee_id')->label('Employee')->required()->searchable()->options(fn () => self::peopleOptions()),
                Select::make('course_id')->label('Course')->searchable()->options(fn () => Course::query()->whereIn('status', Course::ENROLLABLE)->orderBy('title')->pluck('title', 'id')->all())->requiredWithout('learning_path_id'),
                Select::make('learning_path_id')->label('or learning path')->options(fn () => LearningPath::query()->where('status', 'active')->pluck('name', 'id')->all()),
                DatePicker::make('due_on')->native(false)->default(now()->addDays(30)),
                Textarea::make('reason')->maxLength(500),
            ])
            ->action(function (array $data) {
                $employee = Employee::query()->findOrFail($data['employee_id']);
                $due = isset($data['due_on']) ? Carbon::parse($data['due_on']) : null;
                self::run(function () use ($data, $employee, $due) {
                    if (! app(Learning::class)->mayAssign(auth()->user(), $employee)) {
                        throw new RuntimeException('You can assign learning only to employees you manage.');
                    }
                    if (! empty($data['learning_path_id'])) {
                        return app(Learning::class)->enrolPath($employee, LearningPath::query()->findOrFail($data['learning_path_id']), $due, null, auth()->user(), 'assigned')->count().' enrolment(s) created';
                    }
                    app(Learning::class)->enrol($employee, Course::query()->findOrFail($data['course_id']), $due, null, null, auth()->user(), false, 'assigned', $data['reason'] ?? null);

                    return 'Assigned';
                }, fn ($msg) => $msg);
            });
    }

    /** Employee self-service: ask for a course that allows self-enrolment or approval. */
    public static function requestLearning(): Action
    {
        return Action::make('requestLearning')->label('Request learning')->icon(Heroicon::OutlinedHandRaised)->color('primary')
            ->visible(fn () => self::me() !== null && auth()->user()->can('learning.learn'))
            ->schema([
                Select::make('course_id')->label('Course')->required()->searchable()
                    ->options(fn () => Course::query()->whereIn('status', Course::ENROLLABLE)->where(fn ($q) => $q->where('allow_self_enrol', true)->orWhere('requires_approval', true))->orderBy('title')->pluck('title', 'id')->all()),
                Textarea::make('reason')->label('Why do you need it?')->required()->maxLength(500),
            ])
            ->action(fn (array $data) => self::run(fn () => app(Learning::class)->request(self::me(), Course::query()->findOrFail($data['course_id']), $data['reason'], auth()->user()), fn (LearningEnrolment $e) => $e->status === 'pending_approval' ? 'Request sent for approval' : 'Enrolled'));
    }

    /** @return array<int, Action> */
    public static function forEnrolment(): array
    {
        $own = fn (LearningEnrolment $record) => EnrolmentPolicy::isOwn(auth()->user(), $record);
        $staff = fn () => auth()->user()->can('learning.assign') || auth()->user()->can('learning.manage');
        $assessment = fn (LearningEnrolment $record) => $record->courseVersion?->assessment;

        return [
            Action::make('start')->label('Start')->icon(Heroicon::OutlinedPlay)->color('primary')
                ->visible(fn (LearningEnrolment $record) => in_array($record->status, ['assigned', 'enrolled', 'approved'], true) && $own($record))
                ->action(fn (LearningEnrolment $record) => self::run(fn () => app(Learning::class)->start($record), 'Started')),
            Action::make('completeModule')->label('Mark module complete')->icon(Heroicon::OutlinedCheck)->color('primary')
                ->visible(fn (LearningEnrolment $record) => $record->isOpen() && ($own($record) || $staff()) && collect($record->courseVersion?->curriculum ?? [])->where('type', '!=', 'assessment')->isNotEmpty())
                ->schema(fn (LearningEnrolment $record) => [
                    Select::make('module_id')->label('Module')->required()
                        ->options(fn () => collect($record->courseVersion?->curriculum ?? [])->where('type', '!=', 'assessment')->reject(fn ($m) => $record->hasCompletedModule((int) $m['id']))->pluck('title', 'id')->all()),
                ])
                ->action(fn (LearningEnrolment $record, array $data) => self::run(fn () => app(Learning::class)->completeModule($record, $record->course->modules()->findOrFail($data['module_id'])), 'Module completed')),
            Action::make('progress')->label('Update progress')->icon(Heroicon::OutlinedChartBar)->color('gray')
                ->visible(fn (LearningEnrolment $record) => $record->isOpen() && ($staff() || ($own($record) && in_array($record->courseVersion?->delivery_mode, config('peopleos.learning.self_reported_progress_modes', []), true))))
                ->schema([TextInput::make('progress')->numeric()->minValue(0)->maxValue(100)->suffix('%')->required()->helperText('Progress is not completion: completion is recorded separately.')])
                ->action(fn (LearningEnrolment $record, array $data) => self::run(fn () => app(Learning::class)->updateProgress($record, (float) $data['progress'], auth()->user()), 'Progress updated')),
            Action::make('takeAssessment')->label('Take assessment')->icon(Heroicon::OutlinedAcademicCap)->color('success')
                ->visible(fn (LearningEnrolment $record) => $record->isOpen() && $own($record) && $assessment($record) !== null && $record->attempts < ($record->courseVersion?->attempts_allowed ?? $record->course->attempts_allowed))
                ->modalHeading(fn (LearningEnrolment $record) => $assessment($record)['title'] ?? 'Assessment')
                ->modalDescription(fn (LearningEnrolment $record) => 'Pass mark '.($assessment($record)['passing_score'] ?? $record->courseVersion?->passing_score).'% · attempt '.($record->attempts + 1).' of '.($record->courseVersion?->attempts_allowed ?? $record->course->attempts_allowed))
                ->schema(fn (LearningEnrolment $record) => [
                    // Questions of the pinned course version, without their answers.
                    Section::make()->schema(collect($assessment($record)['questions'] ?? [])->values()->map(fn ($q, $i) => Radio::make("answers.{$i}")->label($q['question'])->options(array_values($q['options'] ?? []))->required())->all()),
                ])
                ->action(fn (LearningEnrolment $record, array $data) => self::run(function () use ($record, $data) {
                    $attempt = app(Assessments::class)->submit($record, array_map(fn ($v) => $v === null ? null : (int) $v, $data['answers'] ?? []));

                    return ($attempt->passed ? 'Passed' : 'Not passed').' — score '.rtrim(rtrim((string) $attempt->score, '0'), '.').'%';
                }, fn ($m) => $m)),
            Action::make('evidence')->label('Upload evidence')->icon(Heroicon::OutlinedPaperClip)->color('gray')
                ->visible(fn (LearningEnrolment $record) => ($record->isOpen() || $record->status === 'completed') && ($own($record) || auth()->user()->can('learning.manage')))
                ->schema([FileUpload::make('file')->disk(StagedUpload::disk())->directory('tmp/learning-evidence')->visibility('private')->acceptedFileTypes(config('peopleos.learning.evidence_mimes'))->maxSize((int) config('peopleos.learning.evidence_max_kb'))->required()])
                ->action(fn (LearningEnrolment $record, array $data) => self::run(function () use ($record, $data) {
                    $disk = StagedUpload::storage();
                    try {
                        return app(LearningEvidenceService::class)->upload($record, (string) $disk->get($data['file']), basename($data['file']), (string) $disk->mimeType($data['file']), auth()->user());
                    } finally {
                        $disk->delete($data['file']);
                    }
                }, 'Evidence uploaded')),
            Action::make('approve')->label('Approve')->icon(Heroicon::OutlinedCheckCircle)->color('success')
                ->visible(fn (LearningEnrolment $record) => in_array($record->status, ['requested', 'pending_approval'], true) && ! $own($record) && (auth()->user()->can('learning.approve') || auth()->user()->can('learning.manage')))
                ->schema([Textarea::make('note')->maxLength(500)])
                ->action(fn (LearningEnrolment $record, array $data) => self::run(fn () => app(Learning::class)->decide($record, true, $data['note'] ?? null, auth()->user()), 'Approved')),
            Action::make('reject')->label('Reject')->icon(Heroicon::OutlinedXCircle)->color('danger')
                ->visible(fn (LearningEnrolment $record) => in_array($record->status, ['requested', 'pending_approval'], true) && ! $own($record) && (auth()->user()->can('learning.approve') || auth()->user()->can('learning.manage')))
                ->schema([Textarea::make('note')->label('Reason')->required()->maxLength(500)])
                ->action(fn (LearningEnrolment $record, array $data) => self::run(fn () => app(Learning::class)->decide($record, false, $data['note'], auth()->user()), 'Rejected')),
            Action::make('complete')->label('Record completion')->icon(Heroicon::OutlinedCheckBadge)->color('success')
                ->visible(fn (LearningEnrolment $record) => $record->isOpen() && auth()->user()->can('learning.manage'))
                ->modalDescription('Completion is final: it pins the course version and can only be corrected by a new, audited record.')
                ->schema([
                    TextInput::make('score')->numeric()->minValue(0)->maxValue(100)->suffix('%'),
                    Select::make('grade')->options(config('peopleos.learning.grades')),
                    Select::make('attendance')->options(config('peopleos.learning.attendance')),
                    Textarea::make('evidence')->label('Evidence note')->maxLength(1000),
                ])
                ->action(fn (LearningEnrolment $record, array $data) => self::run(fn () => app(Completions::class)->finalize($record, filled($data['score'] ?? null) ? (float) $data['score'] : null, auth()->user(), array_filter(['grade' => $data['grade'] ?? null, 'attendance' => $data['attendance'] ?? null, 'evidence' => $data['evidence'] ?? null])), 'Completion recorded')),
            Action::make('correct')->label('Correct completion')->icon(Heroicon::OutlinedPencilSquare)->color('warning')
                ->visible(fn (LearningEnrolment $record) => $record->status === 'completed' && auth()->user()->can('learning.manage'))
                ->schema([
                    TextInput::make('score')->numeric()->minValue(0)->maxValue(100)->suffix('%'),
                    Select::make('grade')->options(config('peopleos.learning.grades')),
                    Select::make('attendance')->options(config('peopleos.learning.attendance')),
                    Textarea::make('reason')->required()->maxLength(500),
                ])
                ->action(fn (LearningEnrolment $record, array $data) => self::run(fn () => app(Completions::class)->correct(
                    $record->completions()->where('status', 'final')->latest('sequence')->firstOrFail(),
                    array_filter(['score' => filled($data['score'] ?? null) ? (float) $data['score'] : null, 'grade' => $data['grade'] ?? null, 'attendance' => $data['attendance'] ?? null], fn ($v) => $v !== null),
                    $data['reason'], auth()->user()), 'Correction recorded')),
            Action::make('cancel')->label('Cancel')->icon(Heroicon::OutlinedNoSymbol)->color('danger')
                ->visible(fn (LearningEnrolment $record) => ($record->isOpen() || $record->isPending()) && ($staff() || ($own($record) && $record->isPending())))
                ->schema([Textarea::make('reason')->required()->maxLength(255)])
                ->action(fn (LearningEnrolment $record, array $data) => self::run(fn () => app(Learning::class)->cancel($record, $data['reason'], auth()->user()), 'Cancelled')),
            Action::make('withdraw')->label('Withdraw')->icon(Heroicon::OutlinedXMark)->color('danger')
                ->visible(fn (LearningEnrolment $record) => $record->isOpen() && $record->started_at !== null && $staff())
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
