<?php

namespace App\Domain\Experience\Services;

use App\Domain\Experience\Models\ExperiencePreference;
use App\Domain\Identity\Models\User;
use App\Support\Tenancy\TenantContext;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;

/**
 * UX: a person's presentation preferences. Only known keys are stored; values are normalised and
 * capped. Nothing here grants access: pinned people and recent items are re-checked against the
 * viewer's permissions whenever they are shown.
 */
final class ExperiencePreferences
{
    public const DEFAULTS = [
        'density' => 'comfortable',
        'lens' => null,
        'pinned_people' => [],
        'recent' => [],
        'favourite_reports' => [],
        'home_hidden' => [],
        'snoozed' => [],
        'welcomed_at' => null,
        // UX.15: when Home was last opened, for "what changed since your last visit" (presentation only).
        'home_seen_at' => null,
        'home_prev_seen_at' => null,
        // HROne parity WO-1: saved views per register (register key => list of views), written through SavedViews.
        'saved_views' => [],
        // HROne parity RQ-1: favourite request types (keys, shown first in "New request") and requests the person moved out
        // of their "My requests" list ("type:id"; a hide flag only, nothing changes on the request).
        'favourite_requests' => [],
        'hidden_requests' => [],
        // HROne parity MS-1: favourite My space pages (item keys, newest first), shown first on My space and in the avatar menu.
        'favourite_pages' => [],
        // HROne parity RQ-8: unified inbox items the person has opened (item ids, newest first), for the "Unread" view.
        'inbox_read' => [],
    ];

    public const MAX_RECENT = 12;

    public const MAX_PINNED = 12;

    /** HROne parity RQ-1: requests a person may keep hidden from "My requests" (the oldest hide drops off first). */
    public const MAX_HIDDEN_REQUESTS = 500;

    /** HROne parity RQ-8: opened inbox items remembered for "Unread" (the oldest drops off first). */
    public const MAX_INBOX_READ = 1000;

    /** @var array<int, array<string, mixed>> */
    private array $cache = [];

    public function __construct(private readonly TenantContext $tenants) {}

    /** @return array<string, mixed> */
    public function for(?User $user): array
    {
        if ($user === null || ! $this->tenants->has()) {
            return self::DEFAULTS;
        }

        return $this->cache[$user->id] ??= array_replace(self::DEFAULTS, (array) (ExperiencePreference::query()->where('user_id', $user->id)->value('preferences') ?? []));
    }

    /** @param  array<string, mixed>  $changes */
    public function update(User $user, array $changes): array
    {
        if (! $this->tenants->has()) {
            return self::DEFAULTS;
        }
        $current = $this->for($user);
        foreach ($changes as $key => $value) {
            if (! array_key_exists($key, self::DEFAULTS)) {
                continue;
            }
            $current[$key] = match ($key) {
                'density' => in_array($value, ['comfortable', 'compact'], true) ? $value : 'comfortable',
                'lens' => is_string($value) && in_array($value, RoleLens::ALL, true) ? $value : null,
                'pinned_people', 'favourite_reports' => array_values(array_slice(array_unique(array_map('intval', (array) $value)), 0, self::MAX_PINNED)),
                'home_hidden' => array_values(array_unique(array_filter((array) $value, 'is_string'))),
                'snoozed' => collect((array) $value)->filter(fn ($until) => is_string($until) && strtotime($until) > now()->getTimestamp())->all(),
                'welcomed_at', 'home_seen_at', 'home_prev_seen_at' => is_string($value) && strtotime($value) !== false ? $value : null,
                'saved_views' => SavedViews::normalise($value),
                'favourite_requests', 'favourite_pages' => array_values(array_slice(array_unique(array_filter((array) $value, fn ($k) => is_string($k) && preg_match('/^[a-z0-9_.]{1,64}$/', $k) === 1)), 0, self::MAX_PINNED)),
                'hidden_requests' => array_values(array_slice(array_unique(array_filter((array) $value, fn ($k) => is_string($k) && preg_match('/^[a-z0-9_.]{1,64}:\d{1,20}$/', $k) === 1)), 0, self::MAX_HIDDEN_REQUESTS)),
                'inbox_read' => array_values(array_slice(array_unique(array_filter((array) $value, fn ($k) => is_string($k) && preg_match('/^[a-z0-9_.]{1,64}:[A-Za-z0-9_.-]{1,64}$/', $k) === 1)), 0, self::MAX_INBOX_READ)),
                default => $value,
            };
        }
        ExperiencePreference::query()->updateOrCreate(['user_id' => $user->id], ['preferences' => $current]);

        return $this->cache[$user->id] = $current;
    }

