@props(['steps' => [], 'current' => 0])
{{-- Guided flow: context → information → decision → review → confirm. The current step is announced. --}}
<ol {{ $attributes->class(['pos-stepper']) }} aria-label="Progress">
    @foreach ($steps as $i => $step)
        <li data-state="{{ $i < $current ? 'done' : ($i === $current ? 'current' : 'upcoming') }}" @if ($i === $current) aria-current="step" @endif>
            <span class="pos-stepper-dot" aria-hidden="true">@if ($i < $current)<x-filament::icon icon="heroicon-m-check" class="size-3" />@else{{ $i + 1 }}@endif</span>
            <span class="pos-stepper-label">{{ $step }}</span>
        </li>
    @endforeach
</ol>
