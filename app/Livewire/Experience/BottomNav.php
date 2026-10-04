<?php

namespace App\Livewire\Experience;

use App\Domain\Experience\Services\ExperienceNavigation;
use App\Domain\Experience\Services\MobileNavigation;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Locked;
use Livewire\Attributes\On;
use Livewire\Component;

/**
 * UX.17: the phone and tablet bar. It follows the person's current experience (MobileNavigation) and
 * re-renders when they switch views on Home or in Preferences, so the bar and Home never disagree. The
 * page's route is kept from the first render (a re-render runs on Livewire's update route).
 */
class BottomNav extends Component
{
    #[Locked]
    public string $route = '';

    public function mount(): void
    {
        $this->route = (string) request()->route()?->getName();
    }

    #[On('pos-experience-changed')]
    public function experienceChanged(): void
    {
        // Re-render with the stored experience.
    }

    public function render(): View
    {
        $user = auth()->user();
        $experience = $user === null ? 'employee' : app(ExperienceNavigation::class)->experience($user);

        return view('livewire.experience.bottom-nav', [
            'items' => $user === null ? [] : app(MobileNavigation::class)->items($user, $experience, $this->route),
            'experience' => $experience,
        ]);
    }
}
