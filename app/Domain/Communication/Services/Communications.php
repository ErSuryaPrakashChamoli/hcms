<?php

namespace App\Domain\Communication\Services;

use App\Domain\Audit\Enums\AuditAction;
use App\Domain\Audit\Services\AuditRecorder;
use App\Domain\Communication\Events\CommunicationEvent;
use App\Domain\Communication\Jobs\DeliverCommunication;
use App\Domain\Communication\Models\Announcement;
use App\Domain\Communication\Models\AnnouncementRead;
use App\Domain\Communication\Models\CommunicationRecipient;
use App\Domain\Configuration\Services\EmployeeRuleContext;
use App\Domain\Configuration\Services\RuleEngine;
use App\Domain\Employment\Models\Employee;
use App\Domain\Engagement\Exceptions\EngagementRuleViolation;
use App\Domain\Engagement\Models\Audience;
use App\Domain\Engagement\Services\AudienceQuery;
use App\Domain\Engagement\Support\Guard;
use App\Domain\Identity\Models\User;
use App\Domain\Identity\Scopes\AccessScope;
use App\Domain\Workflow\Models\Workflow;
use App\Domain\Workflow\Services\WorkflowEngine;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;

/**
 * Communication centre (§51), Phase 13:
 * - prepared by communication.manage, approved by a second person (communication.approve) or a
 *   configured workflow;
 * - published on its date with the audience snapshotted in SQL inside the preparer's scope;
 * - delivered through the existing Notifier by CommunicationDelivery;
 * - read and acknowledged by employees, with acknowledgement locked and recorded once.
 *
 * Communication is an intentional organisational message, not a transactional notification. It
 * reuses the notification infrastructure and adds no second engine.
 */
final class Communications
{
    public function __construct(
        private readonly RuleEngine $rules,
        private readonly EmployeeRuleContext $context,
        private readonly AuditRecorder $audit,
        private readonly AudienceQuery $audiences,
        private readonly WorkflowEngine $workflows,
    ) {}

    /** @param  array<string, mixed>  $data */
    public function create(array $data, User $actor, ?string $idempotencyKey = null): Announcement
    {
        Guard::authorise($actor, 'communication.manage');
        if ($idempotencyKey !== null && ($existing = Announcement::query()->where('idempotency_key', $idempotencyKey)->first())) {
            return $existing;
        }
        $clean = $this->content($data);

        try {
            return DB::transaction(function () use ($clean, $actor, $idempotencyKey) {
                $announcement = Announcement::query()->create([...$clean, 'status' => 'draft', 'author_id' => $actor->id, 'prepared_by' => $actor->id, 'idempotency_key' => $idempotencyKey]);
                $this->audit->record(AuditAction::AnnouncementCreated, 'communication', $announcement, [], null, actor: $actor, metadata: ['type' => $announcement->type]);

                return $announcement;
            });
        } catch (UniqueConstraintViolationException) {
            return Announcement::query()->where('idempotency_key', $idempotencyKey)->firstOrFail();
        }
    }

    /** @param  array<string, mixed>  $data */
    public function update(Announcement $announcement, array $data, User $actor): Announcement
    {
        Guard::authorise($actor, 'communication.manage');
        if ($announcement->status !== 'draft') {
            throw new EngagementRuleViolation('Only a draft announcement is edited; create a new version to correct a submitted one.');
        }
        $announcement->update($this->content($data + $announcement->only(['title', 'type', 'priority', 'body', 'audience_id', 'audience_criteria', 'is_pinned', 'requires_acknowledgement', 'publish_at', 'expires_at', 'article_id', 'campaign_id', 'attachment_path', 'attachment_name', 'attachment_sha256'])));

        return $announcement;
    }

