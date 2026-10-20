<?php

namespace App\Domain\Engagement\Services;

use App\Domain\Audit\Enums\AuditAction;
use App\Domain\Audit\Services\AuditRecorder;
use App\Domain\Communication\Models\Announcement;
use App\Domain\Communication\Models\AnnouncementRead;
use App\Domain\Communication\Models\CommunicationRecipient;
use App\Domain\Communication\Services\Communications;
use App\Domain\Engagement\Events\EngagementEvent;
use App\Domain\Engagement\Exceptions\EngagementRuleViolation;
use App\Domain\Engagement\Models\CampaignItem;
use App\Domain\Engagement\Models\EngagementCampaign;
use App\Domain\Engagement\Models\Survey;
use App\Domain\Engagement\Models\SurveyParticipation;
use App\Domain\Engagement\Models\SurveyVersion;
use App\Domain\Engagement\Support\Guard;
use App\Domain\Identity\Models\User;
use App\Domain\Identity\Scopes\AccessScope;
use App\Domain\Knowledge\Models\Article;
use App\Domain\ServiceDesk\Models\ServiceDefinition;
use App\Domain\Workflow\Models\Workflow;
use App\Domain\Workflow\Services\WorkflowEngine;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Phase 13: engagement campaigns group a survey, announcements, Knowledge Base articles and HR
 * services under one owner, audience and timeline — by reference only.
 *
 * Draft → In review → Approved (a second person or a workflow) → Scheduled → Active → Completed,
 * or Cancelled.
 *
 * Launching is one audited bulk operation (operation id). It publishes the campaign's items that
 * are themselves approved, each through its own module. Items that are not ready are reported as
 * skipped, never forced. A partial launch is reported as partial. Launching twice changes nothing.
 */
final class Campaigns
{
    public function __construct(
        private readonly AuditRecorder $audit,
        private readonly Surveys $surveys,
        private readonly Communications $communications,
        private readonly WorkflowEngine $workflows,
    ) {}

    /** @param  array<string, mixed>  $data */
    public function create(array $data, User $actor, ?string $idempotencyKey = null): EngagementCampaign
    {
        Guard::authorise($actor, 'engagement.manage');
        if ($idempotencyKey !== null && ($existing = EngagementCampaign::query()->where('idempotency_key', $idempotencyKey)->first())) {
            return $existing;
        }
        if (blank($data['code'] ?? null) || blank($data['name'] ?? null) || blank($data['starts_on'] ?? null)) {
            throw new EngagementRuleViolation('A campaign needs a code, a name and a start date.');
        }
        if (filled($data['ends_on'] ?? null) && Carbon::parse($data['ends_on'])->lt(Carbon::parse($data['starts_on']))) {
            throw new EngagementRuleViolation('A campaign ends on or after its start date.');
        }

        try {
            return DB::transaction(function () use ($data, $actor, $idempotencyKey) {
                $campaign = EngagementCampaign::query()->create([
                    'code' => $data['code'], 'name' => $data['name'], 'purpose' => $data['purpose'] ?? null, 'owner_id' => $data['owner_id'] ?? $actor->id,
                    'audience_id' => filled($data['audience_id'] ?? null) ? (int) $data['audience_id'] : null, 'starts_on' => $data['starts_on'], 'ends_on' => $data['ends_on'] ?? null,
                    'prepared_by' => $actor->id, 'idempotency_key' => $idempotencyKey,
                ]);
                $this->audit->record(AuditAction::CampaignCreated, 'engagement', $campaign, [], null, actor: $actor);

                return $campaign;
            });
        } catch (UniqueConstraintViolationException) {
            if ($idempotencyKey !== null && ($existing = EngagementCampaign::query()->where('idempotency_key', $idempotencyKey)->first())) {
                return $existing;
            }
            throw new EngagementRuleViolation('A campaign with that code already exists.');
        }
    }

