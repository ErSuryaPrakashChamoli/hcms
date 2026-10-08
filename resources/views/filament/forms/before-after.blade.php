<div aria-live="polite">
    @if (count($changes) > 0)
        <x-pos.before-after :changes="$changes" title="Before → After" />
    @else
        <p class="pos-caption">{{ $empty }}</p>
    @endif
    @if ($note)<p class="pos-caption mt-2">{{ $note }}</p>@endif
</div>