    public function submit(Announcement $announcement, User $actor): Announcement
    {
        Guard::authorise($actor, 'communication.manage');

        return $this->move($announcement, ['draft'], 'in_review', $actor, function (Announcement $current) use ($actor) {
            if (trim((string) $current->body) === '' || trim((string) $current->title) === '') {
                throw new EngagementRuleViolation('The announcement has no content.');
            }
            if (! empty($current->audience)) {
                throw new EngagementRuleViolation('Rule-based audiences are replaced by structured audiences; choose an audience or criteria.');
            }
            $criteria = $current->audience_id ? (Audience::query()->whereKey($current->audience_id)->where('status', 'active')->value('criteria') ?? throw new EngagementRuleViolation('The chosen audience is inactive.')) : ($current->audience_criteria ?? []);
            $criteria = $this->audiences->normalise(is_array($criteria) ? $criteria : (array) json_decode((string) $criteria, true));
            if (! $this->audiences->withinScope($criteria, $actor)) {
                throw new EngagementRuleViolation('The audience names people or organisation units outside your scope.');
            }
            if ($current->expires_at !== null && $current->expires_at->lte($current->publish_at ?? now())) {
                throw new EngagementRuleViolation('The announcement would expire before it is published.');
            }

            return ['audience_criteria' => $criteria, 'prepared_by' => $actor->id, 'scope_user_id' => $actor->id, 'submitted_at' => now(), 'decision_note' => null];
        }, AuditAction::Submitted, 'communication.review_requested', function (Announcement $submitted) use ($actor) {
            $key = config('peopleos.engagement.approval_workflows.announcement');
            $workflow = $key ? Workflow::query()->where('key', $key)->where('status', 'active')->first() : null;
            if ($workflow && $workflow->published()->exists()) {
                $instance = $this->workflows->start($workflow, $submitted, ['announcement' => ['title' => $submitted->title, 'type' => $submitted->type]], $actor);
                Announcement::query()->whereKey($submitted->id)->update(['workflow_instance_id' => $instance->id]);
                $submitted->workflow_instance_id = $instance->id;
            }
        });
    }

    public function approve(Announcement $announcement, ?string $note, User $actor): Announcement
    {
        Guard::authorise($actor, 'communication.approve');

        return $this->move($announcement, ['in_review'], 'approved', $actor, function (Announcement $current) use ($actor, $note) {
            if ($current->workflow_instance_id) {
                throw new EngagementRuleViolation('This announcement is decided by its approval workflow.');
            }
            Guard::notPreparer($current->prepared_by, $actor, 'approve');
            if (! $this->audiences->withinScope($current->audience_criteria ?? [], $actor)) {
                throw new EngagementRuleViolation('The audience is outside your scope; an approver who covers it must decide.');
            }

            return ['approved_by' => $actor->id, 'approved_at' => now(), 'decision_note' => $note];
        }, AuditAction::AnnouncementApproved, 'communication.approved');
    }

    public function returnToDraft(Announcement $announcement, string $note, User $actor): Announcement
    {
        Guard::authorise($actor, 'communication.approve');
        if (trim($note) === '') {
            throw new EngagementRuleViolation('Returning an announcement needs a note.');
        }

        return $this->move($announcement, ['in_review', 'approved'], 'draft', $actor, function (Announcement $current) use ($actor, $note) {
            if ($current->workflow_instance_id) {
                throw new EngagementRuleViolation('This announcement is decided by its approval workflow.');
            }
            Guard::notPreparer($current->prepared_by, $actor, 'return');

            return ['decision_note' => $note, 'submitted_at' => null, 'approved_by' => null, 'approved_at' => null];
        }, AuditAction::Rejected, 'communication.returned');
    }

    /**
     * Approved → scheduled (future publish date) or published now. Publication snapshots the audience
     * and queues delivery. Idempotent: an announcement already scheduled or published is returned as
     * it is. A null actor is the scheduler or an approved campaign launching it.
     */
    public function publish(Announcement $announcement, ?User $actor = null): Announcement
    {
        if ($actor !== null) {
            Guard::authorise($actor, 'communication.manage', 'communication.approve');
        }
        $fresh = Announcement::query()->findOrFail($announcement->id);
        if (in_array($fresh->status, ['scheduled', 'published'], true)) {
            return $fresh->status === 'scheduled' && ($fresh->publish_at === null || $fresh->publish_at->lte(now())) ? $this->release($fresh, $actor) : $fresh;
        }
        $scheduled = $this->move($announcement, ['approved'], 'scheduled', $actor, fn () => ['publish_at' => $announcement->publish_at ?? now()], AuditAction::StatusChange, 'communication.scheduled');

        return $scheduled->publish_at->lte(now()) ? $this->release($scheduled, $actor) : $scheduled;
    }

