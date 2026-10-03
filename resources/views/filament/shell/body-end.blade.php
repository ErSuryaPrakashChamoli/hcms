@auth
    @if (app(\App\Support\Tenancy\TenantContext::class)->has())
        @livewire(\App\Livewire\Experience\CommandCenter::class)
        @livewire(\App\Livewire\Experience\DrawerHost::class)
        @if (\App\Filament\Pages\AssistantPage::canAccess())
            @livewire(\App\Livewire\Experience\AiAssistant::class)
        @endif
        @include('filament.shell.bottom-nav')
    @endif
@endauth
