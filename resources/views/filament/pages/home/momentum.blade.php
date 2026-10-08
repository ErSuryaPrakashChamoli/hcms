{{-- Your momentum (employees): where you are and how things are moving --}}
@if ($h['experience'] === 'employee')
    <x-pos.section title="Your momentum" :link="\App\Filament\Pages\MyCareer::canAccess() ? \App\Filament\Pages\MyCareer::getUrl() : null" link-label="Career">
        <div class="pos-panel pos-panel-pad grid gap-4">
            @if (($h['journey_nodes'] ?? []) !== [])
                <ol class="pos-journey-mini" aria-label="Your journey">
                    @foreach ($h['journey_nodes'] as $node)
                        <li data-state="{{ $node['state'] }}"><span class="pos-journey-mini-dot" aria-hidden="true"></span><span class="pos-meta font-medium text-pos-text">{{ $node['label'] }}</span><span class="pos-caption">{{ $node['sub'] }}</span></li>
                    @endforeach
                </ol>
            @endif
            <div class="pos-figures">
                @if ($f = $kpi('balance'))
                    <x-pos.figure :value="$f['value'].' days'" :label="$f['label'].' left'.(isset($f['ring']) ? ' of '.$num($f['ring']['total']) : '')" />
                @endif
                @foreach (['attention', 'waiting', 'learning', 'present'] as $k)
                    @if ($f = $kpi($k))<x-pos.figure :value="$f['value']" :label="$f['label']" :href="$f['url'] ?? null" />@endif
                @endforeach
            </div>
        </div>
    </x-pos.section>
@endif
