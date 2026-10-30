<?php

namespace App\Domain\Experience\Services;

use App\Domain\Experience\Contracts\MySpaceItem;
use App\Domain\Identity\Models\User;
use App\Domain\Platform\Services\SettingsRepository;
use App\Support\Tenancy\TenantContext;
use Illuminate\Contracts\Container\Container;
use Throwable;

/**
 * HROne parity MS-1: "My space" — the person's own pages registered under the `peopleos.myspace_items` tag, by key, and
 * the ones the signed-in person may open now. Read only: every entry links to an existing page or list.
 *
 * Availability is each destination's own canAccess() for the signed-in person (asked only for them; fail closed: an item
 * that throws is not offered). The organisation may leave items out of My space (`myspace.hidden_items`): hiding never
 * grants anything and never blocks the page itself. Favourites (`ExperiencePreferences` key `favourite_pages`, at most
 * twelve) are shown first on My space and in the avatar menu; a favourite that is no longer available is simply not shown.
 */
final class MySpace
{
    /** The groups in display order (HROne's module chips: Performance, Time office, Engage, Payroll / Expense …). */
    public const GROUPS = [
        'profile' => 'Profile',
        'time' => 'Time',
        'pay' => 'Pay',
        'performance' => 'Performance',
        'engage' => 'Engage',
        'requests' => 'Requests',
    ];

    /** The Setup centre setting listing items left out of My space (registry keys; default none). */
    public const HIDDEN_SETTING = 'myspace.hidden_items';

    /** @var array<string, MySpaceItem>|null */
    private ?array $items = null;

    /** @var array<string, mixed> per-request answers (the registry is request-scoped) */
    private array $memo = [];

    public function __construct(private readonly Container $app) {}

    /** @return array<string, MySpaceItem> every registered item, by key, in registration order */
    public function all(): array
    {
        if ($this->items === null) {
            $this->items = [];
            foreach ($this->app->tagged('peopleos.myspace_items') as $item) {
                $this->items[$item->key()] = $item;
            }
        }

        return $this->items;
    }

    public function find(string $key): ?MySpaceItem
    {
        return $this->all()[$key] ?? null;
    }

    /**
     * The items the signed-in person may open now (each with its URL), less the ones the organisation left out of My
     * space, ordered by group then label.
     *
     * @return list<array{item: MySpaceItem, url: string}>
     */
    public function available(User $user): array
    {
        if (! $this->signedIn($user)) {
            return [];
        }

        return $this->memo['available:'.$this->memoKey($user)] ??= $this->gather($user);
    }

    /** Whether the signed-in person may open this item from My space now. */
    public function isAvailable(User $user, string $key): bool
    {
        return $this->entry($user, $key) !== null;
    }

    /**
     * One item for the signed-in person, when they may open it and the organisation did not leave it out of My space.
     *
     * @return array{item: MySpaceItem, url: string}|null
     */
    public function entry(User $user, string $key): ?array
    {
        $item = $this->find($key);
        if ($item === null || ! $this->signedIn($user)) {
            return null;
        }
        $memo = 'entry:'.$this->memoKey($user).':'.$key;
        if (! array_key_exists($memo, $this->memo)) {
            $hidden = $this->memo['hidden:'.(app(TenantContext::class)->id() ?? 0)] ??= ($this->attempt(fn () => $this->hidden()) ?? []);
            // HROne parity CA-9 (page access per My space item): left out when every role of the person leaves it out.
            // A failure to read the roles hides everything (fail closed).
            $byRoles = $this->memo['roles:'.$this->memoKey($user)] ??= ($this->attempt(fn () => $this->hiddenByRoles($user)) ?? array_keys($this->all()));
            $url = in_array($key, $hidden, true) || in_array($key, $byRoles, true) || ! array_key_exists($item->group(), self::GROUPS)
                ? null : $this->attempt(fn () => $item->available($user) ? $item->url($user) : null);
            $this->memo[$memo] = is_string($url) && $url !== '' ? ['item' => $item, 'url' => $url] : null;
        }

        return $this->memo[$memo];
    }

