{{-- Step 7 · Publish payslips: waits for the close; then ready to publish, or published. --}}
@if (! $run || ! $run->isLocked())
    <x-pos.step-wait title="Close payroll first" text="Payslips are prepared when the month closes, and published from here after that." go="close" go-label="Close payroll" />
@else
    @if ($run->payslips_published_at)
        <x-pos.step-banner tone="clear" icon="heroicon-o-paper-airplane" :title="'Payslips published on '.$run->payslips_published_at->format('j M Y, H:i')" sub="Employees see their payslips." />
    @else
        <x-pos.step-banner tone="idle" icon="heroicon-o-document-text" title="The payslips are ready" sub="Employees see them once you publish them. The month can still be reopened to change it." />
    @endif
    <div class="pos-step-actions">
        @if ($this->publishPayslipsAction->isVisible()) {{ $this->publishPayslipsAction }} @endif
        @if ($this->reopenMonthAction->isVisible()) {{ $this->reopenMonthAction }} @endif
    </div>
@endif