    /** Scheduled → published: the audience snapshot (bulk, one operation id) and the delivery job. */
    public function release(Announcement $announcement, ?User $actor = null): Announcement
    {
        $released = null;
        $operationId = $this->audit->operation('communication', 'Announcement audience snapshot', function (string $operationId) use ($announcement, $actor, &$released) {
            return DB::transaction(function () use ($announcement, $actor, $operationId, &$released) {
                $current = Announcement::query()->whereKey($announcement->id)->lockForUpdate()->firstOrFail();
                if ($current->status !== 'scheduled') {
                    $released = $current;

                    return 0;
                }
                if ($current->publish_at !== null && $current->publish_at->isFuture()) {
                    throw new EngagementRuleViolation('This announcement is scheduled for later.');
                }
                $scopeUser = $current->scope_user_id ? User::query()->find($current->scope_user_id) : null;
                $count = 0;
                AccessScope::withoutScoping(function () use ($current, $scopeUser, &$count) {
                    $this->audiences->query($current->audience_criteria ?? [], $scopeUser)->select('employees.id', 'employees.user_id')->orderBy('employees.id')
                        ->chunk(500, function ($employees) use ($current, &$count) {
                            $now = now();
                            $count += CommunicationRecipient::query()->insertOrIgnore($employees->map(fn ($e) => [
                                'tenant_id' => $current->tenant_id, 'announcement_id' => $current->id, 'employee_id' => $e->id, 'user_id' => $e->user_id, 'status' => 'pending', 'attempts' => 0, 'created_at' => $now, 'updated_at' => $now,
                            ])->all());
                        });
                });
                if ($current->supersedes_id !== null) {
                    $old = Announcement::query()->whereKey($current->supersedes_id)->lockForUpdate()->first();
                    if ($old !== null && $old->status === 'published') {
                        $old->update(['status' => 'archived']);
                        $this->audit->record(AuditAction::StatusChange, 'communication', $old, [['field' => 'status', 'before' => 'published', 'after' => 'archived']], "Superseded by version {$current->version}", actor: $actor);
                    }
                }
                $announcement->setRawAttributes($current->getAttributes(), true);
                $announcement->update(['status' => 'published', 'published_at' => now(), 'publish_at' => $current->publish_at ?? now(), 'recipients_count' => $count, 'operation_id' => $operationId, 'lock_version' => $current->lock_version + 1]);
                $this->audit->record(AuditAction::AudienceUsed, 'communication', $announcement, [], null, actor: $actor, metadata: ['purpose' => 'announcement', 'recipients' => $count, 'criteria' => array_keys($current->audience_criteria ?? [])]);
                $this->audit->record(AuditAction::AnnouncementPublished, 'communication', $announcement, [['field' => 'status', 'before' => 'scheduled', 'after' => 'published']], null, actor: $actor, metadata: ['type' => $current->type, 'recipients' => $count]);
                $released = $announcement;

                return ['succeeded' => $count];
            });
        }, entityType: CommunicationRecipient::class);

        if ($released->operation_id === $operationId) {
            DB::afterCommit(fn () => DeliverCommunication::dispatch((int) $released->tenant_id, (int) $released->id));
            CommunicationEvent::dispatch('communication.published', $released, $this->refs($released), array_values(array_filter([(int) $released->prepared_by], fn ($id) => $id !== (int) $actor?->id)));
        }

        return $released;
    }

    /** A new draft version of a submitted / published announcement; it replaces the old one when it is published. */
    public function newVersion(Announcement $announcement, User $actor): Announcement
    {
        Guard::authorise($actor, 'communication.manage');
        if (! in_array($announcement->status, ['published', 'scheduled', 'approved', 'in_review'], true)) {
            throw new EngagementRuleViolation('Only a submitted or published announcement gets a new version.');
        }
        if (Announcement::query()->where('supersedes_id', $announcement->id)->whereNotIn('status', ['cancelled'])->exists()) {
            throw new EngagementRuleViolation('A newer version of this announcement already exists.');
        }

        return $this->create([...$announcement->only(['title', 'type', 'priority', 'body', 'audience_id', 'audience_criteria', 'is_pinned', 'requires_acknowledgement', 'expires_at', 'article_id', 'campaign_id', 'attachment_path', 'attachment_name', 'attachment_sha256']),
            'version' => $announcement->version + 1, 'supersedes_id' => $announcement->id], $actor);
    }

    public function cancel(Announcement $announcement, string $reason, User $actor): Announcement
    {
        Guard::authorise($actor, 'communication.manage', 'communication.approve');
        if (trim($reason) === '') {
            throw new EngagementRuleViolation('Cancelling an announcement needs a reason.');
        }

        return $this->move($announcement, ['in_review', 'approved', 'scheduled'], 'cancelled', $actor, fn () => ['cancelled_at' => now(), 'workflow_instance_id' => null], AuditAction::StatusChange, 'communication.cancelled', reason: $reason);
    }

