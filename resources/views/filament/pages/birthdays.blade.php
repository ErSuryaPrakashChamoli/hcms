<x-filament-panels::page>
    <div class="pos-ws">
        {{-- HROne setup parity HP-9a: one tab per occasion the organisation celebrates (birthdays only: no tabs, as before). --}}
        @if (count($this->kinds()) > 1)
            <nav class="pos-lens pos-lens-scroll" aria-label="Celebrations" x-data x-init="posRevealChip($el, $el.querySelector('[aria-pressed=true]'))">
                @foreach ($this->kinds() as $k => $label)
                    <button type="button" class="pos-lens-chip" aria-pressed="{{ $kind === $k ? 'true' : 'false' }}" wire:click="selectKind(@js($k))">{{ $label }}</button>
                @endforeach
            </nav>
        @endif
        @if ($kind !== 'birthday')
            @include('filament.pages.birthdays.anniversaries')
        @else
        @if ($this->myWishes->isNotEmpty())
            <x-pos.section title="Wishes for you" :count="$this->myWishes->count()">
                <div class="pos-panel pos-stream">
                    @foreach ($this->myWishes as $wish)
                        <div class="pos-stream-row" wire:key="wish-{{ $wish->id }}">
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

        <x-pos.section title="Today" :count="$this->today->count() ?: null">
            @if ($this->today->isEmpty())
                <x-pos.state title="No birthdays today" why="When it is someone’s birthday you can send them your wishes from here." />
            @else
                <div class="pos-panel pos-stream">
                    @foreach ($this->today as $e)
                        @include('filament.pages.birthdays.row', ['e' => $e, 'key' => 'today', 'when' => 'Today', 'wishable' => true])
                    @endforeach
                </div>
            @endif
        </x-pos.section>

        <x-pos.section title="Coming up" sub="The next two weeks.">
            @if ($this->upcoming->isEmpty())
                <x-pos.state title="No birthdays in the next two weeks" />
            @else
                <div class="pos-panel pos-stream">
                    @foreach ($this->upcoming as $e)
                        @include('filament.pages.birthdays.row', ['e' => $e, 'key' => 'next', 'when' => $this->nextOf($e), 'wishable' => false])
                    @endforeach
                </div>
            @endif
        </x-pos.section>

        <x-pos.section :title="'Birthdays in '.$this->monthName()" :count="$this->monthList->count() ?: null" id="birthdays-month">
            <x-slot:actions>
                <div class="flex gap-1">
                    <button type="button" class="pos-btn pos-btn-ghost pos-btn-sm" wire:click="shiftMonth(-1)" aria-label="Previous month">←</button>
                    <button type="button" class="pos-btn pos-btn-ghost pos-btn-sm" wire:click="shiftMonth(1)" aria-label="Next month">→</button>
                </div>
            </x-slot:actions>
            @if ($this->monthList->isEmpty())
                <x-pos.state title="No birthdays this month" />
            @else
                <div class="pos-panel pos-stream">
                    @foreach ($this->monthList as $e)
                        @include('filament.pages.birthdays.row', ['e' => $e, 'key' => 'month', 'when' => $this->dateOf($e), 'wishable' => false])
                    @endforeach
                </div>
            @endif
        </x-pos.section>
        @endif
    </div>
</x-filament-panels::page>
