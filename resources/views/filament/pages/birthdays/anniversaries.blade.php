{{-- HROne setup parity HP-9a: work or wedding anniversaries, laid out like the birthdays (today with wishes, the next two weeks, any month). --}}
@php($label = \App\Domain\People\Services\Anniversaries::PLURAL[$kind])
@if ($this->myAnniversaryWishes->isNotEmpty())
    <x-pos.section title="Wishes for you" :count="$this->myAnniversaryWishes->count()">
        <div class="pos-panel pos-stream">
            @foreach ($this->myAnniversaryWishes as $wish)
                <div class="pos-stream-row" wire:key="ann-wish-{{ $wish->id }}">
                    <x-pos.avatar :name="$wish->senderEmployee?->person?->display_name ?? $wish->sender?->name ?? 'Colleague'" size="sm" />
                    <div class="pos-stream-body">
                        <p class="pos-stream-title">{{ $wish->senderEmployee?->person?->display_name ?? $wish->sender?->name ?? 'A colleague' }}</p>
                        <p class="pos-body whitespace-pre-line">{{ $wish->message }}</p>
                    </div>
                    <span class="pos-stream-meta">{{ $wish->created_at->format('H:i') }}</span>
                </div>
            @endforeach
        </div>
    </x-pos.section>
@endif

<x-pos.section title="Today" :count="$this->anniversariesToday->count() ?: null">
    @if ($this->anniversariesToday->isEmpty())
        <x-pos.state :title="'No '.strtolower($label).' today'" why="When it is someone’s anniversary you can send them your wishes from here." />
    @else
        <div class="pos-panel pos-stream">
            @foreach ($this->anniversariesToday as $e)
                @include('filament.pages.birthdays.anniversary-row', ['e' => $e, 'key' => 'ann-today', 'when' => $this->anniversaryLine($e, 'today'), 'wishable' => true])
            @endforeach
        </div>
    @endif
</x-pos.section>

<x-pos.section title="Coming up" sub="The next two weeks.">
    @if ($this->anniversariesUpcoming->isEmpty())
        <x-pos.state :title="'No '.strtolower($label).' in the next two weeks'" />
    @else
        <div class="pos-panel pos-stream">
            @foreach ($this->anniversariesUpcoming as $e)
                @include('filament.pages.birthdays.anniversary-row', ['e' => $e, 'key' => 'ann-next', 'when' => $this->anniversaryLine($e, 'next'), 'wishable' => false])
            @endforeach
        </div>
    @endif
</x-pos.section>

<x-pos.section :title="$label.' in '.$this->monthName()" :count="$this->anniversariesMonth->count() ?: null" id="anniversaries-month">
    <x-slot:actions>
        <div class="flex gap-1">
            <button type="button" class="pos-btn pos-btn-ghost pos-btn-sm" wire:click="shiftMonth(-1)" aria-label="Previous month">←</button>
            <button type="button" class="pos-btn pos-btn-ghost pos-btn-sm" wire:click="shiftMonth(1)" aria-label="Next month">→</button>
        </div>
    </x-slot:actions>
    @if ($this->anniversariesMonth->isEmpty())
        <x-pos.state :title="'No '.strtolower($label).' this month'" />
    @else
        <div class="pos-panel pos-stream">
            @foreach ($this->anniversariesMonth as $e)
                @include('filament.pages.birthdays.anniversary-row', ['e' => $e, 'key' => 'ann-month', 'when' => $this->anniversaryLine($e, 'month'), 'wishable' => false])
            @endforeach
        </div>
    @endif
</x-pos.section>