    public function addItem(EngagementCampaign $campaign, string $type, int $id, User $actor): CampaignItem
    {
        Guard::authorise($actor, 'engagement.manage');
        if ($campaign->status !== 'draft') {
            throw new EngagementRuleViolation('Items are added while the campaign is a draft.');
        }
        $exists = match ($type) {
            'survey' => Survey::query()->whereKey($id)->exists(),
            'announcement' => Announcement::query()->whereKey($id)->exists(),
            'article' => Article::query()->whereKey($id)->exists(),
            'service' => ServiceDefinition::query()->whereKey($id)->exists(),
            default => throw new EngagementRuleViolation('Unknown campaign item type.'),
        };
        if (! $exists) {
            throw new EngagementRuleViolation('That item does not exist.');
        }

        try {
            return CampaignItem::query()->create(['campaign_id' => $campaign->id, 'item_type' => $type, 'item_id' => $id, 'position' => (int) CampaignItem::query()->where('campaign_id', $campaign->id)->max('position') + 1]);
        } catch (UniqueConstraintViolationException) {
            throw new EngagementRuleViolation('That item is already part of the campaign.');
        }
    }

    public function removeItem(CampaignItem $item, User $actor): void
    {
        Guard::authorise($actor, 'engagement.manage');
        if (EngagementCampaign::query()->whereKey($item->campaign_id)->value('status') !== 'draft') {
            throw new EngagementRuleViolation('Items are removed while the campaign is a draft.');
        }
        $item->delete();
    }

    public function submit(EngagementCampaign $campaign, User $actor): EngagementCampaign
    {
        Guard::authorise($actor, 'engagement.manage');

        return $this->move($campaign, ['draft'], 'in_review', $actor, function (EngagementCampaign $current) use ($actor) {
            if (! CampaignItem::query()->where('campaign_id', $current->id)->exists()) {
                throw new EngagementRuleViolation('A campaign needs at least one item.');
            }

            return ['prepared_by' => $actor->id, 'submitted_at' => now(), 'decision_note' => null];
        }, AuditAction::Submitted, 'campaign.review_requested', function (EngagementCampaign $submitted) use ($actor) {
            $key = config('peopleos.engagement.approval_workflows.campaign');
            $workflow = $key ? Workflow::query()->where('key', $key)->where('status', 'active')->first() : null;
            if ($workflow && $workflow->published()->exists()) {
                $instance = $this->workflows->start($workflow, $submitted, ['campaign' => ['code' => $submitted->code]], $actor);
                EngagementCampaign::query()->whereKey($submitted->id)->update(['workflow_instance_id' => $instance->id]);
                $submitted->workflow_instance_id = $instance->id;
            }
        });
    }

    public function approve(EngagementCampaign $campaign, ?string $note, User $actor): EngagementCampaign
    {
        Guard::authorise($actor, 'engagement.approve');

        return $this->move($campaign, ['in_review'], 'approved', $actor, function (EngagementCampaign $current) use ($actor, $note) {
            if ($current->workflow_instance_id) {
                throw new EngagementRuleViolation('This campaign is decided by its approval workflow.');
            }
            Guard::notPreparer($current->prepared_by, $actor, 'approve');

            return ['approved_by' => $actor->id, 'approved_at' => now(), 'decision_note' => $note];
        }, AuditAction::CampaignApproved, 'campaign.approved');
    }

    public function returnToDraft(EngagementCampaign $campaign, string $note, User $actor): EngagementCampaign
    {
        Guard::authorise($actor, 'engagement.approve');
        if (trim($note) === '') {
            throw new EngagementRuleViolation('Returning a campaign needs a note.');
        }

        return $this->move($campaign, ['in_review', 'approved'], 'draft', $actor, function (EngagementCampaign $current) use ($actor, $note) {
            Guard::notPreparer($current->prepared_by, $actor, 'return');

            return ['decision_note' => $note, 'submitted_at' => null, 'approved_by' => null, 'approved_at' => null];
        }, AuditAction::Rejected, 'campaign.returned');
    }

