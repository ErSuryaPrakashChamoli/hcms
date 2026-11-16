@php($items = array_values(array_filter((array) ($d['items'] ?? []), fn ($i) => is_array($i) && filled($i['title'] ?? null))))
<section class="ps-section is-tint">
    <div class="ps-wrap">
        @include('website.blocks._head', ['d' => $d, 'center' => true])
        <ol class="ps-steps" style="--cols: {{ min(4, max(1, count($items))) }}">
            @foreach ($items as $n => $item)
                <li class="ps-step ps-reveal" style="transition-delay: {{ $n * 80 }}ms">
                    <h3>{{ $item['title'] }}</h3>
                    @if (filled($item['text'] ?? null))<p>{{ $item['text'] }}</p>@endif
                </li>
            @endforeach
        </ol>
    </div>
</section>
