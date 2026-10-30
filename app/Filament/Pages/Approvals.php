<?php

namespace App\Filament\Pages;

use App\Domain\Experience\Services\ApprovalCenter;
use App\Domain\Experience\Services\ApprovalDecisions;
use App\Domain\Experience\Services\ContextualIntelligence;
use App\Domain\Experience\Services\RequestPreview;
use App\Domain\Experience\Services\RequestTimeline;
use App\Domain\Experience\Services\RoleLens;
use App\Domain\Experience\Support\ApprovalItem;
use App\Domain\Experience\Support\InboxActions;
use App\Domain\Workflow\Services\InboxArchives;
use App\Domain\Workflow\Services\TaskComments;
use App\Domain\Workflow\Services\TaskDelegations;
use App\Support\Tenancy\TenantContext;
use BackedEnum;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Collection;
use InvalidArgumentException;
use Livewire\Attributes\Computed;
use RuntimeException;
use UnitEnum;

/**
 * UX: the Approval Center (§22). Every decision waiting for the viewer, grouped Urgent / Today /
 * Upcoming, with what, who, why, impact, effective date, risk and requester, and the Before → After
 * where the record has one. Decisions go through ApprovalDecisions to the owning domain service.
 */
class Approvals extends Page
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedCheckBadge;

    protected static string|UnitEnum|null $navigationGroup = 'Workflows';

    protected static ?string $navigationLabel = 'Approvals';

    protected static ?string $title = 'Approvals';

    protected static ?string $slug = 'approvals';

    protected static ?int $navigationSort = -5;

    protected string $view = 'filament.pages.experience.approvals';

    /** @var list<string> ids decided in this session, for the success state */
    public array $decided = [];

    /** UX.15.20: the queue renders in pages (a manager can have hundreds of decisions); counts stay whole. */
    public const PAGE = 25;

    public int $shown = self::PAGE;

    /** HROne parity RQ-2: the item whose request trail ("Workflow and policy") is open — loaded for one item at a time. */
    public ?string $trailId = null;

    public static function canAccess(): bool
    {
        $user = auth()->user();
        if ($user === null || ! app(TenantContext::class)->has()) {
            return false;
        }
        $lenses = app(RoleLens::class);

        return $lenses->has($user, RoleLens::MANAGER) || $user->hasPermission('task.view') || $user->hasPermission('leave.approve')
            || $user->hasPermission('attendance.approve') || $user->hasPermission('compensation.approve') || $user->hasPermission('compensation.review') || $user->hasPermission('letter.issue')
            // HROne setup parity P4: someone deciding a colleague's approvals while that colleague is out of office.
            || app(TaskDelegations::class)->running($user)->isNotEmpty();
    }

    /** UX.15: the workspace says what needs the person, not what the page is. */
    public function getHeading(): string
    {
        $count = $this->pendingCount;

        return match (true) {
            $count === 0 => 'No decisions need you',
            $count === 1 => '1 decision needs you',
            // A source reached its scan limit: say so rather than under-count.
            app(ApprovalCenter::class)->capped(auth()->user()) => $count.'+ decisions need you',
            default => $count.' decisions need you',
        };
    }

    public function getSubheading(): ?string
    {
        $g = $this->groups;
        if ($this->pendingCount === 0) {
            return 'You’re all caught up. Nothing is waiting for your decision.';
        }
        $parts = array_filter([
            $g['urgent']->count() > 0 ? $g['urgent']->count().' urgent' : null,
            $g['today']->count() > 0 ? $g['today']->count().' this week' : null,
            $g['upcoming']->count() > 0 ? $g['upcoming']->count().' later' : null,
        ]);

        $more = app(ApprovalCenter::class)->capped(auth()->user()) ? ' More are waiting than are listed: the earliest come first, and each decision brings the next ones in.' : '';

        return ucfirst(implode(' · ', $parts)).'. Most urgent first; the context comes with each decision.'.$more;
    }

    /** UX.15: PeopleOS Intelligence for the queue (contextual, sourced, gated by the AI policy). */
    #[Computed]
    public function intelligence(): ?array
    {
        $g = $this->groups;

        return app(ContextualIntelligence::class)->forApprovals(auth()->user(), collect(['urgent', 'today', 'upcoming'])->flatMap(fn ($k) => $g[$k])->values());
    }

    #[Computed]
    public function groups(): array
    {
        return app(ApprovalCenter::class)->grouped(auth()->user());
    }

    #[Computed]
    public function pendingCount(): int
    {
        $g = $this->groups;

        return $g['urgent']->count() + $g['today']->count() + $g['upcoming']->count();
    }

    public function showMore(): void
    {
        $this->shown += self::PAGE;
    }

    public function decide(string $id, string $decision, ?string $note = null): void
    {
        if (! in_array($decision, ['approve', 'reject', 'request_change', 'complete', 'send_back'], true)) {
            return;
        }
        try {
            $message = app(ApprovalDecisions::class)->decide(auth()->user(), $id, $decision, $note);
            $this->decided[] = $id;
            Notification::make()->success()->title($message)->send();
            $this->dispatch('pos-approval-decided', id: $id);
        } catch (InvalidArgumentException $e) {
            $this->addError('note.'.$id, $e->getMessage());

            return;
        } catch (AuthorizationException|RuntimeException $e) {
            Notification::make()->danger()->title('Not done')->body($e->getMessage())->persistent()->send();
        }
        unset($this->groups, $this->pendingCount);
    }

    /**
     * HROne setup parity P3 (Bulk approval): approve the ticked items of the request types switched on for it. Each is
     * decided on its own; the ones refused are listed.
     *
     * @param  list<string>  $ids
     */
    public function approveSelected(array $ids, ?string $note = null): void
    {
        $result = app(ApprovalDecisions::class)->approveMany(auth()->user(), array_values(array_filter($ids, 'is_string')), $note);
        $this->decided = [...$this->decided, ...array_keys($result['approved'])];
        if ($result['approved'] !== []) {
            Notification::make()->success()->title(count($result['approved']).' approved')->send();
        }
        if ($result['failed'] !== []) {
            Notification::make()->danger()->title(count($result['failed']).' not approved')->body(implode("\n", array_values($result['failed'])))->persistent()->send();
        }
        $this->dispatch('pos-approval-bulk-done');
        unset($this->groups, $this->pendingCount);
    }

    /** HROne parity RQ-2 (§20.2.1 #20, #26, #30): open the request trail and policy links of one item of the queue. */
    public function showTrail(string $id): void
    {
        $this->trailId = $id;
    }

    /**
     * The trail of an item of the viewer's own queue (the item comes from their Approval Center, never from the browser):
     * earlier levels and their decisions, whom it waits with now, and the policy articles of its request type.
     *
     * @return array{trail: ?array, policies: list<array{title: string, url: ?string}>}|null
     */
    public function trailFor(ApprovalItem $item): ?array
    {
        if ($this->trailId !== $item->id) {
            return null;
        }
        try {
            $trail = app(RequestTimeline::class)->forItem(auth()->user(), $item);
            $type = $trail['type'] ?? null;

            return ['trail' => $trail, 'policies' => $type !== null ? app(RequestPreview::class)->policies($type, auth()->user()) : []];
        } catch (\Throwable $e) {
            report($e);

            return ['trail' => null, 'policies' => []];
        }
    }

    /** HROne setup parity P3 (Discussion): a message on an item of the viewer's queue, for the request types switched on. */
    public function comment(string $id, string $body): void
    {
        try {
            $item = app(ApprovalCenter::class)->find(auth()->user(), $id) ?? throw new AuthorizationException('This item is no longer waiting for your decision.');
            app(TaskComments::class)->postOnItem(auth()->user(), $item, $body);
            Notification::make()->success()->title('Message posted')->send();
            $this->dispatch('pos-approval-commented', id: $id);
        } catch (InvalidArgumentException $e) {
            $this->addError('comment.'.$id, $e->getMessage());
        } catch (AuthorizationException $e) {
            Notification::make()->danger()->title('Not posted')->body($e->getMessage())->send();
        }
        unset($this->groups, $this->pendingCount);
    }

    /** HROne setup parity P4 (Move to archive): hide one item from the viewer's queue without deciding it. */
    public function archive(string $id): void
    {
        try {
            $item = app(ApprovalCenter::class)->find(auth()->user(), $id) ?? throw new AuthorizationException('This item is no longer waiting for your decision.');
            app(InboxArchives::class)->archive(auth()->user(), $item);
            Notification::make()->success()->title('Archived')->body('It stays pending; restore it from Archived below.')->send();
            $this->dispatch('pos-approval-decided', id: $id);
        } catch (AuthorizationException|RuntimeException $e) {
            Notification::make()->danger()->title('Not archived')->body($e->getMessage())->send();
        }
        unset($this->groups, $this->pendingCount, $this->archivedItems);
    }

    /** HROne setup parity P4 (Archive inbox, manual): archive every item waiting longer than the archive period. */
    public function archiveOld(): void
    {
        $archives = app(InboxArchives::class);
        if (! $archives->bulkEnabled() || $archives->mode() !== 'manual') {
            return;
        }
        $user = auth()->user();
        $center = app(ApprovalCenter::class);
        $center->forget($user);
        $count = $archives->archiveOld($user, $center->pending($user), $user);
        Notification::make()->success()->title($count.' archived')->send();
        unset($this->groups, $this->pendingCount, $this->archivedItems);
    }

    /** HROne setup parity P4: bring an archived item back into the queue. */
    public function restore(string $id, string $type): void
    {
        try {
            app(InboxArchives::class)->restore(auth()->user(), $id, $type);
            Notification::make()->success()->title('Restored')->send();
        } catch (RuntimeException $e) {
            Notification::make()->danger()->title('Not restored')->body($e->getMessage())->send();
        }
        unset($this->groups, $this->pendingCount, $this->archivedItems);
    }

    /** @return Collection<int, ApprovalItem> the viewer's archived items still waiting */
    #[Computed]
    public function archivedItems(): Collection
    {
        return app(InboxArchives::class)->active() ? app(ApprovalCenter::class)->archived(auth()->user()) : collect();
    }

    /** How many listed items "Archive requests waiting over N days" would archive (manual bulk archiving), 0 when off. */
    public function bulkArchiveCount(): int
    {
        $archives = app(InboxArchives::class);
        if (! $archives->bulkEnabled() || $archives->mode() !== 'manual') {
            return 0;
        }
        $g = $this->groups;

        return collect(['urgent', 'today', 'upcoming'])->flatMap(fn ($k) => $g[$k])->filter(fn ($i) => $archives->bulkEligible($i))->count();
    }

    /** @return list<string> ids of the listed items that may be approved together (bulk approval switched on for their type) */
    public function bulkIds(): array
    {
        $g = $this->groups;

        return collect(['urgent', 'today', 'upcoming'])->flatMap(fn ($k) => $g[$k])
            ->filter(fn ($i) => $i->can('approve') && InboxActions::enabled('bulk_approval', $i->type))->pluck('id')->values()->all();
    }
}
