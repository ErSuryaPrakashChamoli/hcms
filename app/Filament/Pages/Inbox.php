<?php

namespace App\Filament\Pages;

use App\Domain\Experience\Services\ApprovalCenter;
use App\Domain\Experience\Services\ApprovalDecisions;
use App\Domain\Experience\Services\InboxInsight;
use App\Domain\Experience\Services\RequestPreview;
use App\Domain\Experience\Services\RequestTimeline;
use App\Domain\Experience\Services\UnifiedInbox;
use App\Domain\Experience\Support\ApprovalItem;
use App\Domain\Experience\Support\InboxActions;
use App\Domain\Experience\Support\InboxEntry;
use App\Domain\Identity\Models\User;
use App\Domain\Workflow\Exceptions\WorkflowException;
use App\Domain\Workflow\Models\WorkflowTask;
use App\Domain\Workflow\Services\InboxArchives;
use App\Domain\Workflow\Services\SystemTasks;
use App\Domain\Workflow\Services\TaskComments;
use App\Support\Tenancy\TenantContext;
use BackedEnum;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Collection;
use InvalidArgumentException;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Url;
use RuntimeException;
use Throwable;
use UnitEnum;

/**
 * HROne parity RQ-8 (matrix 20 §20.2.1): one inbox over everything waiting for the person — their Approval Center items and
 * their own tasks — grouped by request type with counts and a side search, the "All messages" views and quick filters
 * (unread, overdue, due today / tomorrow, missed by me, relieved employees), a filter panel that fits the request type,
 * keyword search, relative due labels, list + detail with previous / next and a focus mode, tick-and-archive, "Not
 * relevant" for system tasks, "More" (badge, reward points, one-on-one, initiative), a profile peek and the approver's own
 * Insight. Every row comes from the existing reads (UnifiedInbox); every decision goes through ApprovalDecisions to the
 * owning service, exactly as on Approvals. Approvals and the Task inbox are unchanged.
 */
