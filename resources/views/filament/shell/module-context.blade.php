{{--
    UX.15 closure: what matters on a module list. The sentence above (the page subheading) says what the viewer can
    see; here the statuses become a lens (narrowing only) and requests waiting for this viewer point to the
    Approval Center, where decisions come with their context. Counts come from the table's own query.
--}}
@php($lenses = $ctx['lenses'])
@if ($ctx['total'] > 0 && (count($lenses) > 1 || $ctx['approvals']))
    <div class="pos-module-context" role="region" aria-label="What matters in {{ $plural }}">
        @if (count($lenses) > 1)
            <div class="pos-lens-row" role="group" aria-label="Show {{ $plural }}">
                <button type="button" class="pos-lens-chip" wire:click="setPosLens(null)" aria-pressed="{{ $lens === null ? 'true' : 'false' }}">All <span class="pos-count">{{ number_format($ctx['total']) }}</span></button>
                @foreach (array_slice($lenses, 0, 6) as $l)
                    <button type="button" class="pos-lens-chip" data-tone="{{ $l['tone'] }}" wire:click="setPosLens(@js($l['key']))" aria-pressed="{{ $lens === $l['key'] ? 'true' : 'false' }}">
                        <span class="pos-lens-dot" aria-hidden="true"></span>{{ $l['label'] }} <span class="pos-count">{{ number_format($l['count']) }}</span>
                    </button>
                @endforeach
            </div>
        @endif
        @if ($ctx['approvals'])
            <a href="{{ $ctx['approvals']['url'] }}" wire:navigate class="pos-module-decide">
                <x-filament::icon icon="heroicon-m-check-badge" class="size-4" />
                <span><strong class="pos-num">{{ $ctx['approvals']['count'] }}</strong> {{ $ctx['approvals']['count'] === 1 ? 'waits' : 'wait' }} for your decision</span>
                <span class="pos-muted">Decide in the Approval Center →</span>
            </a>
        @endif
    </div>
@endif
