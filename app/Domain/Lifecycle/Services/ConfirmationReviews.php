<?php

namespace App\Domain\Lifecycle\Services;

use App\Domain\Configuration\Services\PolicyResolver;
use App\Domain\Employment\Models\Employee;
use App\Domain\Exit\Services\Exits;
use App\Domain\Feedback\Services\FeedbackRequests;
use App\Domain\Identity\Models\User;
use App\Domain\Letters\Support\PolicyLetters;
use App\Domain\Lifecycle\Enums\LifecycleState;
use App\Domain\Lifecycle\Models\ConfirmationReview;
use App\Domain\Platform\Services\SettingsRepository;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Confirmation reviews (HROne setup parity, Workforce → Confirmation policy). A review opens `review_lead_days` before
 * the probation ends (the confirmation policy, else the probation reminder setting) and is decided by someone with
 * `employee.lifecycle`, never about themselves: confirm (the employee becomes confirmed from the probation end date),
 * extend the probation (by at most `extension_months`, at most `max_extensions` times; a new review opens later), or
 * terminate (an exit of type termination). The policy can switch extension or termination off.
 */
final class ConfirmationReviews
{
    public function __construct(private readonly PolicyResolver $policies, private readonly SettingsRepository $settings, private readonly LifecycleEngine $lifecycle) {}

    /** Open the reviews due by this day; idempotent per employee and probation end date. */
    public function openDue(?Carbon $today = null): int
    {
        $today = ($today ?? now())->copy()->startOfDay();
        $opened = 0;
        Employee::query()->with('person')->whereIn('lifecycle_state', [LifecycleState::Joined->value, LifecycleState::Probation->value])->whereNotNull('probation_end_date')
            ->each(function (Employee $employee) use ($today, &$opened) {
                $lead = (int) ($this->policies->resolve('confirmation', $employee, $today)?->setting('review_lead_days') ?? $this->settings->get('employee.probation.reminder_days', 15));
                if ($employee->probation_end_date->copy()->subDays($lead)->gt($today)) {
                    return;
                }
                $exists = ConfirmationReview::query()->where('employee_id', $employee->id)->whereDate('probation_end_date', $employee->probation_end_date->toDateString())->exists();
                if (! $exists) {
                    $extensions = ConfirmationReview::query()->where('employee_id', $employee->id)->where('status', 'extended')->count();
                    $review = new ConfirmationReview(['employee_id' => $employee->id, 'probation_end_date' => $employee->probation_end_date, 'due_on' => $employee->probation_end_date, 'extension_number' => $extensions]);
                    $review->withAuditReason('Confirmation review opened')->save();
                    // HROne setup parity P13: the confirmation policy's feedback (manager) and probation self-review (employee).
                    app(FeedbackRequests::class)->raiseForConfirmation($review, $employee);
                    $opened++;
                }
            });

        return $opened;
    }

    public function confirm(ConfirmationReview $review, ?string $note, User $actor): ConfirmationReview
    {
        return $this->decide($review, $actor, function (ConfirmationReview $review, Employee $employee) use ($note) {
            $this->lifecycle->transition($employee, LifecycleState::Confirmed, $review->probation_end_date, $note ?: 'Confirmed after review', ['confirmation_review_id' => $review->id]);

            return ['status' => 'confirmed', 'decision_note' => $note];
        });
    }

    public function extend(ConfirmationReview $review, int $months, string $note, User $actor): ConfirmationReview
    {
        return $this->decide($review, $actor, function (ConfirmationReview $review, Employee $employee) use ($months, $note) {
            $policy = $this->policies->resolve('confirmation', $employee, now());
            if ($policy !== null && $policy->setting('allow_extend', true) === false) {
                throw new RuntimeException('The confirmation policy does not allow extending the probation.');
            }
            $limit = (int) ($policy?->setting('extension_months') ?? 0);
            if ($months < 1 || ($limit > 0 && $months > $limit)) {
                throw new RuntimeException($limit > 0 ? "The probation can be extended by 1 to {$limit} month(s)." : 'Extend by at least one month.');
            }
            $max = (int) ($policy?->setting('max_extensions') ?? 0);
            if ($max > 0 && $review->extension_number >= $max) {
                throw new RuntimeException("The probation has already been extended {$review->extension_number} time(s), the most the policy allows.");
            }
            if (trim($note) === '') {
                throw new RuntimeException('Say why the probation is extended.');
            }
            $until = $review->probation_end_date->copy()->addMonths($months);
            $employee->withAuditReason($note)->update(['probation_end_date' => $until]);

            return ['status' => 'extended', 'extension_months' => $months, 'extended_to' => $until, 'decision_note' => $note];
        });
    }

    public function terminate(ConfirmationReview $review, string $note, User $actor): ConfirmationReview
    {
        return $this->decide($review, $actor, function (ConfirmationReview $review, Employee $employee) use ($note, $actor) {
            $policy = $this->policies->resolve('confirmation', $employee, now());
            if ($policy !== null && $policy->setting('allow_terminate', true) === false) {
                throw new RuntimeException('The confirmation policy does not allow termination at review.');
            }
            if (trim($note) === '') {
                throw new RuntimeException('Give the reason for the termination.');
            }
            $case = app(Exits::class)->initiate($employee, 'termination', $note, actor: $actor);

            return ['status' => 'terminated', 'decision_note' => $note, 'exit_case_id' => $case->id];
        });
    }

    /** @param  callable(ConfirmationReview, Employee): array<string, mixed>  $apply */
    private function decide(ConfirmationReview $review, User $actor, callable $apply): ConfirmationReview
    {
        if (! $actor->hasPermission('employee.lifecycle')) {
            throw new RuntimeException('You cannot decide confirmation reviews.');
        }

        return DB::transaction(function () use ($review, $actor, $apply) {
            $locked = ConfirmationReview::query()->with('employee.person')->lockForUpdate()->findOrFail($review->id);
            if ($locked->status !== 'pending') {
                throw new RuntimeException('This review has already been decided.');
            }
            $employee = $locked->employee;
            if ((int) $employee->user_id === (int) $actor->id) {
                throw new RuntimeException('You cannot decide your own confirmation.');
            }
            // HROne setup parity P13: the manager's feedback comes before the decision (HR may cancel it with a reason).
            if (app(FeedbackRequests::class)->pendingFor($locked, 'confirmation') !== null) {
                throw new RuntimeException('The manager\'s confirmation feedback is still pending.');
            }
            $values = $apply($locked, $employee);
            $locked->withAuditReason($values['decision_note'] ?? 'Confirmation decided')->update($values + ['decided_by' => $actor->id, 'decided_at' => now()]);
            // HROne setup parity: the confirmation policy may draft a letter for the decision.
            $template = $this->policies->resolve('confirmation', $employee, now())?->setting(match ($values['status']) {
                'confirmed' => 'letter_on_confirm', 'extended' => 'letter_on_extend', default => 'letter_on_terminate',
            });
            PolicyLetters::draft($template, $employee, $actor, $locked);
            app(FeedbackRequests::class)->cancelFor($locked, 'Confirmation review decided');

            return $locked;
        });
    }
}