    /** Approved → scheduled; launches at once when its start date has come. */
    public function schedule(EngagementCampaign $campaign, User $actor): EngagementCampaign
    {
        Guard::authorise($actor, 'engagement.manage', 'engagement.approve');
        $campaign = $this->move($campaign, ['approved'], 'scheduled', $actor, fn () => [], AuditAction::CampaignScheduled, 'campaign.scheduled');

        return $campaign->starts_on->lte(today()) ? $this->launch($campaign) : $campaign;
    }

    /**
     * Scheduled → active, as one bulk operation: every approved item is published by its own module
     * (an approved survey version is published, an approved announcement is published). Idempotent:
     * a campaign that is already active is returned unchanged.
     */
    public function launch(EngagementCampaign $campaign, ?User $actor = null): EngagementCampaign
    {
        $launched = null;
        $this->audit->operation('engagement', 'Campaign launch', function (string $operationId) use ($campaign, $actor, &$launched) {
            return DB::transaction(function () use ($campaign, $actor, $operationId, &$launched) {
                $current = EngagementCampaign::query()->whereKey($campaign->id)->lockForUpdate()->firstOrFail();
                if ($current->status !== 'scheduled') {
                    $launched = $current;

                    return 0;
                }
                $published = 0;
                $skipped = [];
                foreach (CampaignItem::query()->where('campaign_id', $current->id)->orderBy('position')->get() as $item) {
                    $outcome = $this->launchItem($item, $actor);
                    $outcome === 'published' ? $published++ : ($outcome === 'reference' ? null : $skipped[] = $item->item_type.':'.$item->item_id);
                }
                $campaign->setRawAttributes($current->getAttributes(), true);
                $campaign->update(['status' => 'active', 'launched_at' => now(), 'operation_id' => $operationId, 'lock_version' => $current->lock_version + 1]);
                $this->audit->record(AuditAction::CampaignPublished, 'engagement', $campaign, [['field' => 'status', 'before' => 'scheduled', 'after' => 'active']], null, actor: $actor,
                    metadata: ['published_items' => $published, 'skipped_items' => $skipped, 'partial' => $skipped !== []]);
                EngagementEvent::dispatch('campaign.launched', $campaign, ['campaign' => $campaign->name, 'code' => $campaign->code, 'partial' => $skipped !== []], array_values(array_filter([(int) $campaign->owner_id])));
                $launched = $campaign;

                return ['succeeded' => $published, 'failed' => count($skipped)];
            });
        }, entityType: CampaignItem::class);

        return $launched;
    }

    public function complete(EngagementCampaign $campaign): EngagementCampaign
    {
        return DB::transaction(function () use ($campaign) {
            $current = EngagementCampaign::query()->whereKey($campaign->id)->lockForUpdate()->firstOrFail();
            if ($current->status !== 'active') {
                return $current;
            }
            $campaign->setRawAttributes($current->getAttributes(), true);
            $campaign->update(['status' => 'completed', 'completed_at' => now(), 'lock_version' => $current->lock_version + 1]);
            $this->audit->record(AuditAction::StatusChange, 'engagement', $campaign, [['field' => 'status', 'before' => 'active', 'after' => 'completed']], null);

            return $campaign;
        });
    }

    /** Cancelling stops the campaign; items already published stay with their owning module. */
    public function cancel(EngagementCampaign $campaign, string $reason, User $actor): EngagementCampaign
    {
        Guard::authorise($actor, 'engagement.manage', 'engagement.approve');
        if (trim($reason) === '') {
            throw new EngagementRuleViolation('Cancelling a campaign needs a reason.');
        }

        return $this->move($campaign, ['draft', 'in_review', 'approved', 'scheduled', 'active'], 'cancelled', $actor, fn () => ['cancelled_at' => now(), 'cancel_reason' => $reason, 'workflow_instance_id' => null], AuditAction::CampaignCancelled, 'campaign.cancelled', reason: $reason);
    }

