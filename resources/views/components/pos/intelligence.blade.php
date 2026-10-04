@props(['intel' => null])
{{--
    PeopleInsight / PeopleAI: contextual intelligence. Deterministic statements over data the viewer may
    already see, each with its source; suggestions only open screens. Labelled as generated, always.
--}}
@if ($intel && ($intel['items'] ?? []) !== [])
    <section {{ $attributes->class(['pos-intel']) }} aria-labelledby="intel-{{ md5($intel['context']) }}">
        <div class="pos-intel-head">
            <span class="pos-ai-orb pos-ai-orb-sm" aria-hidden="true"></span>
            <h2 id="intel-{{ md5($intel['context']) }}" class="pos-intel-title">PeopleOS Intelligence</h2>
            <span class="pos-intel-tag">Generated</span>
        </div>
        <ul class="pos-intel-list">
            @foreach ($intel['items'] as $item)
                <li class="pos-intel-item" data-tone="{{ $item['tone'] ?? 'info' }}">
                    <p class="pos-intel-text">{{ $item['text'] }}</p>
                    <p class="pos-intel-source">Source: {{ $item['source'] }}</p>
                    @if ($item['action'] ?? null)
                        @if ($item['action']['drawer'] ?? null)
                            <button type="button" class="pos-link justify-self-start text-start" x-data x-on:click="$dispatch('pos-drawer-open', @js($item['action']['drawer']))">{{ $item['action']['label'] }} <span aria-hidden="true">→</span></button>
                        @elseif ($item['action']['url'] ?? null)
                            <a href="{{ $item['action']['url'] }}" wire:navigate class="pos-link justify-self-start">{{ $item['action']['label'] }} <span aria-hidden="true">→</span></a>
                        @endif
                    @endif
                </li>
            @endforeach
        </ul>
        <p class="pos-intel-foot">Computed by PeopleOS from records you can see. It suggests; you decide. No employment, pay or compensation decision is made here.</p>
    </section>
@endif
