{{-- HROne setup parity HP-9b: the organisation's mission, vision, purpose and core values meant for this employee. --}}
@if ($h['mission'] ?? null)
    @php($m = $h['mission'])
    <x-pos.section title="Our mission">
        <div class="pos-panel pos-stream">
            @foreach (['mission' => 'Mission', 'vision' => 'Vision', 'purpose' => 'Purpose'] as $key => $label)
                @if (filled($m[$key]))
                    <div class="pos-stream-row">
                        <span class="pos-stream-icon" aria-hidden="true"><x-filament::icon :icon="['mission' => 'heroicon-o-flag', 'vision' => 'heroicon-o-eye', 'purpose' => 'heroicon-o-heart'][$key]" class="size-4" /></span>
                        <span class="pos-stream-body"><span class="pos-stream-title">{{ $label }}</span><span class="pos-stream-meta whitespace-pre-line">{{ $m[$key] }}</span></span>
                        <span></span>
                    </div>
                @endif
            @endforeach
            @foreach ($m['values'] as $value)
                <div class="pos-stream-row">
                    <span class="pos-stream-icon" aria-hidden="true"><x-filament::icon :icon="$value['icon']" class="size-4" /></span>
                    <span class="pos-stream-body"><span class="pos-stream-title">{{ $value['title'] }}</span>@if (filled($value['description']))<span class="pos-stream-meta">{{ $value['description'] }}</span>@endif</span>
                    <span></span>
                </div>
            @endforeach
        </div>
    </x-pos.section>
@endif
