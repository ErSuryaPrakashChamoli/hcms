{{--
    Salary history → Compare (owner, 2026-11-27): one approved salary against the one before it, both on its effective date
    (SalaryRevisions::revision), with who proposed, reviewed, approved and executed it. Read-only.
--}}
@php
    $money = fn ($value) => $value === null ? '—' : number_format((float) $value, 2);
    $signed = fn (?float $p) => $p === null ? '—' : ($p > 0 ? '+' : '').number_format($p, 2).'%';
    $row = $revision['row'] ?? null;
@endphp
<div class="pos-sal-preview" data-salary-revision="{{ $row['assignment_id'] ?? '' }}">
    @if ($row === null)
        <x-pos.state variant="denied" size="inline" title="This salary is not part of the employee's approved history." />
    @else
        <dl class="pos-step-stats">
            <div class="pos-step-stat is-key"><dt>Annual CTC</dt><dd>{{ $row['currency'] }} {{ $money($row['ctc']) }}</dd>
                @if ($row['previous_ctc'] !== null)<span class="pos-step-delta">from {{ $money($row['previous_ctc']) }}</span>@endif</div>
            <div class="pos-step-stat"><dt>Change</dt><dd>{{ $signed($row['percent']) }}</dd>
                @if ($row['change'] !== null)<span class="pos-step-delta">{{ $row['change'] >= 0 ? '+' : '−' }}{{ $money(abs($row['change'])) }} a year</span>@endif</div>
            <div class="pos-step-stat"><dt>Effective from</dt><dd>{{ $row['from']->format('j M Y') }}</dd>
                <span class="pos-step-delta">{{ $row['type'] }}@if ($row['months_since_previous'] !== null) · {{ $row['months_since_previous'] }} {{ \Illuminate\Support\Str::plural('month', $row['months_since_previous']) }} after the one before @endif</span></div>
            <div class="pos-step-stat"><dt>Structure</dt><dd class="pos-sal-text">{{ $row['structure'] }}</dd></div>
        </dl>

        @if ($revision['compare'])
            @include('filament.compensation.salary-compare', ['compare' => $revision['compare'], 'caption' => 'This salary against the one before it'])
            @if ($revision['compare']['first'])<p class="pos-caption">The first salary: nothing before it to compare.</p>@endif
        @else
            <x-pos.state variant="partial" size="inline" title="The breakup cannot be shown." :why="$revision['failure']" />
        @endif

        @if (($arrears ?? []) !== [])
            {{-- Owner, 2026-11-27: the arrears (or recoveries) this back-dated change recorded for months already paid. --}}
            <section class="pos-sal-card" aria-label="Arrears" data-revision-arrears>
                <h4 class="pos-sal-card-title">Arrears for months already paid</h4>
                <table class="pos-sal-arrears">
                    <thead><tr><th scope="col">For</th><th scope="col">Pay item</th><th scope="col" class="is-num">Amount</th><th scope="col">Paid in</th><th scope="col">Status</th></tr></thead>
                    <tbody>
                        @foreach ($arrears as $a)
                            <tr><td>{{ $a['month'] ?? '—' }}</td><td>{{ $a['name'] }}</td>
                                <td class="is-num" data-tone="{{ $a['amount'] < 0 ? 'down' : 'up' }}">{{ \App\Filament\Support\Compensation\SalaryFormat::signed($a['amount']) }}</td>
                                <td>{{ $a['payout'] ?? '—' }}</td><td>{{ ucfirst($a['status']) }}</td></tr>
                        @endforeach
                    </tbody>
                    <tfoot><tr><th scope="row" colspan="2">Total</th><td class="is-num">{{ \App\Filament\Support\Compensation\SalaryFormat::signed(array_sum(array_column($arrears, 'amount'))) }}</td><td colspan="2"></td></tr></tfoot>
                </table>
                <p class="pos-caption">Each month already paid was worked out again on this salary; the difference of every pay item is paid (or recovered) with the payroll month shown. They are on Payroll → Adjustments.</p>
            </section>
        @endif

        @if ($full)
            <section class="pos-sal-card" aria-label="Audit trail">
                <h4 class="pos-sal-card-title">Audit trail</h4>
                @if ($row['audit'] === [])
                    <p class="pos-caption">Recorded before compensation changes (no proposal behind it).</p>
                @else
                    <ol class="pos-sal-audit">
                        @foreach ($row['audit'] as $step)
                            <li><span class="pos-sal-audit-step">{{ $step['step'] }}</span>
                                <span>{{ $step['who'] ?? 'System' }} · {{ $step['at']?->format('j M Y, H:i') }}</span></li>
                        @endforeach
                    </ol>
                    <p class="pos-caption">Reference {{ $row['reference'] }} · {{ $row['source'] }}@if ($row['reason']) · Reason: {{ $row['reason'] }}@endif</p>
                @endif
            </section>
        @endif
    @endif
</div>
