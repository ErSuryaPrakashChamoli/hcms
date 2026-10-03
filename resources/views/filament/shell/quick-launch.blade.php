@auth
    @if (app(\App\Support\Tenancy\TenantContext::class)->has())
        <button type="button" class="pos-btn pos-btn-primary pos-btn-sm pos-quick-launch" x-data x-on:click="$dispatch('pos-command-open', { mode: 'actions' })" aria-haspopup="dialog" title="Start something (press N)">
            <x-filament::icon icon="heroicon-m-plus" class="size-4" />
            <span class="pos-quick-launch-label">New</span>
        </button>
    @endif
@endauth