class Inbox extends Page
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedInboxStack;

    protected static string|UnitEnum|null $navigationGroup = 'Workflows';

    protected static ?string $navigationLabel = 'Unified inbox';

    protected static ?string $title = 'Inbox';

    protected static ?string $slug = 'inbox';

    protected static ?int $navigationSort = -4;

    protected string $view = 'filament.pages.experience.inbox';

    public const PAGE = 25;

    public const FILTERS = ['employee' => '', 'from' => '', 'until' => '', 'leave_type' => '', 'ticket_category' => '', 'travel' => '', 'unit' => ''];

    #[Url]
    public string $show = 'all';

    #[Url(except: '')]
    public string $facet = '';

    #[Url(except: '')]
    public string $search = '';

    /** @var array<string, string> */
    public array $filters = self::FILTERS;

    #[Url(as: 'item', except: null)]
    public ?string $selected = null;

    /** §20.2.1 #32: one item at a time, with previous / next (the phone layout always opens items this way). */
    public bool $focus = false;

    public int $shown = self::PAGE;

    /** HROne parity RQ-2: the item whose "Workflow and policy" is open (loaded for one item at a time). */
    public ?string $trailId = null;

    /** §20.2.1 #7: the Insight period (days) and, for holders of an HR or audit right, whose insight ('' = one's own). */
    public string $insightDays = '30';

    public string $insightUser = '';

    public static function canAccess(): bool
    {
        $user = auth()->user();

        return $user !== null && app(TenantContext::class)->has() && UnifiedInbox::opensFor($user);
    }

    public function mount(): void
    {
        abort_unless(static::canAccess(), 403);
        $this->show = isset(UnifiedInbox::SHOWS[$this->show]) ? $this->show : 'all';
        if ($this->selected !== null) {
            $this->open($this->selected);
        }
    }

    public function getHeading(): string
    {
        $count = $this->counts['all'];

        return $count === 0 ? 'Inbox' : 'Inbox · '.$count.' new';
    }

    public function getSubheading(): ?string
    {
        return $this->counts['all'] === 0
            ? 'Nothing is waiting for you. Decisions and tasks that need you appear here.'
            : 'Everything waiting for you in one place: decisions and your own tasks, by request type.';
    }

    // ---- reads -------------------------------------------------------------------------------------------------------

    /** @return Collection<int, InboxEntry> the current view, searched and filtered (every request type) */
    #[Computed]
    public function rows(): Collection
    {
        $inbox = app(UnifiedInbox::class);

        return $inbox->filter($inbox->rows($this->user(), $this->show), null, $this->search, $this->filters);
    }

    /** @return Collection<int, InboxEntry> the rows of the chosen request type */
    #[Computed]
    public function listed(): Collection
    {
        return app(UnifiedInbox::class)->filter($this->rows, $this->facet === '' ? null : $this->facet);
    }

    /** @return array<string, array{label: string, count: int}> */
    #[Computed]
    public function facets(): array
    {
        return app(UnifiedInbox::class)->facets($this->rows);
    }

    /** @return array<string, int> */
    #[Computed]
    public function counts(): array
    {
        return app(UnifiedInbox::class)->counts($this->user());
    }

    /** The filter panel's choices: those of the chosen request type (HROne loads a filter set per category). */
    #[Computed]
    public function options(): array
    {
        $inbox = app(UnifiedInbox::class);

        return $inbox->filterOptions($inbox->filter($inbox->rows($this->user(), $this->show), $this->facet === '' ? null : $this->facet));
    }

    #[Computed]
    public function current(): ?InboxEntry
    {
        $listed = $this->listed;

        return ($this->selected !== null ? $listed->first(fn (InboxEntry $e) => $e->id === $this->selected) : null) ?? ($this->focus ? null : $listed->first());
    }

    /** @return array{index: int, total: int, previous: ?string, next: ?string} */
    #[Computed]
    public function position(): array
    {
        $ids = $this->listed->pluck('id')->values()->all();
        $index = $this->current !== null ? array_search($this->current->id, $ids, true) : false;

        return ['index' => $index === false ? 0 : $index + 1, 'total' => count($ids),
            'previous' => $index !== false && $index > 0 ? $ids[$index - 1] : null, 'next' => $index !== false && $index < count($ids) - 1 ? $ids[$index + 1] : null];
    }

    /** @return list<string> */
    #[Computed]
    public function readIds(): array
    {
        return app(UnifiedInbox::class)->readIds($this->user());
    }

    #[Computed]
    public function peek(): ?array
    {
        return $this->current !== null ? rescue(fn () => app(UnifiedInbox::class)->peek($this->user(), $this->current), null) : null;
    }

    /** @return list<array{key: string, label: string, url: string}> */
    #[Computed]
    public function more(): array
    {
        return $this->current !== null && in_array($this->current->source, ['decision', 'task', 'onboarding'], true) ? app(UnifiedInbox::class)->more($this->user(), $this->current) : [];
    }

    #[Computed]
    public function insight(): ?array
    {
        $user = $this->user();
        $days = (int) $this->insightDays;
        $person = ctype_digit($this->insightUser) && (int) $this->insightUser !== (int) $user->id ? User::forCurrentTenant()->find((int) $this->insightUser) : $user;
        try {
            return app(InboxInsight::class)->for($user, $person ?? $user, $days);
        } catch (AuthorizationException) {
            $this->insightUser = '';

            return app(InboxInsight::class)->for($user, $user, $days);
        }
    }

    /** @return array<int, string> */
    #[Computed]
    public function insightPeople(): array
    {
        return app(InboxInsight::class)->people($this->user());
    }

    public function mayIgnore(InboxEntry $entry): bool
    {
        return app(UnifiedInbox::class)->mayIgnore($this->user(), $entry);
    }

    public function mayRestoreTask(InboxEntry $entry): bool
    {
        $task = $entry->source === 'not_relevant' && $entry->taskId !== null ? WorkflowTask::query()->find($entry->taskId) : null;

        return $task !== null && app(SystemTasks::class)->mayRestore($this->user(), $task);
    }

    /** @return list<string> ids of the listed decisions that may be approved together (bulk approval switched on for their type) */
    public function bulkIds(): array
    {
        return $this->show === 'archived' ? [] : $this->listed->filter(fn (InboxEntry $e) => $e->isDecision() && $e->item->can('approve') && InboxActions::enabled('bulk_approval', $e->item->type))
            ->pluck('id')->values()->all();
    }

    /** @return list<string> ids of the listed decisions that may be archived (Move to archive, or waiting longer than the archive period) */
    public function archivableIds(): array
    {
        $archives = app(InboxArchives::class);

        return $this->show === 'archived' || ! $archives->active() ? [] : $this->listed->filter(fn (InboxEntry $e) => $e->isDecision() && ($archives->offers($e->item) || $archives->bulkEligible($e->item)))
            ->pluck('id')->values()->all();
    }

    // ---- navigation within the inbox ------------------------------------------------------------------------------

    public function setShow(string $show): void
    {
        $this->show = isset(UnifiedInbox::SHOWS[$show]) ? $show : 'all';
        $this->resetList();
    }

    public function setFacet(string $facet): void
    {
        $this->facet = $facet;
        $this->filters = [...self::FILTERS, ...array_intersect_key($this->filters, array_flip(['employee', 'from', 'until', 'unit']))];
        $this->resetList();
    }

    public function clearFilters(): void
    {
        $this->filters = self::FILTERS;
        $this->search = '';
        $this->resetList();
    }

    public function updated(string $name): void
    {
        if ($name === 'search' || str_starts_with($name, 'filters')) {
            $this->filters = array_map(fn ($v) => is_scalar($v) ? (string) $v : '', array_intersect_key($this->filters + self::FILTERS, self::FILTERS));
            $this->resetList();
        }
        if (in_array($name, ['insightDays', 'insightUser'], true)) {
            unset($this->insight);
        }
    }

    /** Open one item: the detail beside the list (or alone, in focus), and it no longer counts as unread. */
    public function open(string $id, bool $focus = false): void
    {
        $entry = app(UnifiedInbox::class)->find($this->user(), $id, $this->show);
        if ($entry === null) {
            $this->selected = null;

            return;
        }
        $this->selected = $entry->id;
        $this->focus = $this->focus || $focus;
        $this->trailId = null;
        app(UnifiedInbox::class)->markRead($this->user(), $entry->id);
        unset($this->current, $this->position, $this->readIds, $this->peek, $this->more);
    }

    public function next(): void
    {
        if (($id = $this->position['next']) !== null) {
            $this->open($id);
        }
    }

    public function previous(): void
    {
        if (($id = $this->position['previous']) !== null) {
            $this->open($id);
        }
    }

    public function setFocus(bool $focus): void
    {
        $this->focus = $focus;
        if ($focus && $this->selected === null && ($first = $this->listed->first()) !== null) {
            $this->open($first->id);
        }
    }

    public function showMore(): void
    {
        $this->shown += self::PAGE;
    }

    // ---- decisions and actions (each through the existing service) --------------------------------------------------

    public function decide(string $id, string $decision, ?string $note = null): void
    {
        if (! in_array($decision, ['approve', 'reject', 'request_change', 'complete', 'send_back'], true)) {
            return;
        }
        $next = $this->position['next'] ?? null;
        try {
            $message = app(ApprovalDecisions::class)->decide($this->user(), $id, $decision, $note);
            Notification::make()->success()->title($message)->send();
            $this->dispatch('pos-approval-decided', id: $id);
            $this->afterChange($id, $next);
        } catch (InvalidArgumentException $e) {
            $this->addError('note.'.$id, $e->getMessage());
        } catch (AuthorizationException|RuntimeException $e) {
            Notification::make()->danger()->title('Not done')->body($e->getMessage())->persistent()->send();
            $this->afterChange();
        }
    }

    /** @param list<string> $ids */
    public function approveSelected(array $ids, ?string $note = null): void
    {
        $result = app(ApprovalDecisions::class)->approveMany($this->user(), array_values(array_filter($ids, 'is_string')), $note);
        if ($result['approved'] !== []) {
            Notification::make()->success()->title(count($result['approved']).' approved')->send();
        }
        if ($result['failed'] !== []) {
            Notification::make()->danger()->title(count($result['failed']).' not approved')->body(implode("\n", array_values($result['failed'])))->persistent()->send();
        }
        $this->dispatch('pos-inbox-bulk-done');
        $this->afterChange();
    }

    /** §20.2.1 #33: archive the ticked items (each must offer "Move to archive" or have waited past the archive period). */
    public function archiveSelected(array $ids): void
    {
        $user = $this->user();
        $center = app(ApprovalCenter::class);
        $center->forget($user);
        $pending = $center->pending($user)->keyBy('id');
        $archives = app(InboxArchives::class);
        $done = 0;
        $failed = [];
        foreach (array_slice(array_values(array_unique(array_filter($ids, 'is_string'))), 0, ApprovalDecisions::BULK_MAX) as $id) {
            $item = $pending->get($id);
            try {
                $item !== null ? $archives->archive($user, $item) : throw new AuthorizationException('No longer waiting for you.');
                $done++;
            } catch (AuthorizationException|RuntimeException $e) {
                $failed[] = ($item?->title ?? $id).': '.$e->getMessage();
            }
        }
        if ($done > 0) {
            Notification::make()->success()->title($done.' archived')->body('They stay pending; restore them from Archive.')->send();
        }
        if ($failed !== []) {
            Notification::make()->danger()->title(count($failed).' not archived')->body(implode("\n", $failed))->persistent()->send();
        }
        $this->dispatch('pos-inbox-bulk-done');
        $this->afterChange();
    }

    /** HROne setup parity P4 (Move to archive) from the approval card. */
    public function archive(string $id): void
    {
        $next = $this->position['next'] ?? null;
        try {
            $item = app(ApprovalCenter::class)->find($this->user(), $id) ?? throw new AuthorizationException('This item is no longer waiting for your decision.');
            app(InboxArchives::class)->archive($this->user(), $item);
            Notification::make()->success()->title('Archived')->body('It stays pending; restore it from Archive.')->send();
            $this->afterChange($id, $next);
        } catch (AuthorizationException|RuntimeException $e) {
            Notification::make()->danger()->title('Not archived')->body($e->getMessage())->send();
        }
    }

    public function restoreArchived(string $id, string $type): void
    {
        try {
            app(InboxArchives::class)->restore($this->user(), $id, $type);
            Notification::make()->success()->title('Restored')->send();
        } catch (RuntimeException $e) {
            Notification::make()->danger()->title('Not restored')->body($e->getMessage())->send();
        }
        $this->afterChange($id);
    }

    /** §20.2.1 #27: close a system task as not relevant (task.ignore, a reason; audited, restorable). */
    public function ignore(string $id, string $reason): void
    {
        $user = $this->user();
        $next = $this->position['next'] ?? null;
        $entry = app(UnifiedInbox::class)->find($user, $id);
        try {
            if ($entry === null || ! app(UnifiedInbox::class)->mayIgnore($user, $entry)) {
                throw new AuthorizationException('This task cannot be marked not relevant by you.');
            }
            app(SystemTasks::class)->ignore($user, $entry->item->record, $reason);
            Notification::make()->success()->title('Marked not relevant')->body('Restore it from "Not relevant" if it turns out to matter.')->send();
            $this->afterChange($id, $next);
        } catch (InvalidArgumentException $e) {
            $this->addError('ignore.'.$id, $e->getMessage());
        } catch (AuthorizationException|WorkflowException $e) {
            Notification::make()->danger()->title('Not done')->body($e->getMessage())->send();
        }
    }

    public function restoreTask(int $taskId, ?string $note = null): void
    {
        $task = WorkflowTask::query()->find($taskId);
        try {
            $task !== null ? app(SystemTasks::class)->restore($this->user(), $task, $note) : throw new AuthorizationException('That task no longer exists.');
            Notification::make()->success()->title('Back in the inbox')->send();
        } catch (AuthorizationException|WorkflowException|InvalidArgumentException $e) {
            Notification::make()->danger()->title('Not restored')->body($e->getMessage())->send();
        }
        $this->afterChange('workflow_task:'.$taskId);
    }

    /** HROne parity RQ-2: the request trail and policy articles of one item of the queue. */
    public function showTrail(string $id): void
    {
        $this->trailId = $id;
    }

    /** @return array{trail: ?array, policies: list<array{title: string, url: ?string}>}|null */
    public function trailFor(ApprovalItem $item): ?array
    {
        if ($this->trailId !== $item->id) {
            return null;
        }
        try {
            $trail = app(RequestTimeline::class)->forItem($this->user(), $item);
            $type = $trail['type'] ?? null;

            return ['trail' => $trail, 'policies' => $type !== null ? app(RequestPreview::class)->policies($type, $this->user()) : []];
        } catch (Throwable $e) {
            report($e);

            return ['trail' => null, 'policies' => []];
        }
    }

    /** HROne setup parity P3 (Discussion) on an item of the viewer's queue. */
    public function comment(string $id, string $body): void
    {
        try {
            $item = app(ApprovalCenter::class)->find($this->user(), $id) ?? throw new AuthorizationException('This item is no longer waiting for your decision.');
            app(TaskComments::class)->postOnItem($this->user(), $item, $body);
            Notification::make()->success()->title('Message posted')->send();
            $this->dispatch('pos-approval-commented', id: $id);
        } catch (InvalidArgumentException $e) {
            $this->addError('comment.'.$id, $e->getMessage());
        } catch (AuthorizationException $e) {
            Notification::make()->danger()->title('Not posted')->body($e->getMessage())->send();
        }
        $this->afterChange();
    }

    // ---- helpers -----------------------------------------------------------------------------------------------------

    private function resetList(): void
    {
        $this->shown = self::PAGE;
        $this->selected = null;
        $this->trailId = null;
        $this->forgetComputed();
    }

    /** After a change to the queue: re-read everything; when the open item left the list, open the next one. */
    private function afterChange(?string $changed = null, ?string $next = null): void
    {
        app(UnifiedInbox::class)->forget($this->user());
        $this->forgetComputed();
        if ($changed !== null && $this->selected === $changed) {
            $this->selected = null;
            if ($next !== null && $this->listed->contains(fn (InboxEntry $e) => $e->id === $next)) {
                $this->selected = $next;
            }
        }
        unset($this->current, $this->position);
    }

    private function forgetComputed(): void
    {
        unset($this->rows, $this->listed, $this->facets, $this->counts, $this->options, $this->current, $this->position, $this->readIds, $this->peek, $this->more, $this->insight);
    }

    private function user(): User
    {
        return auth()->user();
    }
}
