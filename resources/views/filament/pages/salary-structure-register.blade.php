<x-filament-panels::page>
    {{--
        Owner, 2026-11-27: who has a salary and who is left comes first — two cards by the salary in force today (each shows
        the register filtered to it), then the requests in flight as small chips (HROne parity PO-2's states). The cards
        count the whole register in your access.
    --}}
    @php
        $inForce = $this->inForceCounts();
        $counts = $this->stateCounts();
        $total = max(1, $inForce['assigned'] + $inForce['not_assigned']);
    @endphp
    <div class="pos-sal-reg" data-salary-cards>
        <button type="button" wire:click="showInForce('yes')" class="pos-sal-reg-card" data-tone="clear" data-in-force="yes">
            <span class="pos-sal-reg-label"><x-filament::icon icon="heroicon-o-check-circle" class="size-5" />Salary assigned</span>
            <span class="pos-sal-reg-count">{{ $inForce['assigned'] }}</span>
            <span class="pos-sal-reg-sub">A salary in force today · {{ round($inForce['assigned'] / $total * 100) }}% of employees</span>
            <span class="pos-step-meter" style="--tone: var(--pos-success); --pct: {{ round($inForce['assigned'] / $total * 100) }}" aria-hidden="true"><span></span></span>
        </button>
        <button type="button" wire:click="showInForce('no')" class="pos-sal-reg-card" data-tone="{{ $inForce['not_assigned'] > 0 ? 'warning' : 'clear' }}" data-in-force="no">
            <span class="pos-sal-reg-label"><x-filament::icon icon="heroicon-o-exclamation-circle" class="size-5" />Not assigned yet</span>
            <span class="pos-sal-reg-count">{{ $inForce['not_assigned'] }}</span>
            <span class="pos-sal-reg-sub">{{ $inForce['not_assigned'] > 0 ? 'No salary in force today: assign one from the row' : 'Everyone has a salary' }}</span>
        </button>
        <div class="pos-sal-reg-requests" data-state-cards>
            <p class="pos-sal-reg-sub">Requests</p>
            @foreach (['pending' => 'Pending', 'approved' => 'Approved, not in force yet', 'rejected' => 'Rejected last'] as $key => $label)
                <button type="button" wire:click="showState('{{ $key }}')" class="pos-prof-chip" data-state="{{ $key }}">{{ $label }} <span class="pos-num" @if ($counts[$key] === 0) data-zero @endif>{{ $counts[$key] }}</span></button>
            @endforeach
        </div>
    </div>

    {{ $this->table }}
</x-filament-panels::page>
