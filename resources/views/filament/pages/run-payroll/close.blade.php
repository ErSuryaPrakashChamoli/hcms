{{-- Step 6 · Close payroll: waiting for approval, ready to close, or closed (and reopenable until paid or filed). --}}
@if (! $run)
    <x-pos.step-wait title="Run payroll first" text="A month is closed after it is calculated and approved." go="calculate" go-label="Run payroll" />
@elseif ($run->isLocked())
    <x-pos.step-banner tone="clear" icon="heroicon-o-lock-closed" :title="'Payroll closed'.($run->finalized_at ? ' on '.$run->finalized_at->format('j M Y, H:i') : '')"
        sub="Until it is marked paid or a return is filed from it, it can be reopened to change it." />
@elseif ($run->status === 'approved')
    <x-pos.step-banner tone="idle" icon="heroicon-o-lock-closed" title="Approved and ready to close"
        sub="Closing locks the month's attendance and inputs and prepares the payslips." />
@else
    <x-pos.step-wait title="Approve the month first" text="Only an approved month can be closed." go="approve" go-label="Review and approve" />
@endif

@if ($run)
    <div class="pos-step-actions">
        @if ($this->finalizeAction->isVisible()) {{ $this->finalizeAction }} @endif
        @if ($this->reopenMonthAction->isVisible()) {{ $this->reopenMonthAction }} @endif
    </div>
@endif
