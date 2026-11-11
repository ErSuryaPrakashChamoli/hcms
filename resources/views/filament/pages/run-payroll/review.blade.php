{{-- Streamline review (8D): the calculated month before approval — headline tiles with the change from the last closed
     month, the statutory and other totals as a ledger, and the unusual net pay changes. --}}
@php
    $t = $r['totals'];
    $money = fn ($v) => number_format((float) $v, 2);
    $pct = fn ($v) => $v === null ? null : (($v > 0 ? '+' : '').number_format($v, 1).'%');
@endphp
<div class="pos-step-review" data-review>
    <dl class="pos-step-stats">
        @foreach ([
            ['Employees', number_format($t['employees']), $r['change']['employees'] ?? null, false],
            ['Gross earnings', $money($t['gross']), $r['change']['gross'] ?? null, false],
            ['Net payable', $money($t['net']), $r['change']['net'] ?? null, true],
            ['Employer cost', $money($t['employer_cost']), $r['change']['employer_cost'] ?? null, false],
        ] as [$label, $value, $change, $key])
            <div @class(['pos-step-stat', 'is-key' => $key]) data-tone="idle">
                <dt>{{ $label }}</dt><dd>{{ $value }}</dd>
                @if ($r['previous'] && $pct($change) !== null)
                    <span class="pos-step-delta" data-dir="{{ $change > 0 ? 'up' : ($change < 0 ? 'down' : 'flat') }}">
                        <x-filament::icon :icon="$change > 0 ? 'heroicon-m-arrow-trending-up' : ($change < 0 ? 'heroicon-m-arrow-trending-down' : 'heroicon-m-minus')" class="pos-step-delta-icon" />
                        {{ $pct($change) }} on {{ $r['previous']['label'] }}
                    </span>
                @endif
            </div>
        @endforeach
    </dl>

    <section class="pos-step-group" aria-label="Totals">
        <h3 class="pos-step-subhead">Totals</h3>
        <dl class="pos-step-ledger">
            @foreach ([
                ['Total deductions', $money($t['deductions'])], ['Loss of pay (days)', number_format($t['lop_days'], 1)],
                ['PF (employees)', $money($t['pf_employee'])], ['PF (employer, with admin)', $money($t['pf_employer'])],
                ['ESI (employee and employer)', $money($t['esi'])], ['Professional tax', $money($t['pt'])],
                ['TDS', $money($t['tds'])], ['Reimbursements paid', $money($t['reimbursements'])],
                ['Loan and advance recoveries', $money($t['recoveries'])], ['New joiners / leavers in the run', $r['joiners'].' / '.$r['leavers']],
                ['Blocking exceptions / warnings', $r['exceptions'].' / '.$r['warnings']],
                ['New in this run / not in it since last month', $r['previous'] ? $r['new_in_run'].' / '.$r['missing_from_run'] : '—'],
            ] as [$label, $value])
                <div><dt>{{ $label }}</dt><dd>{{ $value }}</dd></div>
            @endforeach
        </dl>
    </section>

    @if (! $r['previous'])
        <p class="pos-step-note">No earlier closed month to compare with.</p>
    @elseif ($r['variances'] === [])
        <p class="pos-step-note is-good"><x-filament::icon icon="heroicon-m-check-circle" class="pos-step-note-icon" /> No unusual net pay changes from {{ $r['previous']['label'] }} (20% and 1,000 or more).</p>
    @else
        <section class="pos-step-group" aria-label="Unusual net pay changes">
            <h3 class="pos-step-subhead">Unusual net pay changes from {{ $r['previous']['label'] }} <span>{{ $r['variance_count'] }}</span></h3>
            <div class="pos-step-table-wrap" tabindex="0" role="region" aria-label="Unusual net pay changes">
                <table class="pos-step-table">
                    <thead><tr><th>Code</th><th>Name</th><th class="is-num">{{ $r['previous']['label'] }}</th><th class="is-num">Now</th><th class="is-num">Change</th></tr></thead>
                    <tbody>
                        @foreach ($r['variances'] as $v)
                            <tr>
                                <td class="is-code">{{ $v['code'] }}</td><td>{{ $v['name'] }}</td>
                                <td class="is-num">{{ $money($v['before']) }}</td><td class="is-num">{{ $money($v['now']) }}</td>
                                <td class="is-num" data-dir="{{ $v['change'] > 0 ? 'up' : 'down' }}">{{ $money($v['change']) }} ({{ $pct($v['share']) ?? '—' }})</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </section>
    @endif
    @if ($runUrl)
        <a href="{{ $runUrl }}" wire:navigate class="pos-step-link">Open the run for each employee's breakdown<x-filament::icon icon="heroicon-m-arrow-up-right" class="pos-step-link-icon" /></a>
    @endif
</div>
