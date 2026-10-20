<?php

namespace App\Livewire\Experience;

use App\Domain\Experience\Services\ChangeFeed;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Lazy;
use Livewire\Component;

/**
 * UX: "What changed?" (§13), loaded after the page so Home paints first (§44). The feed is the
 * permission-aware ChangeFeed read model; filters only narrow what it already allows.
 */
#[Lazy]
class ChangeFeedPanel extends Component
{
    public string $type = 'all';

    public int $limit = 8;

    /** Compact timeline for the Home rail. */
    public bool $compact = false;

    public function placeholder(): View
    {
        return view('livewire.experience.skeleton', ['rows' => $this->compact ? 5 : 4, 'title' => 'What changed']);
    }

    public function filter(string $type): void
    {
        $this->type = $type;
    }

    public function more(): void
    {
        $this->limit = min(40, $this->limit + 8);
    }

    public function render(): View
    {
        $all = app(ChangeFeed::class)->for(auth()->user(), 40);
        $types = $all->pluck('label', 'type')->unique()->all();
        $items = ($this->type === 'all' ? $all : $all->where('type', $this->type))->take($this->limit)->values();

        return view($this->compact ? 'livewire.experience.change-feed-rail' : 'livewire.experience.change-feed-panel', [
            'items' => $items,
            'types' => $types,
            'hasMore' => ($this->type === 'all' ? $all->count() : $all->where('type', $this->type)->count()) > $this->limit,
        ]);
    }
}
