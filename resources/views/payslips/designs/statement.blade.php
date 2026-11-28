{{-- Statement: totals on top, then one ledger of every line, like a bank statement. --}}
@php
    $m = fn ($v) => $money((float) $v, $doc['currency']);
    $num = fn ($v) => rtrim(rtrim(number_format((float) $v, 2, '.', ''), '0'), '.');
    $a = $doc['accent'];
    $ledger = [...array_map(fn ($l) => $l + ['kind' => 'Earning'], $doc['earnings']), ...array_map(fn ($l) => $l + ['kind' => 'Deduction'], $doc['deductions'])];
    $running = 0.0;
@endphp
<x-payslip.page :doc="$doc" :design="$design" :embedded="$embedded ?? false" :pdf="$pdf ?? false">
<x-slot:style>
    .st-head td { vertical-align: bottom; }
    .st-h1 { font-size: 1.55em; font-weight: bold; color: #111827; }
    .st-tag { padding: 2px 8px; background: {{ $doc['accent_soft'] }}; color: {{ $a }}; font-size: 0.82em; font-weight: bold; letter-spacing: .06em; text-transform: uppercase; }
    .st-who { margin-top: 10px; padding: 8px 0; border-top: 1px solid #e5e7eb; border-bottom: 1px solid #e5e7eb; line-height: 1.8; }
    .st-who span { color: #6b7280; }
    .st-boxes { margin-top: 12px; border-collapse: separate; border-spacing: 8px 0; }
    .st-boxes td { width: 25%; padding: 10px 12px; background: #f8fafc; border: 1px solid #e5e7eb; }
    .st-k { font-size: 0.85em; color: #6b7280; }
    .st-v { font-size: 1.2em; font-weight: bold; color: #111827; }
    .st-k-key { font-size: 0.85em; color: {{ $doc['accent_soft'] }}; }
    .st-v-key { font-size: 1.2em; font-weight: bold; color: #ffffff; }
    .st-ledger { margin-top: 14px; }
    .st-ledger th { text-align: left; padding: 6px 8px; font-size: 0.82em; text-transform: uppercase; letter-spacing: .05em; color: #6b7280; border-bottom: 2px solid #111827; }
    .st-ledger th.num { text-align: right; }
    .st-ledger td { padding: 6px 8px; border-bottom: 1px solid #e5e7eb; }
    .st-ledger tr.alt td { background: #fbfcfe; }
    .st-ledger .cr { color: #15803d; }
    .st-ledger .dr { color: #b91c1c; }
    .st-ledger tr.tot td { font-weight: bold; border-top: 2px solid #111827; border-bottom: 0; }
</x-slot:style>
    <table class="st-head"><tr>
        <td>
            @if ($doc['company']['logo'])<img class="logo" src="{{ $doc['company']['logo'] }}" alt=""><br>@endif
            <div class="st-h1">{{ $doc['company']['name'] }}</div>@if ($doc['company']['place'])<p class="muted">{{ $doc['company']['place'] }}</p>@endif
        </td>
        <td style="text-align:right"><span class="st-tag">{{ $doc['title'] }}</span><p><b>{{ $doc['period']['month'] }}</b>@if ($doc['revision']) <span class="revised">Revised</span>@endif</p><p class="muted">No. {{ $doc['number'] }}</p></td>
    </tr></table>
    <p class="st-who">@foreach ($doc['facts'] as $label => $value)<span>{{ $label }}:</span> {{ $value }}@if (! $loop->last) &nbsp;&nbsp;·&nbsp;&nbsp; @endif @endforeach</p>

    <table class="st-boxes"><tr>
        <td><div class="st-k">Earnings</div><div class="st-v">{{ $m($doc['totals']['earnings']) }}</div></td>
        <td><div class="st-k">Deductions</div><div class="st-v">{{ $m($doc['totals']['deductions']) }}</div></td>
        <td><div class="st-k">Days paid</div><div class="st-v">{{ $num($doc['days']['paid']) }} / {{ $num($doc['days']['in_period']) }}</div></td>
        <td class="st-key" style="background: {{ $a }}; border: 1px solid {{ $a }}"><div class="st-k-key">Net pay</div><div class="st-v-key">{{ $m($doc['totals']['net']) }}</div></td>
    </tr></table>

    <table class="st-ledger">
        <thead><tr><th style="width:24px">#</th><th>Description</th><th>Type</th><th class="num">Credit</th><th class="num">Debit</th><th class="num">Balance</th></tr></thead>
        <tbody>
            @foreach ($ledger as $i => $l)
                @php($running += $l['kind'] === 'Earning' ? $l['amount'] : -$l['amount'])
                <tr @class(['alt' => $i % 2 === 1])><td class="muted">{{ $i + 1 }}</td><td>{{ $l['name'] }}</td><td class="muted">{{ $l['kind'] }}</td>
                    <td class="num cr">{{ $l['kind'] === 'Earning' ? $m($l['amount']) : '' }}</td><td class="num dr">{{ $l['kind'] === 'Deduction' ? $m($l['amount']) : '' }}</td>
                    <td class="num">{{ $m($running) }}</td></tr>
            @endforeach
            <tr class="tot"><td></td><td colspan="2">Net pay</td><td class="num">{{ $m($doc['totals']['earnings']) }}</td><td class="num">{{ $m($doc['totals']['deductions']) }}</td><td class="num">{{ $m($doc['totals']['net']) }}</td></tr>
        </tbody>
    </table>
    @if ($doc['words'])<p class="muted" style="margin-top:6px">Net pay in words: {{ $doc['words'] }}</p>@endif

    @if ($doc['employer'] !== [] || $doc['tax'])
        <table class="st-ledger">
            <thead><tr><th colspan="2">Also this month</th><th class="num">Amount</th></tr></thead>
            <tbody>
                @foreach ($doc['employer'] as $l)<tr><td colspan="2">{{ $l['name'] }} <span class="muted">(paid by the employer)</span></td><td class="num">{{ $m($l['amount']) }}</td></tr>@endforeach
                @foreach ($doc['tax'] ?? [] as $label => $value)<tr><td colspan="2">{{ $label }}</td><td class="num">{{ $value }}</td></tr>@endforeach
            </tbody>
        </table>
    @endif
</x-payslip.page>
