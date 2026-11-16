@php($names = array_values(array_filter(array_map(fn ($n) => is_array($n) ? trim((string) ($n['name'] ?? '')) : '', (array) ($d['names'] ?? [])))))
@if ($names !== [])
    <section class="ps-section" style="padding-block: 48px">
        <div class="ps-wrap ps-logos">
            @if (filled($d['heading'] ?? null))<p>{{ $d['heading'] }}</p>@endif
            <div class="ps-marquee" aria-label="{{ implode(', ', $names) }}">
                <div class="ps-marquee-track" aria-hidden="true">
                    @foreach ([1, 2] as $_)
                        @foreach ($names as $name)<span>{{ $name }}</span>@endforeach
                    @endforeach
                </div>
            </div>
        </div>
    </section>
@endif
