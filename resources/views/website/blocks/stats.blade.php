@php($items = array_values(array_filter((array) ($d['items'] ?? []), fn ($i) => is_array($i) && filled($i['value'] ?? null))))
@if ($items !== [])
    <section class="ps-section">
        <div class="ps-wrap">
            @if (filled($d['heading'] ?? null))<div class="ps-section-head is-center ps-reveal"><h2 class="ps-h2">{{ $d['heading'] }}</h2></div>@endif
            <div class="ps-stats ps-reveal" style="--cols: {{ min(4, count($items)) }}">
                @foreach ($items as $item)
                    <div class="ps-stat"><b>{{ $item['value'] }}</b><span>{{ $item['label'] ?? '' }}</span></div>
                @endforeach
            </div>
        </div>
    </section>
@endif
