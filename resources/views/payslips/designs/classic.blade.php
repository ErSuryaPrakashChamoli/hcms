{{-- Classic: the traditional payslip — details in two columns, earnings beside deductions, net pay in words. --}}
@php
    $m = fn ($v) => $money((float) $v, $doc['currency']);
    $a = $doc['accent'];
    $ytd = $doc['ytd'] !== null;
    $rows = max(count($doc['earnings']), count($doc['deductions']));
    $span = $ytd ? 3 : 2;
@endphp
<x-payslip.page :doc="$doc" :design="$design" :embedded="$embedded ?? false" :pdf="$pdf ?? false">
<x-slot:style>
    .c-head td { padding-bottom: 10px; border-bottom: 3px solid {{ $a }}; vertical-align: middle; }
    .c-h1 { font-size: 1.5em; font-weight: bold; color: #111827; }
    .c-h2 { font-size: 1.15em; font-weight: bold; color: {{ $a }}; text-transform: uppercase; letter-spacing: .06em; }
    .c-facts { margin-top: 12px; border: 1px solid #e5e7eb; }
    .c-facts td { padding: 5px 8px; border-bottom: 1px solid #e5e7eb; }
    .c-facts td.k { color: #6b7280; background: #f8fafc; width: 17%; }
    .c-lines { margin-top: 14px; border: 1px solid #cbd5e1; }
    .c-lines th { background: {{ $a }}; color: #ffffff; padding: 6px 8px; text-align: left; font-size: 0.92em; }
    .c-lines th.num { text-align: right; }
    .c-lines td { padding: 5px 8px; border-bottom: 1px solid #e5e7eb; }
    .c-lines .split { border-left: 1px solid #cbd5e1; }
    .c-lines tr.tot td { background: #f8fafc; font-weight: bold; border-top: 1px solid #cbd5e1; }
    .c-net { margin-top: 12px; border: 2px solid {{ $a }}; }
    .c-net td { padding: 10px 14px; vertical-align: middle; }
    .c-net .amt { font-size: 1.45em; font-weight: bold; color: {{ $a }}; text-align: right; white-space: nowrap; }
    .c-extra { margin-top: 12px; }
    .c-extra h3 { font-size: 1em; margin-bottom: 4px; color: #111827; }
    .c-extra table.in td { padding: 3px 0; border-bottom: 1px dashed #e5e7eb; }
</x-slot:style>
    <table class="c-head"><tr>
        <td>
            @if ($doc['company']['logo'])<img class="logo" src="{{ $doc['company']['logo'] }}" alt=""><br>@endif
            <h1 class="c-h1">{{ $doc['company']['name'] }}</h1>@if ($doc['company']['place'])<p class="muted">{{ $doc['company']['place'] }}</p>@endif
        </td>
        <td style="text-align:right">
            <h2 class="c-h2">{{ $doc['title'] }}</h2>
            <p>{{ $doc['period']['month'] }}@if ($doc['revision']) <span class="revised">Revised</span>@endif</p>
            <p class="muted">No. {{ $doc['number'] }}</p>
        </td>
    </tr></table>

    <table class="c-facts">
        @foreach (array_chunk($doc['facts'], 2, true) as $pair)
            <tr>
                @foreach ($pair as $label => $value)<td class="k">{{ $label }}</td><td>{{ $value }}</td>@endforeach
                @if (count($pair) === 1)<td class="k"></td><td></td>@endif
            </tr>
        @endforeach
    </table>

    <table class="c-lines">
        <thead><tr><th>Earnings</th><th class="num">Amount</th>@if ($ytd)<th class="num">Year to date</th>@endif<th class="split">Deductions</th><th class="num">Amount</th>@if ($ytd)<th class="num">Year to date</th>@endif</tr></thead>
        <tbody>
            @for ($i = 0; $i < $rows; $i++)
                @php($e = $doc['earnings'][$i] ?? null)
                @php($d = $doc['deductions'][$i] ?? null)
                <tr>
                    <td>{{ $e['name'] ?? '' }}</td><td class="num">{{ $e ? $m($e['amount']) : '' }}</td>@if ($ytd)<td class="num muted">{{ $e ? $m($e['ytd']) : '' }}</td>@endif
                    <td class="split">{{ $d['name'] ?? '' }}</td><td class="num">{{ $d ? $m($d['amount']) : '' }}</td>@if ($ytd)<td class="num muted">{{ $d ? $m($d['ytd']) : '' }}</td>@endif
                </tr>
            @endfor
            <tr class="tot">
                <td>Total earnings</td><td class="num">{{ $m($doc['totals']['earnings']) }}</td>@if ($ytd)<td class="num">{{ $m($doc['ytd']['earnings']) }}</td>@endif
                <td class="split">Total deductions</td><td class="num">{{ $m($doc['totals']['deductions']) }}</td>@if ($ytd)<td class="num">{{ $m($doc['ytd']['deductions']) }}</td>@endif
            </tr>
        </tbody>
    </table>

    <table class="c-net"><tr>
        <td><span class="muted">Net pay for {{ $doc['period']['month'] }}</span>@if ($doc['words'])<br>{{ $doc['words'] }}@endif</td>
        <td class="amt">{{ $m($doc['totals']['net']) }}</td>
    </tr></table>

    @if ($doc['employer'] !== [] || $doc['tax'])
        <table class="c-extra"><tr>
            <td style="width:50%;padding-right:8px">
                @if ($doc['employer'] !== [])
                    <h3>Employer contributions</h3>
                    <table class="in">@foreach ($doc['employer'] as $l)<tr><td>{{ $l['name'] }}</td><td class="num">{{ $m($l['amount']) }}</td></tr>@endforeach</table>
                    <p class="muted" style="margin-top:4px">Paid by the employer on top of your pay; part of your cost to company.</p>
                @endif
            </td>
            <td style="width:50%;padding-left:8px">
                @if ($doc['tax'])
                    <h3>Income tax</h3>
                    <table class="in">@foreach ($doc['tax'] as $label => $value)<tr><td>{{ $label }}</td><td class="num">{{ $value }}</td></tr>@endforeach</table>
                @endif
            </td>
        </tr></table>
    @endif
</x-payslip.page>
