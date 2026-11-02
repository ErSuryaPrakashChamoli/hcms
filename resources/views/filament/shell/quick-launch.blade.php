@auth
    @if (app(\App\Support\Tenancy\TenantContext::class)->has())
        {{-- HROne parity AC-1: HR actions by module (the Actions panel), for people who have any to run. --}}
        @if (app(\App\Domain\Experience\Services\ActionCatalogue::class)->hasAny(auth()->user()))
            <button type="button" class="pos-btn pos-btn-secondary pos-btn-sm pos-quick-launch pos-actions-launch" x-data x-on:click="$dispatch('pos-actions-open')" aria-haspopup="dialog" title="HR actions by module">
                <x-filament::icon icon="heroicon-m-bolt" class="size-4" />
                <span class="pos-quick-launch-label">Actions</span>
            </button>
        @endif
        <button type="button" class="pos-btn pos-btn-primary pos-btn-sm pos-quick-launch" x-data x-on:click="$dispatch('pos-command-open', { mode: 'actions' })" aria-haspopup="dialog" title="Start something (press N)">
            <x-filament::icon icon="heroicon-m-plus" class="size-4" />
            <span class="pos-quick-launch-label">New</span>
        </button>
    @endif
@endauth
