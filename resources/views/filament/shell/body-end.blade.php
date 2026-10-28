@auth
    {{-- The top-bar search button and Ctrl K open the command center for every signed-in user, including an operator
         outside any tenant (navigation results only; tenant data fails closed). --}}
    @livewire(\App\Livewire\Experience\CommandCenter::class)
    {{-- Back to top: shown once the page is well scrolled (html[data-pos-far], peopleos.js). --}}
    <button type="button" class="pos-to-top" aria-label="Back to top" onclick="window.posToTop()">
        <svg viewBox="0 0 20 20" aria-hidden="true" focusable="false"><path d="M10 15.5V4.75M5 9.5l5-5 5 5" /></svg>
    </button>
    @if (app(\App\Support\Tenancy\TenantContext::class)->has())
        @livewire(\App\Livewire\Experience\DrawerHost::class)
        @livewire(\App\Livewire\Experience\PeekHost::class)
        @if (\App\Filament\Pages\AssistantPage::canAccess())
            @livewire(\App\Livewire\Experience\AiAssistant::class)
        @endif
        @include('filament.shell.bottom-nav')
    @endif
@endauth
