<?php

namespace App\Filament\Pages;

use App\Domain\Experience\Services\ExperiencePreferences;
use App\Domain\Experience\Services\WorkInbox;
use App\Support\Tenancy\TenantContext;
use BackedEnum;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Collection;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Url;
use UnitEnum;

/**
 * UX: My Work (§23). One inbox: Needs attention / Today / Upcoming / Waiting on others / Completed.
 * Rows deep-link to the screen or drawer that owns the action.
 */
class MyWork extends Page
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedInboxStack;

    protected static string|UnitEnum|null $navigationGroup = 'Me';

    protected static ?string $navigationLabel = 'My work';

    protected static ?string $title = 'My work';

    protected static ?string $slug = 'my-work';

    protected static ?int $navigationSort = -9;

    protected string $view = 'filament.pages.experience.my-work';

    public const TABS = ['needs_attention' => 'Needs attention', 'today' => 'Today', 'upcoming' => 'Upcoming', 'waiting' => 'Waiting on others', 'completed' => 'Completed'];

    #[Url]
    public ?string $tab = null;

    public static function canAccess(): bool
    {
        return auth()->check() && app(TenantContext::class)->has();
    }

    #[Computed]
    public function inbox(): array
    {
        return app(WorkInbox::class)->for(auth()->user());
    }

    public function activeTab(): string
    {
        if ($this->tab !== null && array_key_exists($this->tab, self::TABS)) {
            return $this->tab;
        }
        foreach (array_keys(self::TABS) as $key) {
            if ($this->inbox[$key]->isNotEmpty()) {
                return $key;
            }
        }

        return 'needs_attention';
    }

    public function setTab(string $tab): void
    {
        $this->tab = array_key_exists($tab, self::TABS) ? $tab : null;
    }

    /** UX.15.20: each stream shows its first rows; the rest on request (counts stay whole). */
    public const PAGE = 15;

    /** @var list<string> */
    public array $expanded = [];

    public function showAll(string $key): void
    {
        if (in_array($key, ['decisions', 'tasks', 'followups', 'waiting', 'upcoming', 'completed'], true) && ! in_array($key, $this->expanded, true)) {
            $this->expanded[] = $key;
        }
    }

    /** UX.15 filters over one focus workspace (legacy ?tab= keys still deep-link). */
    public const FILTERS = ['all' => 'Everything', 'decisions' => 'Decisions', 'tasks' => 'Tasks', 'followups' => 'Follow-ups', 'waiting' => 'Waiting on others', 'completed' => 'Done'];

    public function filter(): string
    {
        return match ($this->tab) {
            'decisions', 'tasks', 'followups', 'waiting', 'completed' => $this->tab,
            default => 'all',
        };
    }

    public function setFilter(string $filter): void
    {
        $this->tab = array_key_exists($filter, self::FILTERS) && $filter !== 'all' ? $filter : null;
    }

    /**
     * The workspace: the one thing to do next, then streams by kind. Everything comes from WorkInbox (the
     * existing read model); this only arranges it.
     *
     * @return array{next: ?array, decisions: Collection, tasks: Collection, followups: Collection, waiting: Collection, upcoming: Collection, completed: Collection}
     */
    #[Computed]
    public function work(): array
    {
        $inbox = $this->inbox;
        $snoozed = app(ExperiencePreferences::class)->for(auth()->user())['snoozed'] ?? [];
        $open = $inbox['needs_attention']->merge($inbox['today']);
        $byKind = fn (Collection $rows, string $kind) => $rows->where('kind', $kind)->values();

        return [
            'next' => $open->reject(fn ($r) => isset($snoozed['home:'.$r['key']]))->first(fn ($r) => $r['approval_id'] || $r['url']),
            'decisions' => $byKind($open->merge($inbox['upcoming']), 'approval'),
            'tasks' => $byKind($open, 'task'),
            'followups' => $byKind($open, 'attention'),
            'waiting' => $inbox['waiting'],
            'upcoming' => $inbox['upcoming']->where('kind', '!=', 'approval')->values(),
            'completed' => $inbox['completed'],
        ];
    }

    /** "Later": hide the next item until tomorrow (the item itself is unchanged; Home uses the same snooze). */
    public function later(string $key): void
    {
        app(ExperiencePreferences::class)->snooze(auth()->user(), 'home:'.mb_substr($key, 0, 120), now()->addDay()->startOfDay()->toIso8601String());
        unset($this->work);
    }

    public function getSubheading(): ?string
    {
        $w = $this->work;
        $today = $w['decisions']->count() + $w['tasks']->count() + $w['followups']->count();
        if ($today === 0 && $w['waiting']->isEmpty()) {
            return 'You’re all caught up. No decisions require your attention right now.';
        }
        $parts = array_filter([
            $w['next'] ? 'Start with '.(($w['next']['subject'] ?? null) ? $w['next']['subject'].'’s '.mb_strtolower($w['next']['title']) : '“'.$w['next']['title'].'”').'.' : null,
            $today > 1 ? ($today - ($w['next'] ? 1 : 0)).' more '.($today - ($w['next'] ? 1 : 0) === 1 ? 'thing needs' : 'things need').' you.' : null,
            $w['waiting']->isNotEmpty() ? $w['waiting']->count().' of your requests '.($w['waiting']->count() === 1 ? 'is' : 'are').' with others.' : null,
        ]);

        return implode(' ', $parts);
    }
}