    public function archive(Announcement $announcement, ?User $actor = null): Announcement
    {
        if ($actor !== null) {
            Guard::authorise($actor, 'communication.manage', 'communication.approve');
        }

        return $this->move($announcement, ['published'], 'archived', $actor, fn () => [], AuditAction::StatusChange, 'communication.archived');
    }

    /**
     * One private attachment per announcement (draft only), on the document disk under
     * tenants/{tenant}/communication/{id}/, fingerprinted. It is reached only through a short-lived
     * signed link whose route re-checks the audience.
     */
    public function attach(Announcement $announcement, UploadedFile|string $file, ?string $name, User $actor): Announcement
    {
        Guard::authorise($actor, 'communication.manage');
        if ($announcement->status !== 'draft') {
            throw new EngagementRuleViolation('Attachments are added while the announcement is a draft.');
        }
        $diskName = config('peopleos.documents.disk', 'local');
        $disk = Storage::disk($diskName);
        $directory = "tenants/{$announcement->tenant_id}/communication/{$announcement->id}";
        if ($file instanceof UploadedFile) {
            $original = $name ?? $file->getClientOriginalName();
            $this->assertAttachment($original, (int) $file->getSize());
            $path = $file->storeAs($directory, Str::ulid().'.'.strtolower($file->getClientOriginalExtension()), $diskName);
        } else {
            $source = ltrim(str_replace(['\\', '..'], ['/', ''], $file), '/');
            if (! Str::startsWith($source, ['communication/', $directory.'/']) || ! $disk->exists($source)) {
                throw new EngagementRuleViolation('The attachment upload was not found.');
            }
            $original = $name ?? basename($source);
            $this->assertAttachment($original, (int) $disk->size($source));
            $path = $directory.'/'.Str::ulid().'.'.strtolower(pathinfo($source, PATHINFO_EXTENSION));
            $disk->move($source, $path);
        }
        $announcement->update(['attachment_path' => $path, 'attachment_name' => Str::limit($original, 250, ''), 'attachment_sha256' => hash('sha256', (string) $disk->get($path))]);

        return $announcement;
    }

    public function attachmentUrl(Announcement $announcement, int $minutes = 15): ?string
    {
        return $announcement->attachment_path === null ? null : URL::temporarySignedRoute('announcements.attachment', now()->addMinutes($minutes), ['announcement' => $announcement->id]);
    }

    /** Readers: the published announcement's audience; staff: preparers and approvers. */
    public function canDownload(Announcement $announcement, User $user): bool
    {
        if ($user->hasPermission('communication.manage') || $user->hasPermission('communication.approve')) {
            return true;
        }
        $employee = AccessScope::withoutScoping(fn () => Employee::query()->where('user_id', $user->id)->first());

        return $employee !== null && $announcement->isLive() && $this->inAudience($announcement, $employee);
    }

    private function assertAttachment(string $name, int $bytes): void
    {
        if (! in_array(strtolower(pathinfo($name, PATHINFO_EXTENSION)), config('peopleos.documents.mimes', []), true)) {
            throw new EngagementRuleViolation('That file type is not accepted ('.implode(', ', config('peopleos.documents.mimes', [])).').');
        }
        if ($bytes > 1024 * (int) config('peopleos.documents.max_kb', 10240)) {
            throw new EngagementRuleViolation('The attachment is too large.');
        }
    }

    /** Is the employee in the announcement's audience? Snapshot rows decide; pre-Phase 13 rows keep their rule. */
    public function inAudience(Announcement $announcement, Employee $employee): bool
    {
        if ($announcement->recipients_count !== null) {
            return CommunicationRecipient::query()->withoutGlobalScope(AccessScope::class)->where('announcement_id', $announcement->id)->where('employee_id', $employee->id)->exists();
        }

        return empty($announcement->audience) || $this->rules->matches($announcement->audience, $this->context->build($employee));
    }

    /** Live announcements for this employee, pinned first, newest first (database-side; legacy rule rows filtered after). */
    public function feedFor(Employee $employee): Collection
    {
        return $this->liveFor($employee)->get()
            ->filter(fn (Announcement $a) => $a->recipients_count !== null || empty($a->audience) || $this->rules->matches($a->audience, $this->context->build($employee)))->values();
    }

