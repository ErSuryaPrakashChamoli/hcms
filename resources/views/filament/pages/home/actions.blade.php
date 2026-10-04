{{-- Start something: the actions this person most often starts --}}
@if (count($h['actions']) > 0)
    <x-pos.section title="Start something">
        <div class="pos-panel pos-stream">
            @foreach ($h['actions'] as $a)
                @php($mount = ['request_leave' => 'requestLeave', 'regularise' => 'regularise', 'ask_hr' => 'askHr'][$a['key']] ?? null)
                @if ($mount)
                    <button type="button" wire:click="mountAction('{{ $mount }}')" class="pos-stream-row" data-pos-action="{{ $a['key'] }}">
                        <span class="pos-stream-icon" aria-hidden="true"><x-filament::icon :icon="$a['icon']" class="size-4" /></span>
                        <span class="pos-stream-body"><span class="pos-stream-title">{{ $a['label'] }}</span><span class="pos-stream-meta">{{ $a['hint'] }}</span></span>
                        <span aria-hidden="true" class="pos-muted">→</span>
                    </button>
                @elseif (str_starts_with($a['url'], '#pick:'))
                    <button type="button" class="pos-stream-row" data-pos-action="{{ $a['key'] }}" x-data x-on:click="$dispatch('pos-command-open', { mode: 'people', pick: @js(substr($a['url'], 6)) })">
                        <span class="pos-stream-icon" aria-hidden="true"><x-filament::icon :icon="$a['icon']" class="size-4" /></span>
                        <span class="pos-stream-body"><span class="pos-stream-title">{{ $a['label'] }}</span><span class="pos-stream-meta">{{ $a['hint'] }}</span></span>
                        <span aria-hidden="true" class="pos-muted">→</span>
                    </button>
                @else
                    <a href="{{ $a['url'] }}" wire:navigate class="pos-stream-row" data-pos-action="{{ $a['key'] }}">
                        <span class="pos-stream-icon" aria-hidden="true"><x-filament::icon :icon="$a['icon']" class="size-4" /></span>
                        <span class="pos-stream-body"><span class="pos-stream-title">{{ $a['label'] }}</span><span class="pos-stream-meta">{{ $a['hint'] }}</span></span>
                        <span aria-hidden="true" class="pos-muted">→</span>
                    </a>
                @endif
            @endforeach
            <div class="pos-stream-more"><button type="button" class="pos-link" x-data x-on:click="$dispatch('pos-command-open', { mode: 'actions' })">All actions</button></div>
        </div>
    </x-pos.section>
@endif
