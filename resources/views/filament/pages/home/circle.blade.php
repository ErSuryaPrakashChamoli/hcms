{{-- Your people (employees): the people you work with most --}}
@if (($h['circle'] ?? []) !== [])
    <x-pos.section title="Your people">
        <div class="pos-panel pos-stream">
            @foreach ($h['circle'] as $p)
                <div class="pos-stream-row">
                    <x-pos.person :id="$p['id']" :name="$p['name']" size="sm" />
                    <span class="pos-stream-meta">{{ $p['role'] }}</span>
                    <span></span>
                </div>
            @endforeach
        </div>
    </x-pos.section>
@endif
