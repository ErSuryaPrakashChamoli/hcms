{{-- Step 8 · Pay: three numbered tasks (bank file, journal voucher, mark as paid), each with its own button. --}}
@if (! $run || ! $run->isLocked())
    <x-pos.step-wait title="Close payroll first" text="The bank file and the journal voucher come from the closed month." go="close" go-label="Close payroll" />
@else
    @php $paid = $run->status === 'paid'; @endphp
    @if ($paid)
        <x-pos.step-banner tone="clear" icon="heroicon-o-banknotes" :title="'Marked paid'.($run->paid_at ? ' on '.$run->paid_at->format('j M Y') : '')" sub="The month is done. The files can still be downloaded below." />
    @endif
    <ol class="pos-step-tasks">
        <li class="pos-step-task">
            <span class="pos-step-task-n" aria-hidden="true">1</span>
            <span class="pos-step-task-text"><strong>Bank file</strong><span>The salary transfers, for the paying bank account.</span></span>
            @if ($this->bankFileAction->isVisible()) {{ $this->bankFileAction }} @endif
        </li>
        <li class="pos-step-task">
            <span class="pos-step-task-n" aria-hidden="true">2</span>
            <span class="pos-step-task-text"><strong>Journal voucher</strong><span>The month's entries, for the books.</span></span>
            @if ($this->journalVoucherAction->isVisible()) {{ $this->journalVoucherAction }} @endif
        </li>
        <li class="pos-step-task" @if ($paid) data-done @endif>
            <span class="pos-step-task-n" aria-hidden="true">@if ($paid)<x-filament::icon icon="heroicon-m-check" class="pos-step-task-check" />@else 3 @endif</span>
            <span class="pos-step-task-text"><strong>Mark as paid</strong><span>{{ $paid ? 'Done.' : 'Once the transfers have gone out.' }}</span></span>
            @if ($this->markPaidAction->isVisible()) {{ $this->markPaidAction }} @endif
        </li>
    </ol>
@endif
