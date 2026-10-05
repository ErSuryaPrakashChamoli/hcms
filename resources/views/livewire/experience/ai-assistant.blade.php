@php
    $available = $this->assistants;
@endphp
{{-- UX.18: the open panel marks <html> so the Back button can close it (pos-ai-close, overlayBack in peopleos.js) --}}
<div @if (! $embedded) x-data="{ open: false, lastFocus: null }" x-on:pos-ai-open.window="lastFocus = document.activeElement; open = true; $nextTick(() => $refs.ask?.focus())"
    x-on:pos-ai-close.window="if (open) { open = false; $nextTick(() => lastFocus?.focus?.()) }" x-effect="document.documentElement.classList.toggle('pos-ai-open', open)" @endif>
    @if (! $embedded)
        <div x-show="open" x-cloak class="pos-overlay" x-on:click="open = false; $nextTick(() => lastFocus?.focus?.())" aria-hidden="true"></div>
    @endif
    <section @if (! $embedded) x-show="open" x-cloak x-trap.noscroll.inert="open" role="dialog" aria-modal="true" x-on:keydown.escape.prevent.stop="open = false; $nextTick(() => lastFocus?.focus?.())" x-transition:enter="pos-drawer-in" x-transition:leave="pos-drawer-out" @endif
        aria-labelledby="pos-ai-title-{{ $embedded ? 'card' : 'panel' }}" class="{{ $embedded ? 'pos-card pos-ai-card' : 'pos-drawer pos-ai-panel' }}">
        <header class="pos-ai-head">
            <span class="pos-ai-orb" aria-hidden="true"></span>
            <h2 id="pos-ai-title-{{ $embedded ? 'card' : 'panel' }}" class="pos-h3 flex-1">PeopleOS Assistant</h2>
            @if ($thread !== [])<button type="button" class="pos-link pos-caption" wire:click="clear">New</button>@endif
            @if (! $embedded)
                <button type="button" class="pos-icon-btn" x-on:click="open = false; $nextTick(() => lastFocus?.focus?.())" aria-label="Close assistant"><x-filament::icon icon="heroicon-m-x-mark" class="size-5" /></button>
            @endif
        </header>

        <div class="{{ $embedded ? '' : 'pos-drawer-body' }}">
            @if ($available === [])
                <x-pos.empty icon="heroicon-o-sparkles" title="The assistant is not available to you" why="It is switched off for your organisation or your role does not include it." />
            @else
                @if ($this->interactions->isEmpty())
                    <p class="pos-section-title">Ask about what you can see</p>
                    <p class="pos-body-sm">Answers come with their key facts and sources, from records you already have access to. Suggestions only open screens; you decide.</p>
                @endif

                @if (count($available) > 1)
                    <div class="pos-chips mt-3" role="tablist" aria-label="Assistant">
                        @foreach ($available as $key => $label)
                            <button type="button" role="tab" class="pos-chip" aria-selected="{{ $assistant === $key ? 'true' : 'false' }}" wire:click="switchTo('{{ $key }}')">{{ $label }}</button>
                        @endforeach
                    </div>
                @endif

                @if ($this->interactions->isNotEmpty())
                    <div class="pos-ai-thread {{ $embedded ? 'pos-ai-thread-card' : '' }}" aria-live="polite">
                        @foreach ($embedded ? $this->interactions->take(-1) : $this->interactions as $interaction)
                            <x-pos.ai-response :interaction="$interaction" wire:key="ai-{{ $interaction->id }}" />
                        @endforeach
                    </div>
                @endif

                @if ($this->interactions->isEmpty() || ! $embedded)
                    <ul class="pos-ai-prompts mt-3" aria-label="Suggested questions">
                        @foreach ($this->prompts as $prompt)
                            <li><button type="button" class="pos-ai-prompt" wire:click="usePrompt(@js($prompt))"><x-filament::icon icon="heroicon-m-sparkles" class="size-3.5" /><span>{{ $prompt }}</span></button></li>
                        @endforeach
                    </ul>
                @endif

                <form class="pos-ai-ask" wire:submit="ask">
                    <label class="sr-only" for="pos-ai-q-{{ $embedded ? 'card' : 'panel' }}">Ask the assistant</label>
                    <input id="pos-ai-q-{{ $embedded ? 'card' : 'panel' }}" x-ref="ask" type="text" wire:model="question" maxlength="1000" placeholder="Ask anything…" autocomplete="off" />
                    <button type="submit" class="pos-ai-send" aria-label="Send" wire:loading.attr="disabled" wire:target="ask, usePrompt">
                        <span wire:loading.remove wire:target="ask, usePrompt"><x-filament::icon icon="heroicon-m-paper-airplane" class="size-4" /></span>
                        <span wire:loading wire:target="ask, usePrompt" class="pos-spinner"></span>
                    </button>
                </form>
                <div wire:loading.delay wire:target="ask, usePrompt" class="pos-caption mt-2" role="status">Looking at what you can see…</div>
                @if ($error)<p class="pos-caption mt-2 text-pos-danger" role="alert">{{ $error }}</p>@endif
                <p class="pos-caption mt-2">Assistive only. No employment, pay or compensation decisions are made here.</p>
            @endif
        </div>
    </section>
</div>
