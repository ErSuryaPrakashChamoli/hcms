<?php

namespace App\Filament\Pages;

use App\Domain\Experience\Services\WorkInbox;
use App\Support\Tenancy\TenantContext;
use BackedEnum;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
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

    public function getSubheading(): ?string
    {
        $open = $this->inbox['needs_attention']->count() + $this->inbox['today']->count();

        return $open === 0 ? 'You are up to date.' : $open.' '.($open === 1 ? 'item needs' : 'items need').' you today.';
    }
}