    /** @param  array{type: string, id: int|string, label: string, url: string, meta?: string}  $item */
    public function pushRecent(User $user, array $item): void
    {
        $recent = collect($this->for($user)['recent'])->reject(fn ($r) => ($r['type'] ?? null) === $item['type'] && (string) ($r['id'] ?? '') === (string) $item['id'])
            ->prepend(['type' => $item['type'], 'id' => $item['id'], 'label' => mb_substr($item['label'], 0, 120), 'url' => $item['url'], 'meta' => mb_substr((string) ($item['meta'] ?? ''), 0, 120)])
            ->take(self::MAX_RECENT)->values()->all();
        $this->update($user, ['recent' => $recent]);
    }

    public function togglePin(User $user, int $employeeId): bool
    {
        $pinned = $this->for($user)['pinned_people'];
        $isPinned = in_array($employeeId, $pinned, true);
        $this->update($user, ['pinned_people' => $isPinned ? array_values(array_diff($pinned, [$employeeId])) : [$employeeId, ...$pinned]]);

        return ! $isPinned;
    }

    /** HROne parity RQ-1: star or unstar a request type in "New request" (newest first, at most MAX_PINNED). */
    public function toggleFavouriteRequest(User $user, string $key): bool
    {
        $favourites = $this->for($user)['favourite_requests'];
        $isFavourite = in_array($key, $favourites, true);
        $this->update($user, ['favourite_requests' => $isFavourite ? array_values(array_diff($favourites, [$key])) : [$key, ...$favourites]]);

        return ! $isFavourite;
    }

    /** HROne parity MS-1: heart or un-heart a My space item (newest first, at most MAX_PINNED; the oldest drops off). */
    public function toggleFavouritePage(User $user, string $key): bool
    {
        $favourites = $this->for($user)['favourite_pages'];
        $isFavourite = in_array($key, $favourites, true);
        $this->update($user, ['favourite_pages' => $isFavourite ? array_values(array_diff($favourites, [$key])) : [$key, ...$favourites]]);

        return ! $isFavourite;
    }

    /** HROne parity RQ-1: hide or show again one of the person's requests in "My requests" ("type:id"). */
    public function toggleHiddenRequest(User $user, string $key): bool
    {
        $hidden = $this->for($user)['hidden_requests'];
        $isHidden = in_array($key, $hidden, true);
        $this->update($user, ['hidden_requests' => $isHidden ? array_values(array_diff($hidden, [$key])) : [$key, ...$hidden]]);

        return ! $isHidden;
    }

    /** HROne parity RQ-8: remember that the person opened an inbox item (presentation only; nothing changes on the item). */
    public function markInboxRead(User $user, string $itemId): void
    {
        $read = $this->for($user)['inbox_read'];
        if (($read[0] ?? null) === $itemId) {
            return;
        }
        $this->update($user, ['inbox_read' => [$itemId, ...array_values(array_diff($read, [$itemId]))]]);
    }

    /**
     * UX.15: record a Home visit and answer "since when" for "what changed since your last visit". A visit
     * within 30 minutes of the previous one keeps the earlier reference, so a refresh does not reset it.
     */
    public function markHomeVisit(User $user): ?CarbonInterface
    {
        $prefs = $this->for($user);
        $seen = $prefs['home_seen_at'] ? Carbon::parse($prefs['home_seen_at']) : null;
        if ($seen === null || $seen->lt(now()->subMinutes(30))) {
            $prefs = $this->update($user, ['home_prev_seen_at' => $seen?->toIso8601String(), 'home_seen_at' => now()->toIso8601String()]);
        }

        return $prefs['home_prev_seen_at'] ? Carbon::parse($prefs['home_prev_seen_at']) : null;
    }

    public function snooze(User $user, string $notificationId, string $until): void
    {
        $this->update($user, ['snoozed' => [...$this->for($user)['snoozed'], $notificationId => $until]]);
    }
}
