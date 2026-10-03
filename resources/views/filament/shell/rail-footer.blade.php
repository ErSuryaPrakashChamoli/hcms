@auth
    @if (app(\App\Support\Tenancy\TenantContext::class)->has() && \App\Filament\Pages\AssistantPage::canAccess())
        {{-- The assistant is assistive: it answers from data the person may see and suggests; people decide. --}}
        <div class="pos-rail-ai" x-show="$store.sidebar.isOpen" x-cloak>
            <span class="pos-rail-ai-orb" aria-hidden="true"></span>
            <p class="pos-rail-ai-title">PeopleOS Assistant</p>
            <p class="pos-rail-ai-text">Ask, find and explain. It only uses what you can see.</p>
            <button type="button" class="pos-rail-ai-btn" x-data x-on:click="$dispatch('pos-ai-open')">Ask a question</button>
        </div>
    @endif
@endauth
