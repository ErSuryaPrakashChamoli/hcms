<?php

namespace App\Domain\Feedback\Services;

use App\Domain\Configuration\Services\PolicyResolver;
use App\Domain\Employment\Models\Employee;
use App\Domain\Employment\Models\ReportingRelationship;
use App\Domain\Exit\Models\ExitCase;
use App\Domain\Feedback\Models\FeedbackRequest;
use App\Domain\Feedback\Models\FeedbackTemplate;
use App\Domain\Feedback\Support\FeedbackQuestions;
use App\Domain\Identity\Models\Role;
use App\Domain\Identity\Models\User;
use App\Domain\Identity\Scopes\AccessScope;
use App\Domain\Identity\Services\AccessScopes;
use App\Domain\Lifecycle\Models\ConfirmationReview;
use App\Domain\Onboarding\Models\OnboardingTask;
use App\Domain\Onboarding\Services\Onboarding;
use App\Domain\Platform\Services\SettingsRepository;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Throwable;

/**
 * HROne setup parity P13: feedback asked of people about an employee, on a feedback template bound to the process.
 *
 * - Raised by the processes: an exit (the separation policy's exit interviews by the employee, the manager and an HR
 *   role, due on the last working day), a confirmation review (the confirmation policy's manager feedback and the
 *   employee's probation self-review, due on the probation end date) and an onboarding item of type feedback (its owner,
 *   due with the item). One per subject and process; a template that is missing or inactive asks nothing.
 * - Answered once, by the person named or a holder of the role named, never about oneself (except the employee's own
 *   interview or self-review). An answered induction item completes its onboarding task.
 * - Read by the respondent, by holders of the process's reading permission inside their access scope (exit.interview,
 *   employee.lifecycle, onboarding.manage; never feedback about themselves) and, when the confirmation policy copies
 *   feedback to the next level, by the manager's own manager. The employee never reads what others said about them.
 */
final class FeedbackRequests
{
    public function __construct(private readonly PolicyResolver $policies, private readonly SettingsRepository $settings, private readonly AccessScopes $scopes) {}

    /** Ask one feedback; null when there is no active template of the process or nobody to ask. Idempotent per subject and process. */
    public function raise(mixed $templateId, string $process, Model $subject, Employee $employee, string $respondent, ?int $userId, ?int $roleId, ?CarbonInterface $dueOn, string $title, bool $shareWithNextLevel = false): ?FeedbackRequest
    {
        if (! is_numeric($templateId) || ($userId === null && $roleId === null)) {
            return null;
        }
        $template = FeedbackTemplate::query()->active()->where('process', $process)->find((int) $templateId);
        if ($template === null) {
            return null;
        }
        $existing = FeedbackRequest::query()->withoutGlobalScope(AccessScope::class)->where('subject_type', $subject->getMorphClass())->where('subject_id', $subject->getKey())
            ->where('process', $process)->where('status', '!=', 'cancelled')->first();
        if ($existing !== null) {
            return $existing;
        }
        $request = new FeedbackRequest([
            'feedback_template_id' => $template->id, 'process' => $process, 'subject_type' => $subject->getMorphClass(), 'subject_id' => $subject->getKey(),
            'employee_id' => $employee->id, 'respondent' => $respondent, 'respondent_user_id' => $userId, 'respondent_role_id' => $userId === null ? $roleId : null,
            'share_with_next_level' => $shareWithNextLevel, 'title' => mb_strimwidth($title, 0, 160, '…'), 'questions' => $template->questions, 'due_on' => $dueOn,
        ]);
        $request->withAuditReason('Feedback asked: '.$template->name)->save();

        return $request;
    }

    /**
     * The separation policy's exit interviews, raised when the exit starts. A failure never blocks the exit.
     *
     * @return list<FeedbackRequest>
     */
    public function raiseForExit(ExitCase $case, Employee $employee): array
    {
        try {
            $policy = $this->policies->resolve('exit', $employee, now());
            if ($policy === null) {
                return [];
            }
            $employee->loadMissing('person');
            $name = $employee->person?->full_name ?? $employee->employee_code;
            $due = $case->last_working_day;
            $raised = [$this->raise($policy->setting('interview_employee_template_id'), 'exit_interview', $case, $employee, 'employee', $employee->user_id, null, $due, 'Your exit interview')];
            if (self::yes($policy->setting('interview_manager'))) {
                $manager = $case->manager_id ? Employee::query()->withoutGlobalScope(AccessScope::class)->whereKey($case->manager_id)->value('user_id') : null;
                $raised[] = $this->raise($policy->setting('interview_manager_template_id'), 'exit_interview_manager', $case, $employee, 'manager', $manager !== null ? (int) $manager : null, null, $due, "Exit interview of {$name} (manager)");
            }
            if (self::yes($policy->setting('interview_hr'))) {
                $slug = $policy->setting('interview_hr_role') ?: $this->settings->get('exit.hr_clearance_role');
                $role = Role::query()->where('slug', $slug)->value('id');
                $raised[] = $this->raise($policy->setting('interview_hr_template_id'), 'exit_interview_hr', $case, $employee, 'hr', null, $role !== null ? (int) $role : null, $due, "Exit interview of {$name} (HR)");
            }

            return array_values(array_filter($raised));
        } catch (Throwable $e) {
            report($e);

            return [];
        }
    }