    public function markRead(Announcement $announcement, Employee $employee): AnnouncementRead
    {
        $this->assertReaches($announcement, $employee);
        AnnouncementRead::query()->insertOrIgnore(['tenant_id' => $announcement->tenant_id, 'announcement_id' => $announcement->id, 'employee_id' => $employee->id, 'read_at' => now(), 'created_at' => now(), 'updated_at' => now()]);

        return AnnouncementRead::query()->where('announcement_id', $announcement->id)->where('employee_id', $employee->id)->firstOrFail();
    }

    /** Acknowledge once (locked): concurrent or repeated acknowledgements record one time and one audit event. */
    public function acknowledge(Announcement $announcement, Employee $employee, string $source = 'web'): AnnouncementRead
    {
        $this->assertReaches($announcement, $employee);
        if (! $announcement->requires_acknowledgement) {
            return $this->markRead($announcement, $employee);
        }

        return DB::transaction(function () use ($announcement, $employee, $source) {
            // One acknowledgement at a time per person. Serialise first on the recipient row of the
            // snapshot (the employee row for pre-Phase 13 items): a plain row lock taken before anything
            // else. Without it, two calls each take a shared lock on an existing read row (insert-ignore
            // duplicate check) and deadlock upgrading it.
            $recipient = $announcement->recipients_count !== null && CommunicationRecipient::query()->withoutGlobalScope(AccessScope::class)
                ->where('announcement_id', $announcement->id)->where('employee_id', $employee->id)->lockForUpdate()->exists();
            if (! $recipient) {
                Employee::query()->withoutGlobalScope(AccessScope::class)->whereKey($employee->id)->lockForUpdate()->exists();
            }
            AnnouncementRead::query()->insertOrIgnore(['tenant_id' => $announcement->tenant_id, 'announcement_id' => $announcement->id, 'employee_id' => $employee->id, 'read_at' => now(), 'created_at' => now(), 'updated_at' => now()]);
            $read = AnnouncementRead::query()->where('announcement_id', $announcement->id)->where('employee_id', $employee->id)->lockForUpdate()->firstOrFail();
            if ($read->acknowledged_at !== null) {
                return $read;
            }
            $read->update(['acknowledged_at' => now(), 'source' => $source]);
            $this->audit->record(AuditAction::AnnouncementAcknowledged, 'communication', $announcement, [], null, metadata: ['employee_id' => $employee->id, 'version' => $announcement->version]);

            return $read;
        });
    }

    public function pendingAcknowledgements(Employee $employee): Collection
    {
        return $this->feedFor($employee)->filter(fn (Announcement $a) => $a->requires_acknowledgement)
            ->reject(fn (Announcement $a) => AnnouncementRead::query()->where('announcement_id', $a->id)->where('employee_id', $employee->id)->whereNotNull('acknowledged_at')->exists())
            ->values();
    }

    /** @return array{audience: int, read: int, acknowledged: int, sent?: int, failed?: int, skipped?: int, pending?: int} */
    public function stats(Announcement $announcement): array
    {
        $reads = AnnouncementRead::query()->where('announcement_id', $announcement->id);
        $base = ['read' => (clone $reads)->count(), 'acknowledged' => (clone $reads)->whereNotNull('acknowledged_at')->count()];
        if ($announcement->recipients_count === null) {
            $audience = $announcement->status === 'published' ? Employee::query()->employed()->get()->filter(fn (Employee $e) => $this->inAudience($announcement, $e))->count() : 0;

            return ['audience' => $audience] + $base;
        }
        $status = CommunicationRecipient::query()->withoutGlobalScope(AccessScope::class)->where('announcement_id', $announcement->id)
            ->select('status', DB::raw('count(*) as n'))->groupBy('status')->pluck('n', 'status');

        return ['audience' => (int) $announcement->recipients_count] + $base + collect(config('peopleos.communication.recipient_statuses'))->map(fn ($l, $s) => (int) ($status[$s] ?? 0))->all();
    }

    /** @return array<string, mixed> references for events (never the body or recipients) */
    public function refs(Announcement $announcement): array
    {
        return ['title' => $announcement->title, 'type' => config("peopleos.communication.types.{$announcement->type}", $announcement->type), 'version' => $announcement->version,
            'priority' => $announcement->priority, 'acknowledge' => (bool) $announcement->requires_acknowledgement];
    }

