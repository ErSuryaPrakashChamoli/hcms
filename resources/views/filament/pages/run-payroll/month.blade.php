{{-- Step 1 · Pay month: the month as a calendar card with where payroll stands, three facts, and Start when it has no run. --}}
@php
    [$year, $monthNo] = $this->period();
    $date = $year > 0 && $monthNo > 0 ? \Carbon\CarbonImmutable::create($year, $monthNo, 1) : null;
    $dates = \App\Filament\Support\PayrollActions::periodDates($this->companyId(), $year, $monthNo);
    $statusLabel = $run ? (['finalized' => 'Closed'][$run->status] ?? ucfirst($run->status)) : 'Not started';
    $statusTone = match ($run?->status) { null => 'na', 'draft' => 'idle', 'calculated', 'validated', 'approved' => 'pending', default => 'clear' };
    $upNext = collect($steps)->first(fn (array $s) => $s['key'] !== 'month' && $s['state'] !== 'done');
@endphp

<div class="pos-step-hero">
    @if ($date)
        <div class="pos-step-cal" aria-hidden="true"><span>{{ $date->format('M') }}</span><strong>{{ $date->format('Y') }}</strong></div>
    @endif
    <div class="pos-step-hero-text">
        <p class="pos-step-hero-title">{{ $date?->format('F Y') }}</p>
        <p class="pos-step-hero-sub">{{ $this->companies()[$this->companyId()] ?? '—' }}@if ($dates) · {{ \Illuminate\Support\Str::after($dates, 'Covers ') }}@endif</p>
    </div>
    <span class="pos-step-pill" data-tone="{{ $statusTone }}">{{ $statusLabel }}@if ($run?->reopen_count > 0) · reopened {{ $run->reopen_count }}×@endif</span>
</div>

<div class="pos-step-facts">
    <div class="pos-step-fact">
        <span class="pos-step-fact-icon" aria-hidden="true"><x-filament::icon icon="heroicon-o-banknotes" class="pos-step-fact-svg" /></span>
        <span class="pos-step-fact-text"><span>Payment date</span><strong>{{ $run?->period?->payment_date?->format('j M Y') ?? 'Month end' }}</strong></span>
    </div>
    <div class="pos-step-fact">
        <span class="pos-step-fact-icon" aria-hidden="true"><x-filament::icon icon="heroicon-o-document-text" class="pos-step-fact-svg" /></span>
        <span class="pos-step-fact-text"><span>Payslips</span><strong>{{ $run?->payslips_published_at ? 'Published '.$run->payslips_published_at->format('j M') : ($run?->isLocked() ? 'Ready to publish' : 'Not issued yet') }}</strong></span>
    </div>
    @if ($upNext)
        <button type="button" class="pos-step-fact is-link" wire:click="goTo('{{ $upNext['key'] }}')">
            <span class="pos-step-fact-icon" aria-hidden="true"><x-filament::icon icon="heroicon-o-arrow-right-circle" class="pos-step-fact-svg" /></span>
            <span class="pos-step-fact-text"><span>Up next</span><strong>{{ $upNext['label'] }}</strong></span>
        </button>
    @endif
</div>

@if (! $run)
    <x-pos.step-banner tone="idle" :title="'Start '.$date?->format('F Y')" sub="Starting the month creates its payroll run. Nothing is calculated until Run payroll, after attendance and the checks.">
        <x-slot:aside>@if ($this->startRunAction->isVisible()) {{ $this->startRunAction }} @endif</x-slot:aside>
    </x-pos.step-banner>
@endif
