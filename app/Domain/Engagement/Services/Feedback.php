<?php

namespace App\Domain\Engagement\Services;

use App\Domain\Audit\Enums\AuditAction;
use App\Domain\Audit\Services\AuditRecorder;
use App\Domain\Employment\Models\Employee;
use App\Domain\Engagement\Events\EngagementEvent;
use App\Domain\Engagement\Exceptions\EngagementRuleViolation;
use App\Domain\Engagement\Models\EmployeeFeedback;
use App\Domain\Engagement\Models\EngagementIdentity;
use App\Domain\Engagement\Support\Guard;
use App\Domain\Grievance\Models\Grievance;
use App\Domain\Grievance\Models\GrievanceCategory;
use App\Domain\Grievance\Services\Grievances;
use App\Domain\Identity\Models\User;
use App\Domain\Identity\Scopes\AccessScope;
use App\Domain\Identity\Services\AccessScopes;
use App\Domain\ServiceDesk\Models\Ticket;
use App\Domain\ServiceDesk\Models\TicketCategory;
use App\Domain\ServiceDesk\Services\ServiceRequests;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Phase 13: employee feedback — identified, confidential or anonymous, with the same privacy
 * architecture as surveys (random id, date only, encrypted body; confidential authorship only in
 * engagement_identities; anonymous authorship nowhere).
 *
 * Feedback is not a case system. When an item needs action, it is routed into the owning domains
 * through their own safe entry points:
 * - identified feedback → an HR service desk request (ServiceRequests::openGeneric);
 * - confidential feedback → the same, after an explicit, reasoned, audited identification;
 * - anonymous feedback → an anonymous grievance (Grievances::raise, only for categories that accept
 *   anonymous cases).
 */
final class Feedback
{
    public function __construct(
        private readonly AuditRecorder $audit,
        private readonly AccessScopes $scopes,
        private readonly ConfidentialIdentities $identities,
    ) {}

    /** @return array{status: 'submitted', id: ?string} the id only for identified feedback */
    public function submit(User $user, string $mode, string $category, string $body): array
    {
        Guard::authorise($user, 'engagement.participate');
        $employee = AccessScope::withoutScoping(fn () => Employee::query()->where('user_id', $user->id)->first()) ?? throw new EngagementRuleViolation('Feedback is given by employees.');
        if (! array_key_exists($mode, config('peopleos.engagement.feedback_modes')) || ! array_key_exists($category, config('peopleos.engagement.feedback_categories'))) {
            throw new EngagementRuleViolation('Choose how to send your feedback and what it is about.');
        }
        $body = trim($body);
        if (mb_strlen($body) < 5 || mb_strlen($body) > 5000) {
            throw new EngagementRuleViolation('Feedback is between 5 and 5,000 characters.');
        }

        $feedback = DB::transaction(function () use ($employee, $user, $mode, $category, $body) {
            $feedback = EmployeeFeedback::query()->create([
                'mode' => $mode, 'category' => $category, 'body' => $body, 'status' => 'new', 'submitted_on' => now()->toDateString(),
                'employee_id' => $mode === 'identified' ? $employee->id : null,
            ]);
            if ($mode === 'confidential') {
                EngagementIdentity::query()->create(['subject_type' => 'feedback', 'subject_id' => $feedback->id, 'employee_id' => $employee->id]);
            }
            $identified = $mode === 'identified';
            $this->audit->record(AuditAction::FeedbackSubmitted, 'engagement', $identified ? $feedback : null, [], null, actor: $identified ? $user : null,
                metadata: ['mode' => $mode, 'category' => $category], entityLabel: $identified ? null : 'Feedback', anonymous: ! $identified);

            return $feedback;
        });
        EngagementEvent::dispatch('feedback.submitted', $feedback, ['category' => config("peopleos.engagement.feedback_categories.{$category}"), 'mode' => $mode], Guard::holders('engagement.feedback', [$user->id]));

        return ['status' => 'submitted', 'id' => $mode === 'identified' ? $feedback->id : null];
    }

    /** The handler's inbox: identified items of employees in scope; confidential / anonymous items have no employee and are visible to every handler. */
    public function inbox(User $user): Builder
    {
        Guard::authorise($user, 'engagement.feedback');
        $query = EmployeeFeedback::query();
        if ($this->scopes->isScoped($user)) {
            $query->where(fn (Builder $q) => $q->whereNull('employee_id')->orWhereIn('employee_id', $this->scopes->employeeKeys($user)));
        }

        return $query;
    }

