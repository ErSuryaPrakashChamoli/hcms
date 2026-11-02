<span id="pos-main" tabindex="-1" class="sr-only">Main content</span>
@auth
    {{-- UX.15 area bar: its own component, so starring a module re-renders the bar alone (the page keeps it on its updates) --}}
    @if (app(\App\Domain\Experience\Services\ExperienceNavigation::class)->areaBar(auth()->user()) !== null)
        @livewire(\App\Livewire\Experience\AreaBar::class, [], key('pos-area-bar'))
    @endif
@endauth