    /**
     * The confirmation policy's feedback, raised when the review opens: the manager's feedback (needed before the decision)
     * and the employee's probation self-review. A failure never blocks the review.
     *
     * @return list<FeedbackRequest>
     */
    public function raiseForConfirmation(ConfirmationReview $review, Employee $employee): array
    {
        try {
            $policy = $this->policies->resolve('confirmation', $employee, now());
            if ($policy === null) {
                return [];
            }
            $employee->loadMissing(['person', 'currentManager.manager']);
            $name = $employee->person?->full_name ?? $employee->employee_code;
            $share = self::yes($policy->setting('share_feedback_with_next_level'));
            $manager = $employee->currentManager?->manager?->user_id;

            return array_values(array_filter([
                $this->raise($policy->setting('feedback_template_id'), 'confirmation', $review, $employee, 'manager', $manager !== null ? (int) $manager : null, null, $review->probation_end_date, "Confirmation feedback for {$name}", $share),
                $this->raise($policy->setting('self_review_template_id'), 'probation', $review, $employee, 'employee', $employee->user_id, null, $review->probation_end_date, 'Your probation self-review', $share),
            ]));
        } catch (Throwable $e) {
            report($e);

            return [];
        }
    }

    /** Feedback still pending for a subject and process (e.g. the manager's confirmation feedback before the decision). */
    public function pendingFor(Model $subject, string $process): ?FeedbackRequest
    {
        return FeedbackRequest::query()->withoutGlobalScope(AccessScope::class)->where('subject_type', $subject->getMorphClass())->where('subject_id', $subject->getKey())
            ->where('process', $process)->where('status', 'pending')->first();
    }

    /** @return Collection<int, FeedbackRequest> every feedback raised for a subject (statuses only are shown to those who may not read) */
    public function forSubject(Model $subject): Collection
    {
        return FeedbackRequest::query()->withoutGlobalScope(AccessScope::class)->where('subject_type', $subject->getMorphClass())->where('subject_id', $subject->getKey())->orderBy('id')->get();
    }

    /** "Exit interview by the manager: pending (due 30 Oct 2026) · …" — statuses only, never answers; null when none. */
    public function summary(Model $subject): ?string
    {
        $requests = $this->forSubject($subject)->where('status', '!=', 'cancelled');

        return $requests->isEmpty() ? null : $requests->map(fn (FeedbackRequest $r) => $r->processLabel().': '.strtolower(FeedbackRequest::STATUSES[$r->status] ?? $r->status)
            .($r->status === 'pending' && $r->due_on ? ' (due '.$r->due_on->format('d M Y').')' : ''))->implode(' · ');
    }

    /** @param  array<string, mixed>  $answers */
    public function submit(FeedbackRequest $request, array $answers, User $actor): FeedbackRequest
    {
        $submitted = DB::transaction(function () use ($request, $answers, $actor) {
            $locked = FeedbackRequest::query()->withoutGlobalScope(AccessScope::class)->with('employee')->lockForUpdate()->findOrFail($request->id);
            if ($locked->status !== 'pending') {
                throw new RuntimeException('This feedback has already been '.($locked->status === 'submitted' ? 'given.' : 'cancelled.'));
            }
            if (! $locked->isRespondent($actor)) {
                throw new RuntimeException('This feedback is not asked of you.');
            }
            if ($locked->respondent !== 'employee' && (int) $locked->employee?->user_id === (int) $actor->id) {
                throw new RuntimeException('You cannot give this feedback about yourself.');
            }
            $clean = FeedbackQuestions::validate((array) $locked->questions, $answers);
            $locked->withAuditReason('Feedback given')->update(['answers' => $clean, 'status' => 'submitted', 'submitted_by' => $actor->id, 'submitted_at' => now()]);

            return $locked;
        });

        // An answered feedback item of an onboarding plan (e.g. the induction evaluation) completes the item.
        if ($submitted->subject_type === (new OnboardingTask)->getMorphClass()) {
            $task = OnboardingTask::query()->find($submitted->subject_id);
            if ($task !== null && $task->status === 'pending' && blank($task->declaration_text)) {
                app(Onboarding::class)->complete($task, 'Feedback given', null, $actor);
            }
        }

        return $submitted;
    }

