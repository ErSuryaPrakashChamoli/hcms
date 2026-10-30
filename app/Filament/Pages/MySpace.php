<?php

namespace App\Filament\Pages;

use App\Domain\Experience\Contracts\MySpaceItem;
use App\Domain\Experience\Services\MySpace as MySpaceRegistry;
use App\Domain\Identity\Services\CurrentEmployee;
use App\Support\Tenancy\TenantContext;
use BackedEnum;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Url;
use UnitEnum;

/**
 * HROne parity MS-1 (matrix 22 §22.1, §22.1.1 — the rail "My space" flyout and its "View all" dialog): every one of the
 * person's own pages they may open, grouped (Profile, Time, Pay, Performance, Engage, Requests) with a chip per group, a
 * search, and a heart on each item; hearted items (at most twelve) come first here and in the avatar menu. Each item opens
 * the existing page under that page's own access rule (MySpace registry), so nothing here grants or blocks anything.
 */
class MySpace extends Page
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedSquares2x2;

    protected static string|UnitEnum|null $navigationGroup = 'Me';

    protected static ?string $navigationLabel = 'My space';

    protected static ?string $title = 'My space';

    protected static ?string $slug = 'my-space';

    protected static ?int $navigationSort = 0;

    protected string $view = 'filament.pages.experience.my-space';

    #[Url]
    public string $search = '';

    #[Url]
    public string $group = 'all';

    /** Cheap on purpose (the navigation and the avatar menu ask on every page): a tenant user with an employee record of their own. */
    public static function canAccess(): bool
    {
        $user = auth()->user();

        return $user !== null && app(TenantContext::class)->has() && app(CurrentEmployee::class)->of($user) !== null;
    }

    public function mount(): void
    {
        abort_unless(static::canAccess(), 403);
        if ($this->group !== 'all' && ! array_key_exists($this->group, MySpaceRegistry::GROUPS)) {
            $this->group = 'all';
        }
    }

    public function getSubheading(): ?string
    {
        return 'Your own pages in one place. Heart the ones you use most: they come first here and in your avatar menu.';
    }

    /** Filter by group ("All" or one of MySpace::GROUPS); anything else shows everything. */
    public function setGroup(string $group): void
    {
        $this->group = array_key_exists($group, MySpaceRegistry::GROUPS) ? $group : 'all';
        unset($this->catalogue);
    }

    /**
     * @return array{favourites: list<array<string, mixed>>, groups: array<string, array{label: string, items: list<array<string, mixed>>}>, chips: array<string, array{label: string, count: int}>, total: int}
     */
    #[Computed]
    public function catalogue(): array
    {
        $user = auth()->user();
        $registry = app(MySpaceRegistry::class);
        $favourites = $registry->favouriteKeys($user);
        $entries = array_map(fn (array $e) => $this->entry($e['item'], $e['url'], in_array($e['item']->key(), $favourites, true)), $registry->available($user));
        $chips = [];
        foreach (MySpaceRegistry::GROUPS as $key => $label) {
            $count = count(array_filter($entries, fn (array $e) => $e['group'] === $key));
            if ($count > 0) {
                $chips[$key] = ['label' => $label, 'count' => $count];
            }
        }
        $matching = array_values(array_filter($entries, fn (array $e) => $this->matches($e) && ($this->group === 'all' || $e['group'] === $this->group)));
        $groups = [];
        foreach ($matching as $entry) {
            $groups[$entry['group']] ??= ['label' => MySpaceRegistry::GROUPS[$entry['group']], 'items' => []];
            $groups[$entry['group']]['items'][] = $entry;
        }
        $byKey = array_column($matching, null, 'key');

        return [
            'favourites' => array_values(array_filter(array_map(fn (string $key) => $byKey[$key] ?? null, $favourites))),
            'groups' => $groups,
            'chips' => $chips,
            'total' => count($entries),
        ];
    }

    /** Heart or un-heart an item the person may open (at most twelve; the oldest heart drops off). */
    public function toggleFavourite(string $key): void
    {
        app(MySpaceRegistry::class)->toggleFavourite(auth()->user(), $key);
        unset($this->catalogue);
    }

    /** @return array<string, mixed> */
    private function entry(MySpaceItem $item, string $url, bool $favourite): array
    {
        return ['key' => $item->key(), 'label' => $item->label(), 'description' => $item->description(), 'icon' => $item->icon(), 'group' => $item->group(),
            'group_label' => MySpaceRegistry::GROUPS[$item->group()] ?? '', 'keywords' => $item->keywords(), 'url' => $url, 'favourite' => $favourite];
    }

    /** @param  array<string, mixed>  $entry */
    private function matches(array $entry): bool
    {
        $search = mb_strtolower(trim($this->search));
        if ($search === '') {
            return true;
        }
        $haystack = mb_strtolower(implode(' ', [$entry['label'], $entry['description'], $entry['group_label'], ...$entry['keywords']]));

        return str_contains($haystack, $search);
    }
}
