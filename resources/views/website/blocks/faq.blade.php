@php($items = array_values(array_filter((array) ($d['items'] ?? []), fn ($i) => is_array($i) && filled($i['question'] ?? null))))
@if ($items !== [])
    <section class="ps-section" id="faq">
        <div class="ps-wrap ps-faq">
            <div class="ps-section-head ps-reveal" style="margin:0">
                <p class="ps-eyebrow">FAQ</p>
                <h2 class="ps-h2">{{ $d['heading'] ?? 'Questions, answered' }}</h2>
                @if (filled($d['text'] ?? null))<p class="ps-lead">{{ $d['text'] }}</p>@endif
            </div>
            <div class="ps-faq-list ps-reveal">
                @foreach ($items as $n => $item)
                    <details @if ($n === 0) open @endif>
                        <summary>{{ $item['question'] }} <i aria-hidden="true"></i></summary>
                        <p>{{ $item['answer'] ?? '' }}</p>
                    </details>
                @endforeach
            </div>
        </div>
    </section>
@endif
