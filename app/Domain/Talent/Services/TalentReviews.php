<?php

namespace App\Domain\Talent\Services;

use App\Domain\Audit\Enums\AuditAction;
use App\Domain\Audit\Services\AuditRecorder;
use App\Domain\Employment\Models\Employee;
use App\Domain\Identity\Models\User;
use App\Domain\Identity\Scopes\AccessScope;
use App\Domain\Talent\Events\TalentEvent;
use App\Domain\Talent\Models\TalentProfile;
use App\Domain\Talent\Models\TalentReviewItem;
use App\Domain\Talent\Models\TalentReviewSession;
use App\Domain\Workflow\Models\Workflow;
use App\Domain\Workflow\Services\WorkflowEngine;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Phase 9 talent reviews. A session has a scope, participants and a population; each employee gets
 * a decision recorded by a person (with reason, reviewer and time). Decisions such as "add to a pool"
 * or "nominate as successor" are recorded, never executed automatically — the follow-up is a separate,
 * explicit action. Completion is locked; with a configured workflow it waits for approval (the
 * instance is pinned to the published workflow version).
 */
final class TalentReviews
{
    public function __construct(private readonly TalentAccess $access, private readonly AuditRecorder $audit, private readonly WorkflowEngine $workflows) {}

    /** @param  list<int>  $participantUserIds */
    public function create(string $name, ?int $organisationNodeId, array $participantUserIds, User $actor, ?string $scheduledFor = null): TalentReviewSession
    {
        $this->assertManager($actor);
        // Phase 14: participants are users of this tenant only, whatever the form submitted.
        $requested = array_values(array_unique(array_map('intval', $participantUserIds)));
        $known = User::forCurrentTenant()->whereIn('id', $requested)->pluck('id')->map(fn ($id) => (int) $id)->all();
        if (array_diff($requested, $known) !== []) {
            throw new RuntimeException('Participants must be users of this organisation.');
        }

        return TalentReviewSession::query()->create([
            'name' => $name, 'organisation_node_id' => $organisationNodeId, 'facilitator_user_id' => $actor->id,
            'participants' => array_values(array_unique(array_map('intval', [$actor->id, ...$participantUserIds]))), 'scheduled_for' => $scheduledFor, 'created_by' => $actor->id,
        ]);
    }

    /**
     * Add employees to the population as one audited bulk operation. Only employees inside the
     * actor's organisation scope can be added.
     *
     * @param  list<int>  $employeeIds
     */
    public function addEmployees(TalentReviewSession $session, array $employeeIds, User $actor): int
    {
        $this->assertManager($actor);
        $added = 0;
        $this->audit->operation('talent', 'Talent review population', function () use ($session, $employeeIds, $actor, &$added) {
            DB::transaction(function () use ($session, $employeeIds, $actor, &$added) {
                $locked = TalentReviewSession::query()->whereKey($session->id)->lockForUpdate()->firstOrFail();
                if (! in_array($locked->status, ['draft', 'in_progress'], true)) {
                    throw new RuntimeException('The review is closed.');
                }
                Employee::query()->withoutGlobalScope(AccessScope::class)->whereIn('id', $employeeIds)->orderBy('id')->chunkById(200, function ($employees) use ($session, $actor, &$added) {
                    foreach ($employees as $employee) {
                        if (! $this->access->inScope($actor, $employee->id) || $this->access->self($actor, $employee->id)) {
                            continue;
                        }
                        $item = TalentReviewItem::query()->withoutGlobalScope(AccessScope::class)->firstOrCreate(['talent_review_session_id' => $session->id, 'employee_id' => $employee->id]);
                        $added += $item->wasRecentlyCreated ? 1 : 0;
                    }
                });
            });

            return $added;
        }, null, TalentReviewItem::class);

        return $added;
    }

    public function start(TalentReviewSession $session, User $actor): TalentReviewSession
    {
        $this->assertManager($actor);
        $session->update(['status' => 'in_progress']);

        return $session;
    }

