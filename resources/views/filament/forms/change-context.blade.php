@php
    /** @var \App\Domain\Employment\Models\Employee|null $record */
    $p = $record?->currentPosition;
@endphp
{{-- Step 1 of a guided change: what is true today, before anything changes. --}}
<div class="pos-change">
    <div class="pos-change-head"><p class="pos-change-label">Today</p><span class="pos-change-when">As recorded now</span></div>
    <dl class="pos-change-rows">
        @foreach (array_filter($facts ?? []) as $label => $value)
            <div class="pos-change-row" data-unchanged><dt class="pos-change-field">{{ $label }}</dt><dd class="pos-change-values"><span class="pos-change-after">{{ $value }}</span></dd></div>
        @endforeach
    </dl>
    <p class="pos-meta">{{ $hint ?? 'Only change what is different. You review the Before → After before anything is saved.' }}</p>
</div>
