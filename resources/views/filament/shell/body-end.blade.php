@auth
    {{-- The top-bar search button and Ctrl K open the command center for every signed-in user, including an operator
         outside any tenant (navigation results only; tenant data fails closed). --}}
    @livewire(\App\Livewire\Experience\CommandCenter::class)
    @if (app(\App\Support\Tenancy\TenantContext::class)->has())
        @livewire(\App\Livewire\Experience\DrawerHost::class)
        @livewire(\App\Livewire\Experience\PeekHost::class)
        @if (\App\Filament\Pages\AssistantPage::canAccess())
            @livewire(\App\Livewire\Experience\AiAssistant::class)
        @endif
        @include('filament.shell.bottom-nav')
    @endif
@endauth
