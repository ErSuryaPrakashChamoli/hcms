{{-- Step 5 · Review and approve: where approval stands (one banner), the calculated month's review, then the actions. --}}
@if (! $run)
    <x-pos.step-wait title="Run payroll first" text="The month is reviewed and approved here once it is calculated." go="calculate" go-label="Run payroll" />
@else
    @php
        [$tone, $icon, $title, $sub] = match ($run->status) {
            'draft' => ['na', 'heroicon-o-calculator', 'Calculate the month first', 'Its result is reviewed here once it is calculated.'],
            'calculated' => ['idle', 'heroicon-o-shield-check', 'Validate the result', 'A run with blocking exceptions cannot go further.'],
            'validated' => ['pending', 'heroicon-o-clock', 'Waiting for approval', 'Someone other than the person who ran it approves it.'],
            default => ['clear', 'heroicon-o-check-badge', 'Approved'.($run->approved_at ? ' on '.$run->approved_at->format('j M Y') : ''), 'The month can be closed.'],
        };
    @endphp

    @if ($run->status === 'draft')
        <x-pos.step-wait icon="heroicon-o-calculator" :title="$title" :text="$sub" go="calculate" go-label="Run payroll" />
    @else
        <x-pos.step-banner :tone="$tone" :icon="$icon" :title="$title" :sub="$sub" />
    @endif

    @if ($r = $this->review())
        @include('filament.pages.run-payroll.review', ['r' => $r, 'runUrl' => $this->runUrl()])
    @endif

    <div class="pos-step-actions">
        @if ($this->validateAction->isVisible()) {{ $this->validateAction }} @endif
        @if ($this->approveAction->isVisible()) {{ $this->approveAction }} @endif
        @if ($this->reopenAction->isVisible()) {{ $this->reopenAction }} @endif
        @if ($run->status !== 'draft' && ($url = $this->runUrl()))
            <a href="{{ $url }}" wire:navigate class="pos-step-link">Open the run<x-filament::icon icon="heroicon-m-arrow-up-right" class="pos-step-link-icon" /></a>
        @endif
    </div>
@endif
