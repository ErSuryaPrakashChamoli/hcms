@props(['interaction'])
@php
    /** @var \App\Domain\Ai\Models\AiInteraction $interaction */
    // Presentation only: the first paragraph is the answer; bullet lines become key facts.
    $lines = preg_split('/\R/', trim((string) $interaction->answer)) ?: [];
    $answer = [];
    $facts = [];
    foreach ($lines as $line) {
        $t = trim($line);
        if ($t === '') { continue; }
        if (preg_match('/^[-•*]\s+(.*)$/u', $t, $m)) { $facts[] = $m[1]; } else { $answer[] = $t; }
    }
    $sources = collect($interaction->sources ?? [])->filter(fn ($s) => filled($s['label'] ?? null))->values();
    // UX.19: only screens the person may still open (checked again when shown).
    $actions = collect($interaction->openActions());
@endphp
<article {{ $attributes->class(['pos-ai-response']) }} aria-label="Answer">
    <p class="pos-ai-q">{{ $interaction->question }}</p>
    <div class="pos-ai-block">
        <p class="pos-label">Answer</p>
        <p class="pos-body mt-1">{{ implode(' ', $answer) ?: '—' }}</p>
    </div>
    @if ($facts !== [])
        <div class="pos-ai-block">
            <p class="pos-label">Key facts</p>
            <ul class="pos-ai-facts">
                @foreach (array_slice($facts, 0, 8) as $fact)<li>{{ $fact }}</li>@endforeach
            </ul>
        </div>
    @endif
    @if ($sources->isNotEmpty())
        <div class="pos-ai-block">
            <p class="pos-label">Sources</p>
            <ul class="pos-ai-sources">
                @foreach ($sources as $s)<li><x-filament::icon icon="heroicon-m-document-magnifying-glass" class="size-3.5" />{{ $s['label'] }}@if (filled($s['detail'] ?? null)) <span class="pos-muted">· {{ $s['detail'] }}</span>@endif</li>@endforeach
            </ul>
        </div>
    @endif
    @if ($actions->isNotEmpty())
        <div class="pos-ai-block">
            <p class="pos-label">Suggested actions</p>
            <div class="mt-1 flex flex-wrap gap-2">
                @foreach ($actions as $a)<a href="{{ $a['url'] }}" wire:navigate class="pos-chip">{{ $a['label'] }} <span aria-hidden="true">→</span></a>@endforeach
            </div>
        </div>
    @endif
    <footer class="pos-ai-foot">
        <span class="pos-ai-badge">Assistive</span>
        <span class="pos-caption">{{ $interaction->ai_generated ? 'Worded by '.$interaction->provider.' from facts you can see' : 'Computed from data you can see' }}{{ $interaction->is_inference ? ' · includes an inference' : '' }}</span>
        <span class="ms-auto flex gap-1" role="group" aria-label="Was this helpful?">
            <button type="button" class="pos-icon-btn" wire:click="rate({{ $interaction->id }}, 'up')" aria-pressed="{{ $interaction->feedback === 'up' ? 'true' : 'false' }}" aria-label="Helpful"><x-filament::icon :icon="$interaction->feedback === 'up' ? 'heroicon-s-hand-thumb-up' : 'heroicon-o-hand-thumb-up'" class="size-4" /></button>
            <button type="button" class="pos-icon-btn" wire:click="rate({{ $interaction->id }}, 'down')" aria-pressed="{{ $interaction->feedback === 'down' ? 'true' : 'false' }}" aria-label="Not helpful"><x-filament::icon :icon="$interaction->feedback === 'down' ? 'heroicon-s-hand-thumb-down' : 'heroicon-o-hand-thumb-down'" class="size-4" /></button>
        </span>
    </footer>
</article>
