{{-- Today: attendance, the leave request and the latest payslip (one tap each). --}}
<section class="pos-card pos-today" aria-labelledby="pos-today-title-{{ $suffix }}">
    <p class="pos-label">Today</p>
    <h2 id="pos-today-title-{{ $suffix }}" class="pos-h3 mt-1">
        @if ($me['checked_in'])
            Checked in at {{ $me['first_in']?->format('H:i') }}
        @elseif ($me['last_out'])
            Checked out at {{ $me['last_out']->format('H:i') }}
        @else
            Not checked in yet
        @endif
    </h2>
    @if ($me['status'])<p class="pos-caption mt-1">Attendance: {{ $me['status'] }}</p>@endif
    <div class="mt-3 flex flex-wrap gap-2">
        @if ($me['checked_in'])
            <button type="button" wire:click="punch('out')" wire:loading.attr="disabled" class="pos-btn pos-btn-secondary pos-btn-sm">Check out</button>
        @else
            <button type="button" wire:click="punch('in')" wire:loading.attr="disabled" class="pos-btn pos-btn-primary pos-btn-sm">Check in</button>
        @endif
        @if ($this->requestLeaveAction->isVisible())
            <button type="button" wire:click="mountAction('requestLeave')" class="pos-btn pos-btn-secondary pos-btn-sm" data-pos-action="request_leave_{{ $suffix }}">Request leave</button>
        @endif
        @if ($me['payslip'] ?? null)
            <a href="{{ $me['payslip']['url'] }}" wire:navigate class="pos-btn pos-btn-ghost pos-btn-sm" data-pos-action="payslip">Payslip · {{ $me['payslip']['label'] }}</a>
        @endif
    </div>
    @if ($me['next_leave'])
        <p class="pos-body-sm mt-3"><span class="pos-muted">Next leave:</span> {{ $me['next_leave']['label'] }} <x-pos.status :tone="$me['next_leave']['status'] === 'approved' ? 'success' : 'warning'" :label="ucfirst($me['next_leave']['status'])" /></p>
    @endif
</section>