    private function liveFor(Employee $employee): Builder
    {
        return Announcement::query()->where('status', 'published')
            ->where(fn ($q) => $q->whereNull('publish_at')->orWhere('publish_at', '<=', now()))
            ->where(fn ($q) => $q->whereNull('expires_at')->orWhere('expires_at', '>', now()))
            ->where(fn ($q) => $q->whereNull('recipients_count')->orWhereExists(fn ($sub) => $sub->from('communication_recipients')->whereColumn('communication_recipients.announcement_id', 'announcements.id')->where('communication_recipients.employee_id', $employee->id)))
            ->orderByDesc('is_pinned')->orderByDesc('publish_at')->orderByDesc('id');
    }

    private function assertReaches(Announcement $announcement, Employee $employee): void
    {
        if (! $announcement->isLive() || ! $this->inAudience($announcement, $employee)) {
            throw new EngagementRuleViolation('That announcement is not addressed to you.');
        }
    }

    /** @return array<string, mixed> */
    private function content(array $data): array
    {
        $type = $data['type'] ?? 'announcement';
        if (! array_key_exists($type, config('peopleos.communication.types'))) {
            throw new EngagementRuleViolation('Unknown communication type.');
        }
        $priority = $data['priority'] ?? 'normal';
        if (! array_key_exists($priority, config('peopleos.communication.priorities'))) {
            throw new EngagementRuleViolation('Unknown priority.');
        }
        if (blank($data['title'] ?? null)) {
            throw new EngagementRuleViolation('An announcement needs a title.');
        }
        $criteria = $data['audience_criteria'] ?? null;

        return [
            'title' => trim((string) $data['title']), 'type' => $type, 'priority' => $priority, 'body' => (string) ($data['body'] ?? ''),
            'audience_id' => filled($data['audience_id'] ?? null) ? (int) $data['audience_id'] : null,
            'audience_criteria' => is_array($criteria) ? $this->audiences->normalise($criteria) : null,
            'is_pinned' => (bool) ($data['is_pinned'] ?? false), 'requires_acknowledgement' => (bool) ($data['requires_acknowledgement'] ?? false),
            'publish_at' => $data['publish_at'] ?? null, 'expires_at' => $data['expires_at'] ?? null,
            'article_id' => filled($data['article_id'] ?? null) ? (int) $data['article_id'] : null, 'campaign_id' => filled($data['campaign_id'] ?? null) ? (int) $data['campaign_id'] : null,
            'attachment_path' => $data['attachment_path'] ?? null, 'attachment_name' => $data['attachment_name'] ?? null, 'attachment_sha256' => $data['attachment_sha256'] ?? null,
            ...(isset($data['version']) ? ['version' => (int) $data['version'], 'supersedes_id' => $data['supersedes_id'] ?? null] : []),
        ];
    }

    private function move(Announcement $announcement, array $from, string $to, ?User $actor, callable $changes, AuditAction $action, string $event, ?callable $after = null, ?string $reason = null): Announcement
    {
        return DB::transaction(function () use ($announcement, $from, $to, $actor, $changes, $action, $event, $after, $reason) {
            $current = Announcement::query()->whereKey($announcement->id)->lockForUpdate()->firstOrFail();
            if ((int) $current->lock_version !== (int) $announcement->lock_version) {
                throw new EngagementRuleViolation('The announcement was changed meanwhile. Reload and try again.');
            }
            if (! in_array($current->status, $from, true)) {
                throw new EngagementRuleViolation("An announcement that is {$current->status} cannot become ".str_replace('_', ' ', $to).'.');
            }
            $before = $current->status;
            $extra = $changes($current);
            $announcement->setRawAttributes($current->getAttributes(), true);
            $announcement->update(['status' => $to, 'lock_version' => $current->lock_version + 1, ...$extra]);
            $this->audit->record($action, 'communication', $announcement, [['field' => 'status', 'before' => $before, 'after' => $to]], $reason ?? ($extra['decision_note'] ?? null), actor: $actor, metadata: ['event' => $event]);
            $recipients = match ($event) {
                'communication.review_requested' => Guard::holders('communication.approve', [(int) $actor?->id]),
                default => array_values(array_filter([(int) $announcement->prepared_by], fn ($id) => $id !== (int) $actor?->id)),
            };
            CommunicationEvent::dispatch($event, $announcement, $this->refs($announcement), $recipients);
            if ($after) {
                $after($announcement);
            }

            return $announcement;
        });
    }
}
