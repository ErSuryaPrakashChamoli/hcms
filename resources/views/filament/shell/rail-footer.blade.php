@auth
    @if (app(\App\Support\Tenancy\TenantContext::class)->has() && \App\Filament\Pages\AssistantPage::canAccess())
        {{-- The assistant is assistive: it answers from data the person may see and suggests; people decide. --}}
        <button type="button" class="pos-rail-ai" x-show="$store.sidebar.isOpen" x-cloak x-data x-on:click="$dispatch('pos-ai-open')">
            <span class="pos-rail-ai-orb" aria-hidden="true"></span>
            <span class="min-w-0 text-start">
                <span class="pos-rail-ai-title block">Ask PeopleOS</span>
                <span class="pos-rail-ai-text block">Explains with sources. Never decides.</span>
            </span>
        </button>
    @endif
@endauth
