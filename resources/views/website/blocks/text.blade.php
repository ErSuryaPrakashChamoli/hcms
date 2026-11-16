{{-- Text in Markdown: HTML typed into it is shown as text, and unsafe links are dropped (CommonMark safe options). --}}
<section class="ps-section">
    <div class="ps-wrap">
        <div class="ps-prose ps-reveal">
            @if (filled($d['heading'] ?? null))<h2>{{ $d['heading'] }}</h2>@endif
            {!! \Illuminate\Support\Str::markdown((string) ($d['body'] ?? ''), ['html_input' => 'escape', 'allow_unsafe_links' => false, 'max_nesting_level' => 10]) !!}
        </div>
    </div>
</section>
