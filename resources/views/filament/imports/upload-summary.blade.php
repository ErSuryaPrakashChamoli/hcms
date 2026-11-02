{{-- The head of every upload's own page: what it is, where it stands in the pipeline, and its counts (ImportActions::summary). --}}
<div class="pos-di" style="{{ $tone }}">
    <header class="pos-di-hero">
        <span class="pos-di-hero-icon" aria-hidden="true"><x-filament::icon :icon="$icon" class="size-6" /></span>
        <div class="pos-di-hero-body">
            <span class="pos-di-eyebrow">{{ $module }}</span>
            <h2 class="pos-di-title">{{ $title }}</h2>
            <p class="pos-di-sub">{{ $file }} · uploaded by {{ $uploader }} on {{ $uploadedAt }}</p>
        </div>
        <div class="pos-di-hero-side">
            @if ($intent)
                <span class="pos-di-chip" data-tone="tone"><x-filament::icon icon="heroicon-m-check-circle" class="size-4" />{{ $intent }}</span>
            @endif
            <span class="pos-di-chip">{{ $status }}</span>
        </div>
    </header>
    <div class="pos-di-card">
        <x-pos.stepper :steps="$steps" :current="$current" />
        @if ($next)
            <div class="pos-di-note"><x-filament::icon icon="heroicon-m-arrow-right-circle" /><span>{{ $next }}</span></div>
        @endif
        @if ($stats !== [])
            <div class="pos-di-stats">
                @foreach ($stats as [$label, $value, $statTone])
                    <div class="pos-di-stat" @if ($statTone && $value) data-tone="{{ $statTone }}" @endif><span class="pos-di-stat-value">{{ $value }}</span><span class="pos-di-stat-label">{{ $label }}</span></div>
                @endforeach
            </div>
        @endif
    </div>
</div>
