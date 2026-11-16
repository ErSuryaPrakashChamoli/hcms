@if (filled($d['quote'] ?? null))
    <section class="ps-section">
        <figure class="ps-wrap ps-quote ps-reveal">
            <blockquote>{{ $d['quote'] }}</blockquote>
            @if (filled($d['name'] ?? null))<figcaption><b>{{ $d['name'] }}</b>@if (filled($d['role'] ?? null)) · {{ $d['role'] }}@endif</figcaption>@endif
        </figure>
    </section>
@endif
