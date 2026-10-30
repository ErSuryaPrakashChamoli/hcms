<?php

namespace App\Domain\Workflow\Services;

use App\Domain\Experience\Services\ApprovalCenter;
use App\Domain\Experience\Services\ScreenAccess;
use App\Domain\Experience\Support\ApprovalItem;
use App\Domain\Experience\Support\InboxActions;
use App\Domain\Identity\Enums\UserStatus;
use App\Domain\Identity\Models\User;
use App\Domain\Platform\Services\SettingsRepository;
use App\Domain\Workflow\Models\InboxArchive;
use App\Support\Tenancy\TenantContext;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Throwable;

/**
 * HROne setup parity P4 (Inbox → Move to archive, Archive inbox). A person archives Approval Center items to clear
 * their queue without deciding them: the request stays pending for everyone else, its SLA and escalation run on, and
 * the item comes back when restored. Settings (all off by default):
 *   - inbox.archive.types: request types that offer "Archive" on each item (manual);
 *   - inbox.archive.enabled + inbox.archive.mode (manual | auto) + inbox.archive.after_days + inbox.archive.excluded_types:
 *     bulk archiving of items waiting longer than N days, by the person ("Archive requests waiting over N days") or
 *     every night (peopleos:inbox:archive), every request type except the excluded ones.
 * The auto-archive never archives again an item the person restored. With archiving switched off entirely, archived
 * items show again.
 */
final class InboxArchives
{
    /** @var array<string, array<string, InboxArchive>> tenant:user => type|id => archive row (archived or restored) */
    private array $rows = [];

    public function __construct(private readonly SettingsRepository $settings, private readonly TenantContext $tenants) {}

    /** @return list<string> request types offering the per-item Archive action (inbox.archive.types) */
    public function manualKinds(): array
    {
        return InboxActions::kinds('archive');
    }

    public function bulkEnabled(): bool
    {
        return (bool) $this->settings->get('inbox.archive.enabled', false);
    }

    public function mode(): string
    {
        return $this->settings->get('inbox.archive.mode', 'manual') === 'auto' ? 'auto' : 'manual';
    }

    public function afterDays(): int
    {
        return max(1, min(365, (int) $this->settings->get('inbox.archive.after_days', 90)));
    }

    /** @return list<string> */
    public function excludedKinds(): array
    {
        return array_values(array_map('strval', array_filter((array) $this->settings->get('inbox.archive.excluded_types', []))));
    }

    /** Whether archiving is in use at all (otherwise archived items show again and nothing is read). */
    public function active(): bool
    {
        return $this->manualKinds() !== [] || $this->bulkEnabled();
    }

    /** The per-item Archive action is offered for this item. */
    public function offers(ApprovalItem $item): bool
    {
        return in_array($item->type, $this->manualKinds(), true);
    }

    /** The item may be archived by the bulk action or the nightly run: archiving on, not excluded, waiting long enough. */
    public function bulkEligible(ApprovalItem $item): bool
    {
        $since = $item->requestedAt ?? $item->dueAt;

        return $this->bulkEnabled() && ! in_array($item->type, $this->excludedKinds(), true)
            && $since !== null && $since->lte(now()->subDays($this->afterDays()));
    }

    public function isArchived(User $user, ApprovalItem $item): bool
    {
        $row = $this->rowsFor($user)[$item->type.'|'.$item->id] ?? null;

        return $row !== null && $row->restored_at === null;
    }

    /** The person archives one item of their queue (types offering Archive). */
    public function archive(User $user, ApprovalItem $item): InboxArchive
    {
        if (! $this->offers($item) && ! $this->bulkEligible($item)) {
            throw new AuthorizationException('This request cannot be archived.');
        }

        return $this->store($user, $item, $user);
    }

    /**
     * Archive the items of a person's queue waiting longer than the setting allows. Manual: the person, any item not
     * archived now. Automatic ($by null): only items never archived before, so a restored item stays in the queue.
     *
     * @param  Collection<int, ApprovalItem>  $items
     */
    public function archiveOld(User $user, Collection $items, ?User $by): int
    {
        if (! $this->bulkEnabled()) {
            throw new RuntimeException('Bulk archiving is not switched on (Setup → Automation → Archive inbox).');
        }
        $rows = $this->rowsFor($user);
        $count = 0;
        foreach ($items as $item) {
            $row = $rows[$item->type.'|'.$item->id] ?? null;
            if (! $this->bulkEligible($item) || ($row !== null && ($by === null || $row->restored_at === null))) {
                continue;
            }
            $this->store($user, $item, $by);
            $count++;
        }

        return $count;
    }

    /** Bring an archived item back into the person's queue. */
    public function restore(User $user, string $itemId, string $type): InboxArchive
    {
        return DB::transaction(function () use ($user, $itemId, $type) {
            $row = InboxArchive::query()->where('user_id', $user->id)->where('item_id', $itemId)->where('item_type', $type)->whereNull('restored_at')->lockForUpdate()->first()
                ?? throw new RuntimeException('That request is not archived.');
            $row->update(['restored_at' => now(), 'restored_by' => $user->id]);
            $this->forget($user);

            return $row;
        });
    }

    /** @return array<string, InboxArchive> type|id => the person's archive rows (archived and restored) */
    public function rowsFor(User $user): array
    {
        if (! $this->tenants->has()) {
            return [];
        }
        $key = $this->tenants->id().':'.$user->id;

        return $this->rows[$key] ??= InboxArchive::query()->where('user_id', $user->id)->get()
            ->keyBy(fn (InboxArchive $a) => $a->item_type.'|'.$a->item_id)->all();
    }

    /**
     * The nightly auto-archive for the current tenant: every active person's queue (as they see it), items waiting longer
     * than the setting allows. Returns the number archived.
     */
    public function autoArchive(): int
    {
        if (! $this->bulkEnabled() || $this->mode() !== 'auto') {
            return 0;
        }
        $center = app(ApprovalCenter::class);
        $count = 0;
        User::forCurrentTenant()->where('status', UserStatus::Active->value)->where('is_platform_admin', false)->orderBy('id')
            ->each(function (User $user) use ($center, &$count) {
                try {
                    $center->forget($user);
                    $items = ScreenAccess::as($user, fn () => $center->pending($user));
                    $count += $this->archiveOld($user, $items, null);
                } catch (Throwable $e) {
                    report($e);
                } finally {
                    $center->forget($user);
                }
            });

        return $count;
    }

    private function store(User $user, ApprovalItem $item, ?User $by): InboxArchive
    {
        return DB::transaction(function () use ($user, $item, $by) {
            $row = InboxArchive::query()->where('user_id', $user->id)->where('item_id', $item->id)->where('item_type', $item->type)->lockForUpdate()->first();
            $values = ['archived_at' => now(), 'archived_by' => $by?->id, 'restored_at' => null, 'restored_by' => null, 'title' => mb_substr($item->title, 0, 255)];
            if ($row === null) {
                $row = InboxArchive::create(['user_id' => $user->id, 'item_id' => $item->id, 'item_type' => $item->type] + $values);
            } elseif ($row->restored_at !== null) {
                $row->update($values);
            }
            $this->forget($user);

            return $row;
        });
    }

    private function forget(User $user): void
    {
        unset($this->rows[$this->tenants->id().':'.$user->id]);
        app(ApprovalCenter::class)->forget($user);
    }
}