    public function recordDecision(TalentReviewItem $item, string $decision, string $reason, User $actor): TalentReviewItem
    {
        if (! array_key_exists($decision, config('peopleos.talent.review_decisions'))) {
            throw new RuntimeException("Unknown review decision '{$decision}'.");
        }
        if (trim($reason) === '') {
            throw new RuntimeException('A review decision needs a reason.');
        }
        if ($this->access->self($actor, $item->employee_id)) {
            throw new RuntimeException('Nobody records a talent decision about themself.');
        }

        return DB::transaction(function () use ($item, $decision, $reason, $actor) {
            $session = TalentReviewSession::query()->whereKey($item->talent_review_session_id)->lockForUpdate()->firstOrFail();
            if ($session->status !== 'in_progress') {
                throw new RuntimeException('Decisions are recorded while the review is in progress.');
            }
            $participant = in_array((int) $actor->id, array_map('intval', $session->participants ?? []), true);
            if (! ($actor->hasPermission('talent.manage') || ($participant && $actor->hasPermission('talent.review'))) || ! $this->access->inScope($actor, $item->employee_id)) {
                throw new RuntimeException('Only review participants (talent.review) or talent administrators record decisions, within scope.');
            }
            $current = TalentReviewItem::query()->withoutGlobalScope(AccessScope::class)->whereKey($item->id)->lockForUpdate()->firstOrFail();
            if ($current->status === 'decided') {
                throw new RuntimeException('A decision was already recorded for this employee.');
            }
            $item->setRawAttributes($current->getAttributes(), true);
            $item->update(['status' => 'decided', 'decision' => $decision, 'reason' => $reason, 'reviewed_by' => $actor->id, 'reviewed_at' => now()]);

            return $item;
        });
    }

    /** Complete the review: every employee needs a decision. With a configured workflow, completion waits for approval. */
    public function complete(TalentReviewSession $session, string $summary, User $actor): TalentReviewSession
    {
        $this->assertManager($actor);

        return DB::transaction(function () use ($session, $summary, $actor) {
            $current = TalentReviewSession::query()->whereKey($session->id)->lockForUpdate()->firstOrFail();
            if ($current->status !== 'in_progress') {
                throw new RuntimeException('Only a review in progress can be completed.');
            }
            if ($current->workflow_instance_id) {
                throw new RuntimeException('This review is waiting for approval.');
            }
            if (TalentReviewItem::query()->withoutGlobalScope(AccessScope::class)->where('talent_review_session_id', $current->id)->where('status', '!=', 'decided')->exists()) {
                throw new RuntimeException('Record a decision for every employee before completing the review.');
            }
            $session->setRawAttributes($current->getAttributes(), true);
            $session->update(['decision_summary' => $summary]);

            $key = config('peopleos.talent.review_workflow_key');
            $workflow = $key ? Workflow::query()->where('key', $key)->where('status', 'active')->first() : null;
            if ($workflow && $workflow->published()->exists()) {
                $instance = $this->workflows->start($workflow, $session, ['talent_review' => ['name' => $session->name]], $actor);
                $session->update(['workflow_instance_id' => $instance->id]);

                return $session->refresh();
            }

            return $this->finish($session, $actor);
        });
    }

    /** Finish a completion (directly, or when the approval workflow approves). */
    public function finish(TalentReviewSession $session, ?User $actor = null): TalentReviewSession
    {
        $session->update(['status' => 'completed', 'completed_by' => $actor?->id ?? $session->facilitator_user_id, 'completed_at' => now()]);
        TalentReviewItem::query()->withoutGlobalScope(AccessScope::class)->where('talent_review_session_id', $session->id)->get()
            ->each(function (TalentReviewItem $item) {
                TalentProfile::query()->withoutGlobalScope(AccessScope::class)->firstOrCreate(['employee_id' => $item->employee_id]);
                TalentProfile::query()->withoutGlobalScope(AccessScope::class)->where('employee_id', $item->employee_id)->first()?->update(['latest_review_outcome' => $item->decision]);
            });
        $this->audit->record(AuditAction::Approved, 'talent', $session, [['field' => 'status', 'before' => 'in_progress', 'after' => 'completed']], null, actor: $actor, metadata: ['event' => 'talent_review_completed']);
        TalentEvent::dispatch('talent.review.completed', null, $session, ['name' => $session->name], [], array_values(array_map('intval', $session->participants ?? [])));

        return $session;
    }

    public function cancel(TalentReviewSession $session, string $reason, User $actor): TalentReviewSession
    {
        $this->assertManager($actor);
        $session->withAuditReason($reason)->update(['status' => 'cancelled']);

        return $session;
    }

    private function assertManager(User $actor): void
    {
        if (! $actor->hasPermission('talent.manage')) {
            throw new RuntimeException('Talent reviews are run with talent.manage.');
        }
    }
}
