<?php

namespace App\Domain\Experience\Services;

use App\Domain\Experience\Models\ExperiencePreference;
use App\Domain\Identity\Models\User;
use App\Support\Tenancy\TenantContext;

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
    ];

    public const MAX_RECENT = 12;

    public const MAX_PINNED = 12;

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
                'snoozed' => collect((array) $value)->filter(fn ($until) => is_string($until) && strtotime($until) > time())->all(),
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

    public function snooze(User $user, string $notificationId, string $until): void
    {
        $this->update($user, ['snoozed' => [...$this->for($user)['snoozed'], $notificationId => $until]]);
    }
}
