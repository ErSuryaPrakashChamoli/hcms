{{-- Birthdays today: the people the viewer can see celebrating, with one-click wishes (the Birthdays page's rules).
     HROne setup parity HP-9a: work and wedding anniversaries the organisation celebrates join the card ("Celebrations today"). --}}
@if ($h['birthdays'] ?? null)
    @php($b = $h['birthdays'])
    @php($ann = $b['anniversaries'] ?? [])
    <x-pos.section :title="$ann === [] ? 'Birthdays today' : 'Celebrations today'" :count="$b['count'] + count($ann)" :link="$b['url']" :link-label="$ann === [] ? 'Birthdays' : 'Celebrations'">
        <div class="pos-panel pos-stream">
            @foreach ($b['people'] as $p)
                <div class="pos-stream-row" wire:key="bday-{{ $p->id }}">
                    <span class="pos-stream-icon" aria-hidden="true"><x-filament::icon icon="heroicon-o-cake" class="size-4" /></span>
                    <span class="pos-stream-body"><span class="pos-stream-title">{{ $p->person->display_name }}</span><span class="pos-stream-meta">{{ $p->currentPosition?->designation?->name ?? $p->currentPosition?->department?->name }}</span></span>
                    @if ((int) $p->user_id === (int) auth()->id())
                        <x-pos.status tone="success" label="That’s you!" />
                    @elseif (in_array($p->id, $b['wished'], true))
                        <x-pos.status tone="neutral" label="Wished" />
                    @else
                        <button type="button" class="pos-btn pos-btn-secondary pos-btn-sm" wire:click="mountAction('wish', @js(['employee' => $p->id]))" data-pos-action="wish">Wish</button>
                    @endif
                </div>
            @endforeach
            @foreach ($ann as $a)
                <div class="pos-stream-row" wire:key="ann-{{ $a['kind'] }}-{{ $a['employee']->id }}">
                    <span class="pos-stream-icon" aria-hidden="true"><x-filament::icon :icon="$a['kind'] === 'work_anniversary' ? 'heroicon-o-trophy' : 'heroicon-o-heart'" class="size-4" /></span>
                    <span class="pos-stream-body"><span class="pos-stream-title">{{ $a['employee']->person->display_name }}</span><span class="pos-stream-meta">{{ $a['label'] }}</span></span>
                    @if ((int) $a['employee']->user_id === (int) auth()->id())
                        <x-pos.status tone="success" label="That’s you!" />
                    @elseif ($a['wished'])
                        <x-pos.status tone="neutral" label="Wished" />
                    @else
                        <button type="button" class="pos-btn pos-btn-secondary pos-btn-sm" wire:click="mountAction('wishAnniversary', @js(['employee' => $a['employee']->id, 'kind' => $a['kind']]))" data-pos-action="wish">Wish</button>
                    @endif
                </div>
            @endforeach
        </div>
    </x-pos.section>
@endif