    /** The submitter's own identified feedback (confidential / anonymous items cannot be listed back). */
    public function mine(User $user): Builder
    {
        return EmployeeFeedback::query()->where('mode', 'identified')->whereIn('employee_id', Employee::query()->select('id')->where('user_id', $user->id));
    }

    public function setStatus(EmployeeFeedback $feedback, string $status, ?string $note, User $actor): EmployeeFeedback
    {
        $this->assertHandler($feedback, $actor);
        if (! in_array($status, ['in_review', 'closed'], true)) {
            throw new EngagementRuleViolation('Feedback is moved to review or closed.');
        }
        $before = $feedback->status;
        $feedback->update(['status' => $status, 'handled_by' => $actor->id, 'handling_note' => $note, 'closed_on' => $status === 'closed' ? now()->toDateString() : null]);
        $this->audit->record(AuditAction::StatusChange, 'engagement', $feedback, [['field' => 'status', 'before' => $before, 'after' => $status]], null, actor: $actor, entityLabel: 'Feedback');

        return $feedback;
    }

    /** Identified (or, with a reason, confidential) feedback → an HR service desk request owned by the desk. */
    public function referToServiceDesk(EmployeeFeedback $feedback, TicketCategory $category, User $actor, ?string $revealReason = null): Ticket
    {
        $this->assertHandler($feedback, $actor);
        Guard::authorise($actor, 'servicedesk.agent');
        $employee = match ($feedback->mode) {
            'identified' => AccessScope::withoutScoping(fn () => Employee::query()->findOrFail($feedback->employee_id)),
            'confidential' => $this->identities->reveal('feedback', $feedback->id, (string) $revealReason, $actor),
            default => throw new EngagementRuleViolation('Anonymous feedback has nobody to open a request for; refer it as an anonymous grievance.'),
        };

        return DB::transaction(function () use ($feedback, $category, $actor, $employee) {
            $ticket = app(ServiceRequests::class)->openGeneric($employee, $category, 'Feedback: '.config("peopleos.engagement.feedback_categories.{$feedback->category}", $feedback->category),
                $feedback->body, raiser: $actor, idempotencyKey: 'feedback-'.$feedback->id, source: 'hr');
            $this->markReferred($feedback, 'ticket', (int) $ticket->id, $actor);

            return $ticket;
        });
    }

    /** Anonymous feedback → an anonymous grievance (the grievance domain's own rules apply). */
    public function referToGrievance(EmployeeFeedback $feedback, GrievanceCategory $category, User $actor): Grievance
    {
        $this->assertHandler($feedback, $actor);
        if ($feedback->mode !== 'anonymous') {
            throw new EngagementRuleViolation('Only anonymous feedback is referred as an anonymous grievance.');
        }

        return DB::transaction(function () use ($feedback, $category, $actor) {
            $grievance = app(Grievances::class)->raise($category, null, 'Anonymous feedback: '.config("peopleos.engagement.feedback_categories.{$feedback->category}", $feedback->category), $feedback->body, 'medium', anonymous: true, raiser: $actor);
            $this->markReferred($feedback, 'grievance', (int) $grievance->id, $actor);

            return $grievance;
        });
    }

    private function markReferred(EmployeeFeedback $feedback, string $type, int $id, User $actor): void
    {
        $current = EmployeeFeedback::query()->whereKey($feedback->id)->lockForUpdate()->firstOrFail();
        if ($current->status === 'referred') {
            throw new EngagementRuleViolation('This feedback was already referred.');
        }
        $feedback->setRawAttributes($current->getAttributes(), true);
        $feedback->update(['status' => 'referred', 'handled_by' => $actor->id, 'referred_type' => $type, 'referred_id' => $id]);
        $this->audit->record(AuditAction::StatusChange, 'engagement', $feedback, [['field' => 'status', 'before' => $current->status, 'after' => 'referred']], null, actor: $actor, entityLabel: 'Feedback', metadata: ['referred_type' => $type, 'referred_id' => $id]);
    }

    private function assertHandler(EmployeeFeedback $feedback, User $actor): void
    {
        Guard::authorise($actor, 'engagement.feedback');
        if ($feedback->employee_id !== null && ! $this->scopes->allowsEmployeeId($actor, (int) $feedback->employee_id)) {
            throw new EngagementRuleViolation('That feedback is outside your scope.');
        }
    }
}
