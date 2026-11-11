@props(['title', 'eyebrow' => null, 'icon' => null, 'brief' => null])
{{--
    PeopleMasthead: a workspace's opening band in the Home language (ink into iris, a dot field, rings). It carries the
    page's heading once, so the page hides Filament's default header (getHeader() → filament.shell.empty).
    Slots: the default slot sits under the brief (a next step, actions); `glance` is the aside beside it (x-pos.glance);
    `band` is a footer strip across the whole band (filters, a view switcher). The artwork is decoration only.
--}}
<header {{ $attributes->class(['pos-mast']) }} @if (! isset($glance) || $glance->isEmpty()) data-solo @endif>
    <div class="pos-mast-art" aria-hidden="true"></div>

    <div class="pos-mast-main">
        <div class="pos-ws-head-text">
            @if ($eyebrow)
                <p class="pos-ws-eyebrow pos-mast-eyebrow">
                    @if ($icon)<span class="pos-mast-mark"><x-filament::icon :icon="$icon" class="size-4" /></span>@endif
                    <span>{{ $eyebrow }}</span>
                </p>
            @endif
            <h1 class="pos-ws-title pos-ws-title-display">{{ $title }}</h1>
            @if ($brief)<p class="pos-ws-brief">{{ $brief }}</p>@endif
        </div>
        {{ $slot }}
    </div>

    @if (isset($glance) && $glance->isNotEmpty())
        {{ $glance }}
    @endif

    @if (isset($band) && $band->isNotEmpty())
        <div {{ $band->attributes->class(['pos-mast-band']) }}>{{ $band }}</div>
    @endif
</header>
