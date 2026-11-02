<?php

namespace App\Livewire\Experience;

use App\Domain\Experience\Services\ExperienceNavigation;
use Illuminate\Contracts\View\View;
use Livewire\Component;

/**
 * UX.15 area bar, as its own component so a star re-renders the bar alone (not the page and its table). It reads
 * the page from Livewire's original URL, so a re-render on the update route shows the same area.
 */
class AreaBar extends Component
{
    public function toggleFavourite(string $key): void
    {
        $user = auth()->user();
        if ($user !== null) {
            app(ExperienceNavigation::class)->toggleFavouriteModule($user, $key);
        }
    }

    public function render(): View
    {
        $user = auth()->user();

        return view('livewire.experience.area-bar', [
            'bar' => $user === null ? null : app(ExperienceNavigation::class)->areaBar($user),
        ]);
    }
}
