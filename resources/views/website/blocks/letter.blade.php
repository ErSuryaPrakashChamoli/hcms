{{--
    Letter generator: the operator's template with {placeholders}; each placeholder becomes a field (one ending in _date is
    a date). resources/js/site.js fills the preview as the visitor types. Nothing typed here is sent anywhere.
--}}
@php
    $template = (string) ($d['template'] ?? '');
    preg_match_all('/\{([a-z][a-z0-9_]{0,40})\}/', $template, $m);
    $fields = array_values(array_unique($m[1]));
    $label = fn (string $f) => \Illuminate\Support\Str::of($f)->replace('_', ' ')->replace(' ctc', ' CTC')->ucfirst()->toString();
    $uid = 'ltr-'.$index;
@endphp
<section class="ps-section ps-tool" data-ps-letter>
    <div class="ps-wrap">
        <div class="ps-section-head">
            @if (filled($d['eyebrow'] ?? null))<p class="ps-eyebrow">{{ $d['eyebrow'] }}</p>@endif
            <h1 class="ps-h2">{{ $d['heading'] ?? 'Letter generator' }}</h1>
            @if (filled($d['text'] ?? null))<p class="ps-lead">{{ $d['text'] }}</p>@endif
        </div>
        <div class="ps-tool-grid">
            <form class="ps-form ps-tool-form" onsubmit="return false" aria-label="Letter details">
                <p class="ps-tool-hint">{{ svg('heroicon-m-lock-closed', 'ps-icon') }} Stays in your browser — nothing is sent or saved.</p>
                @foreach ($fields as $field)
                    @php($isDate = str_ends_with($field, '_date'))
                    <div class="ps-field">
                        <label for="{{ $uid }}-{{ $field }}">{{ $label($field) }}</label>
                        <input id="{{ $uid }}-{{ $field }}" type="{{ $isDate ? 'date' : 'text' }}" data-field="{{ $field }}" maxlength="120" autocomplete="off">
                    </div>
                @endforeach
            </form>
            <div class="ps-paper-wrap">
                <div class="ps-tool-actions">
                    <button type="button" class="ps-btn ps-btn-primary ps-btn-sm" data-ps-copy>{{ svg('heroicon-m-clipboard-document', 'ps-icon') }} <span>Copy text</span></button>
                    <button type="button" class="ps-btn ps-btn-ghost ps-btn-sm" data-ps-print>{{ svg('heroicon-m-printer', 'ps-icon') }} Print or save PDF</button>
                    <button type="button" class="ps-btn ps-btn-ghost ps-btn-sm" data-ps-reset>Clear</button>
                </div>
                <article class="ps-paper" data-ps-paper aria-live="polite" aria-label="Letter preview">
                    <template data-ps-template>{{ $template }}</template>
                    <div data-ps-output class="ps-paper-text">{{ $template }}</div>
                </article>
            </div>
        </div>
    </div>
</section>