    /**
     * The person's favourites they may still open, in the order they were added (newest first).
     *
     * @return list<array{item: MySpaceItem, url: string}>
     */
    public function favourites(User $user): array
    {
        // Only the hearted items are asked (the avatar menu reads this on every page; most people heart a few or none).
        return array_values(array_filter(array_map(fn (string $key) => $this->entry($user, $key), $this->favouriteKeys($user))));
    }

    /** @return list<string> the favourite keys the person stored (whether or not still available) */
    public function favouriteKeys(User $user): array
    {
        return array_values(array_filter((array) (app(ExperiencePreferences::class)->for($user)['favourite_pages'] ?? []), 'is_string'));
    }

    /** Heart or un-heart an item the signed-in person may open (others are refused: false, nothing stored). */
    public function toggleFavourite(User $user, string $key): bool
    {
        if (! $this->isAvailable($user, $key)) {
            return false;
        }
        app(ExperiencePreferences::class)->toggleFavouritePage($user, $key);

        return true;
    }

    /** @return list<string> the registered keys the organisation left out of My space */
    public function hidden(): array
    {
        $chosen = array_map('strval', (array) app(SettingsRepository::class)->get(self::HIDDEN_SETTING, []));

        return array_values(array_intersect($chosen, array_keys($this->all())));
    }

    /**
     * HROne parity CA-9 (Access rights → Page access, per My space item): the items the person's roles leave out of My
     * space. Roles add up as permissions do: an item stays when any active role of the person leaves it in, so it is left
     * out only when every one of them leaves it out (a person without roles: nothing). A role whose stored list is not a
     * list of keys leaves everything out (it gives no page); a key no item has is ignored. Hiding never grants anything
     * and the pages keep their own access rules.
     *
     * @return list<string>
     */
    public function hiddenByRoles(User $user): array
    {
        $all = array_keys($this->all());
        $roles = $user->roles()->where('roles.status', 'active')->get(['roles.id', 'roles.myspace_hidden_items'])->unique('id');
        if ($roles->isEmpty()) {
            return [];
        }
        $hidden = $all;
        foreach ($roles as $role) {
            $raw = $role->getAttributes()['myspace_hidden_items'] ?? null;
            $list = $raw === null ? [] : json_decode((string) $raw, true);
            $list = is_array($list) && array_is_list($list) && array_filter($list, fn ($k) => ! is_string($k)) === [] ? $list : $all;
            $hidden = array_values(array_intersect($hidden, $list));
            if ($hidden === []) {
                break;
            }
        }

        return $hidden;
    }

    /** @return array<string, string> key => "Group · Label" of every registered item (the hidden-items setting's options) */
    public function options(): array
    {
        $options = [];
        foreach ($this->all() as $key => $item) {
            $options[$key] = (self::GROUPS[$item->group()] ?? 'Other').' · '.$item->label();
        }
        asort($options);

        return $options;
    }

    /** Forget the per-request answers (after the person's permissions, favourites or the setting changed). */
    public function forget(): void
    {
        $this->memo = [];
    }

    /** @return list<array{item: MySpaceItem, url: string}> */
    private function gather(User $user): array
    {
        $out = array_values(array_filter(array_map(fn ($key) => $this->entry($user, (string) $key), array_keys($this->all()))));
        $groups = array_flip(array_keys(self::GROUPS));
        usort($out, fn (array $a, array $b) => [$groups[$a['item']->group()], mb_strtolower($a['item']->label())] <=> [$groups[$b['item']->group()], mb_strtolower($b['item']->label())]);

        return $out;
    }

    private function memoKey(User $user): string
    {
        return (app(TenantContext::class)->id() ?? 0).':'.$user->getKey();
    }

    private function signedIn(User $user): bool
    {
        return auth()->check() && (int) auth()->id() === (int) $user->getKey() && app(TenantContext::class)->has();
    }

    private function attempt(callable $callback): mixed
    {
        try {
            return $callback();
        } catch (Throwable $e) {
            report($e);

            return null;
        }
    }
}
