{{-- Detailed: the year to date beside every line, employer contributions and the income-tax summary. --}}
@php
    $m = fn ($v) => $money((float) $v, $doc['currency']);
    $a = $doc['accent'];
    $ytd = $doc['ytd'] !== null;
@endphp
<x-payslip.page :doc="$doc" :design="$design" :embedded="$embedded ?? false" :pdf="$pdf ?? false">
<x-slot:style>
    .d-head { background: #f8fafc; border: 1px solid #e5e7eb; border-top: 4px solid {{ $a }}; }
    .d-head td { padding: 12px 14px; vertical-align: middle; }
    .d-h1 { font-size: 1.35em; font-weight: bold; color: #111827; }
    .d-h2 { font-size: 1em; font-weight: bold; color: {{ $a }}; text-transform: uppercase; letter-spacing: .06em; }
    .d-facts { margin-top: 10px; border: 1px solid #e5e7eb; }
    .d-facts td { padding: 6px 10px; border: 1px solid #e5e7eb; width: 33.33%; }
    .d-k { font-size: 0.78em; color: #6b7280; text-transform: uppercase; letter-spacing: .05em; }
    .d-v { font-weight: bold; color: #111827; }
    .d-h3 { font-size: 1em; font-weight: bold; color: #111827; margin: 12px 0 4px; padding-left: 7px; border-left: 4px solid {{ $a }}; }
    .d-table th { text-align: left; font-size: 0.82em; text-transform: uppercase; letter-spacing: .05em; color: #6b7280; padding: 5px 8px; border-bottom: 2px solid #111827; }
    .d-table th.num { text-align: right; }
    .d-table td { padding: 5px 8px; border-bottom: 1px solid #e5e7eb; }
    .d-table tr.tot td { font-weight: bold; border-bottom: 2px solid #111827; background: #f8fafc; }
    .d-net { margin-top: 14px; border: 1px solid {{ $a }}; }
    .d-net td { padding: 10px 12px; width: 33.33%; border-left: 1px solid #e5e7eb; }
    .d-nk { color: #6b7280; font-size: 0.85em; }
    .d-nv { font-size: 1.2em; font-weight: bold; color: #111827; }
    .d-key { background: {{ $a }}; }
    .d-nk-key { color: {{ $doc['accent_soft'] }}; font-size: 0.85em; }
    .d-nv-key { color: #ffffff; font-size: 1.4em; font-weight: bold; }
</x-slot:style>
    <table class="d-head"><tr>
        @if ($doc['company']['logo'])<td style="width:1%"><img class="logo" src="{{ $doc['company']['logo'] }}" alt=""></td>@endif
        <td><div class="d-h1">{{ $doc['company']['name'] }}</div>@if ($doc['company']['place'])<p class="muted">{{ $doc['company']['place'] }}</p>@endif</td>
        <td style="text-align:right"><div class="d-h2">{{ $doc['title'] }}</div><p><b>{{ $doc['period']['month'] }}</b>@if ($doc['revision']) <span class="revised">Revised</span>@endif</p>
            <p class="muted">{{ $doc['period']['range'] }} · No. {{ $doc['number'] }}</p></td>
    </tr></table>

    <table class="d-facts">
        @foreach (array_chunk($doc['facts'], 3, true) as $row)
            <tr>@foreach ($row as $label => $value)<td><div class="d-k">{{ $label }}</div><div class="d-v">{{ $value }}</div></td>@endforeach @for ($i = count($row); $i < 3; $i++)<td></td>@endfor</tr>
        @endforeach
    </table>

    <table><tr>
        <td style="width:50%;padding-right:7px">
            <p class="d-h3">Earnings</p>
            <table class="d-table"><thead><tr><th>Pay item</th><th class="num">This month</th>@if ($ytd)<th class="num">Year to date</th>@endif</tr></thead><tbody>
                @foreach ($doc['earnings'] as $l)<tr><td>{{ $l['name'] }}</td><td class="num">{{ $m($l['amount']) }}</td>@if ($ytd)<td class="num">{{ $m($l['ytd']) }}</td>@endif</tr>@endforeach
                <tr class="tot"><td>Total earnings</td><td class="num">{{ $m($doc['totals']['earnings']) }}</td>@if ($ytd)<td class="num">{{ $m($doc['ytd']['earnings']) }}</td>@endif</tr>
            </tbody></table>
        </td>
        <td style="width:50%;padding-left:7px">
            <p class="d-h3">Deductions</p>
            <table class="d-table"><thead><tr><th>Deduction</th><th class="num">This month</th>@if ($ytd)<th class="num">Year to date</th>@endif</tr></thead><tbody>
                @forelse ($doc['deductions'] as $l)<tr><td>{{ $l['name'] }}</td><td class="num">{{ $m($l['amount']) }}</td>@if ($ytd)<td class="num">{{ $m($l['ytd']) }}</td>@endif</tr>
                @empty<tr><td class="muted" colspan="3">Nothing deducted this month</td></tr>@endforelse
                <tr class="tot"><td>Total deductions</td><td class="num">{{ $m($doc['totals']['deductions']) }}</td>@if ($ytd)<td class="num">{{ $m($doc['ytd']['deductions']) }}</td>@endif</tr>
            </tbody></table>
        </td>
    </tr></table>

    <table class="d-net"><tr>
        <td style="border-left:0"><div class="d-nk">Gross pay</div><div class="d-nv">{{ $m($doc['totals']['gross']) }}</div></td>
        <td><div class="d-nk">Deductions</div><div class="d-nv">{{ $m($doc['totals']['deductions']) }}</div></td>
        <td class="d-key"><div class="d-nk-key">Net pay{{ $ytd ? ' · '.$m($doc['ytd']['net']).' this year' : '' }}</div><div class="d-nv-key">{{ $m($doc['totals']['net']) }}</div></td>
    </tr></table>
    @if ($doc['words'])<p class="muted" style="margin-top:6px">In words: {{ $doc['words'] }}</p>@endif

    @if ($doc['employer'] !== [] || $doc['tax'])
        <table><tr>
            <td style="width:50%;padding-right:7px">
                @if ($doc['employer'] !== [])
                    <p class="d-h3">Employer contributions</p>
                    <table class="d-table"><tbody>@foreach ($doc['employer'] as $l)<tr><td>{{ $l['name'] }}</td><td class="num">{{ $m($l['amount']) }}</td>@if ($ytd)<td class="num muted">{{ $m($l['ytd']) }}</td>@endif</tr>@endforeach
                        <tr class="tot"><td>In all</td><td class="num">{{ $m($doc['totals']['employer']) }}</td>@if ($ytd)<td></td>@endif</tr></tbody></table>
                @endif
            </td>
            <td style="width:50%;padding-left:7px">
                @if ($doc['tax'])
                    <p class="d-h3">Income tax</p>
                    <table class="d-table"><tbody>@foreach ($doc['tax'] as $label => $value)<tr><td>{{ $label }}</td><td class="num">{{ $value }}</td></tr>@endforeach</tbody></table>
                @endif
            </td>
        </tr></table>
    @endif
</x-payslip.page>
