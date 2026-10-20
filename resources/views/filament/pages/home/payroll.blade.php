{{-- Payroll --}}
@if ($h['payroll'] ?? null)
    <x-pos.section title="Payroll" :link="$h['payroll']['url']" link-label="Control room">
        <div class="pos-panel pos-panel-pad grid gap-3">
            <p class="pos-section-title">{{ $h['payroll']['run']['label'] ?? 'No payroll run yet' }}</p>
            @if ($h['payroll']['run'])
                <div class="pos-figures">
                    <x-pos.figure :value="$h['payroll']['run']['status']" label="Status" />
                    <x-pos.figure :value="number_format($h['payroll']['run']['employees'])" label="Employees" />
                    <x-pos.figure :value="$h['payroll']['run']['exceptions']" label="Exceptions" :meaning="$h['payroll']['run']['exceptions'] > 0 ? 'bad' : null" :delta="$h['payroll']['run']['exceptions'] > 0 ? 'Resolve before sign-off' : null" />
                </div>
            @else
                <p class="pos-meta">Open a run for the next pay period from the control room.</p>
            @endif
        </div>
    </x-pos.section>
@endif
