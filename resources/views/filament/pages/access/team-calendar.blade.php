<x-filament-panels::page>
    <div class="pos-ws">
        {{-- HROne parity CA-9: whose events (a manager's team, or HR's access scope) --}}
        @if (count($this->audiences()) > 1)
            <nav class="pos-lens pos-lens-scroll" aria-label="Whose events">
                @foreach ($this->audiences() as $key => $label)
                    <button type="button" class="pos-lens-chip" aria-pressed="{{ $audience === $key ? 'true' : 'false' }}" wire:click="setAudience(@js($key))">{{ $label }}</button>
                @endforeach
            </nav>
        @endif

        @if ($this->kinds() !== [])
            <nav class="pos-lens pos-lens-scroll" aria-label="Events">
                @foreach ($this->kinds() as $key => $label)
                    <button type="button" class="pos-lens-chip" aria-pressed="{{ in_array($key, $only, true) ? 'true' : 'false' }}" wire:click="toggleKind(@js($key))">{{ $label }}</button>
                @endforeach
            </nav>
        @endif

        <x-pos.section :title="$this->monthName()" :count="collect($this->days)->flatten(1)->count() ?: null">
            <x-slot:actions>
                <div class="flex gap-1">
                    <button type="button" class="pos-btn pos-btn-ghost pos-btn-sm" wire:click="shiftMonth(-1)" aria-label="Previous month">←</button>
                    <button type="button" class="pos-btn pos-btn-ghost pos-btn-sm" wire:click="shiftMonth(1)" aria-label="Next month">→</button>
                </div>
            </x-slot:actions>
            @if ($this->kinds() === [])
                <x-pos.state title="No events are shown here" why="Your organisation shows no calendar events to this audience (Setup → Access rights → Calendar access)." />
            @elseif ($this->days === [])
                <x-pos.state title="Nothing this month" why="Events appear here as they fall in the month: birthdays, anniversaries, joiners, leave and more, as far as you may see them." />
            @else
                <div class="pos-panel pos-stream">
                    @foreach ($this->days as $date => $events)
                        @foreach ($events as $i => $event)
                            <div class="pos-stream-row" wire:key="ev-{{ $date }}-{{ $i }}" data-kind="{{ $event['kind'] }}">
                                <span class="pos-stream-meta">{{ \Carbon\CarbonImmutable::parse($date)->format('D j M') }}</span>
                                <div class="pos-stream-body">
                                    <p class="pos-stream-title">{{ $event['name'] }}</p>
                                    <p class="pos-stream-meta">{{ $event['label'] }}@if ($event['detail']) · {{ $event['detail'] }}@endif</p>
                                </div>
                            </div>
                        @endforeach
                    @endforeach
                </div>
            @endif
        </x-pos.section>
    </div>
</x-filament-panels::page>