    public function cancel(FeedbackRequest $request, string $reason, User $actor): FeedbackRequest
    {
        if (! $this->canManage($actor, $request)) {
            throw new RuntimeException('You cannot cancel this feedback.');
        }
        if (trim($reason) === '') {
            throw new RuntimeException('Say why the feedback is no longer needed.');
        }
        if ($request->status !== 'pending') {
            throw new RuntimeException('Only pending feedback can be cancelled.');
        }
        $request->withAuditReason($reason)->update(['status' => 'cancelled', 'cancel_reason' => $reason]);

        return $request;
    }

    /** The subject went away (an exit withdrawn, an onboarding item skipped): its pending feedback is cancelled. */
    public function cancelFor(Model $subject, string $reason): int
    {
        $pending = FeedbackRequest::query()->withoutGlobalScope(AccessScope::class)->where('subject_type', $subject->getMorphClass())->where('subject_id', $subject->getKey())->where('status', 'pending')->get();
        $pending->each(fn (FeedbackRequest $r) => $r->withAuditReason($reason)->update(['status' => 'cancelled', 'cancel_reason' => mb_strimwidth($reason, 0, 500)]));

        return $pending->count();
    }

    /** Whether the user may read the answers (see the class comment). */
    public function canRead(User $user, FeedbackRequest $request): bool
    {
        $request->loadMissing('employee');
        $aboutMe = (int) $request->employee?->user_id === (int) $user->id;
        if ($aboutMe) {
            return $request->respondent === 'employee' && $request->isRespondent($user);
        }
        if ($request->isRespondent($user)) {
            return true;
        }
        $permission = config("peopleos.feedback.processes.{$request->process}.read");
        if ($permission !== null && $user->hasPermission($permission) && $this->scopes->allows($user, $request)) {
            return true;
        }

        return $request->share_with_next_level && in_array((int) $request->employee_id, $this->nextLevelEmployeeIds($user), true);
    }

    /** HR who read the process may cancel a pending feedback (e.g. the manager has left). */
    public function canManage(User $user, FeedbackRequest $request): bool
    {
        $permission = config("peopleos.feedback.processes.{$request->process}.read");
        $request->loadMissing('employee');

        return $permission !== null && $user->hasPermission($permission) && (int) $request->employee?->user_id !== (int) $user->id && $this->scopes->allows($user, $request);
    }

    /** Feedback the user answers or may read, for the Feedback screen. */
    public function visibleTo(User $user): Builder
    {
        $roleIds = $user->roles()->pluck('roles.id')->all();
        $me = Employee::query()->withoutGlobalScope(AccessScope::class)->where('user_id', $user->id)->value('id');
        $readable = collect((array) config('peopleos.feedback.processes'))->filter(fn (array $p) => $user->hasPermission($p['read']))->keys()->all();
        $skipLevel = $this->nextLevelEmployeeIds($user);

        return FeedbackRequest::query()->withoutGlobalScope(AccessScope::class)->where(function (Builder $q) use ($user, $roleIds, $me, $readable, $skipLevel) {
            $q->where('respondent_user_id', $user->id)
                ->orWhere(fn (Builder $q) => $q->whereNull('respondent_user_id')->whereIn('respondent_role_id', $roleIds ?: [0])->where('employee_id', '!=', $me ?? 0))
                ->orWhere(function (Builder $q) use ($user, $me, $readable) {
                    $q->whereIn('process', $readable ?: ['-'])->where('employee_id', '!=', $me ?? 0);
                    if ($this->scopes->isScoped($user)) {
                        $q->whereIn('employee_id', $this->scopes->employeeKeys($user));
                    }
                })
                ->orWhere(fn (Builder $q) => $q->where('share_with_next_level', true)->whereIn('employee_id', $skipLevel ?: [0]));
        });
    }

    /** @return list<int> employees whose manager's manager is the user */
    private function nextLevelEmployeeIds(User $user): array
    {
        $me = Employee::query()->withoutGlobalScope(AccessScope::class)->where('user_id', $user->id)->value('id');
        if ($me === null) {
            return [];
        }
        $line = fn (array $managers) => ReportingRelationship::query()->whereIn('manager_id', $managers)->where('is_primary', true)->effectiveOn()->pluck('employee_id')->map(fn ($id) => (int) $id)->all();
        $direct = $line([(int) $me]);

        return $direct === [] ? [] : array_values(array_unique($line($direct)));
    }

    private static function yes(mixed $value): bool
    {
        return in_array($value, [true, 1, '1', 'yes', 'true'], true);
    }
}