    /**
     * Factual campaign metrics from the owning modules (counts only):
     * - announcements: recipients, sent, failed, skipped, acknowledged;
     * - surveys: eligible and submitted.
     *
     * @return array<string, int>
     */
    public function stats(EngagementCampaign $campaign): array
    {
        $items = CampaignItem::query()->where('campaign_id', $campaign->id)->get()->groupBy('item_type');
        $announcementIds = ($items['announcement'] ?? collect())->pluck('item_id');
        $versionIds = SurveyVersion::query()->whereIn('survey_id', ($items['survey'] ?? collect())->pluck('item_id'))->whereNotNull('opened_at')->pluck('id');
        $recipients = CommunicationRecipient::query()->withoutGlobalScope(AccessScope::class)->whereIn('announcement_id', $announcementIds)->select('status', DB::raw('count(*) as n'))->groupBy('status')->pluck('n', 'status');
        $participation = AccessScope::withoutScoping(fn () => SurveyParticipation::query()->whereIn('survey_version_id', $versionIds)->select('status', DB::raw('count(*) as n'))->groupBy('status')->pluck('n', 'status'));

        return [
            'recipients' => (int) $recipients->sum(), 'sent' => (int) ($recipients['sent'] ?? 0), 'failed' => (int) ($recipients['failed'] ?? 0), 'skipped' => (int) ($recipients['skipped'] ?? 0), 'pending' => (int) ($recipients['pending'] ?? 0),
            'acknowledged' => AnnouncementRead::query()->whereIn('announcement_id', $announcementIds)->whereNotNull('acknowledged_at')->count(),
            'survey_eligible' => (int) $participation->sum(), 'survey_submitted' => (int) ($participation['submitted'] ?? 0),
        ];
    }

    /** @return 'published'|'skipped'|'reference' */
    private function launchItem(CampaignItem $item, ?User $actor): string
    {
        return match ($item->item_type) {
            'survey' => ($version = SurveyVersion::query()->where('survey_id', $item->item_id)->whereIn('status', ['approved', 'scheduled', 'open'])->orderByDesc('version')->first()) === null
                ? 'skipped'
                : ($version->status === 'approved' ? ($this->surveys->publishApproved($version, $actor) ? 'published' : 'skipped') : 'published'),
            'announcement' => ($announcement = Announcement::query()->find($item->item_id)) !== null && in_array($announcement->status, ['approved', 'scheduled', 'published'], true)
                ? ($this->communications->publish($announcement, null) ? 'published' : 'skipped')
                : 'skipped',
            default => 'reference',
        };
    }

    private function move(EngagementCampaign $campaign, array $from, string $to, User $actor, callable $changes, AuditAction $action, string $event, ?callable $after = null, ?string $reason = null): EngagementCampaign
    {
        return DB::transaction(function () use ($campaign, $from, $to, $actor, $changes, $action, $event, $after, $reason) {
            $current = EngagementCampaign::query()->whereKey($campaign->id)->lockForUpdate()->firstOrFail();
            if ((int) $current->lock_version !== (int) $campaign->lock_version) {
                throw new EngagementRuleViolation('The campaign was changed meanwhile. Reload and try again.');
            }
            if (! in_array($current->status, $from, true)) {
                throw new EngagementRuleViolation("A campaign that is {$current->status} cannot become ".str_replace('_', ' ', $to).'.');
            }
            $before = $current->status;
            $extra = $changes($current);
            $campaign->setRawAttributes($current->getAttributes(), true);
            $campaign->update(['status' => $to, 'lock_version' => $current->lock_version + 1, ...$extra]);
            $this->audit->record($action, 'engagement', $campaign, [['field' => 'status', 'before' => $before, 'after' => $to]], $reason ?? ($extra['decision_note'] ?? null), actor: $actor, metadata: ['event' => $event]);
            $recipients = $event === 'campaign.review_requested' ? Guard::holders('engagement.approve', [$actor->id]) : array_values(array_filter([(int) $campaign->prepared_by], fn ($id) => $id !== (int) $actor->id));
            EngagementEvent::dispatch($event, $campaign, ['campaign' => $campaign->name, 'code' => $campaign->code, 'status' => $to], $recipients);
            if ($after) {
                $after($campaign);
            }

            return $campaign;
        });
    }
}
