{{-- Modern: a coloured band with net pay in large figures; earnings and deductions as two cards. --}}
@php
    $m = fn ($v) => $money((float) $v, $doc['currency']);
    $num = fn ($v) => rtrim(rtrim(number_format((float) $v, 2, '.', ''), '0'), '.');
    $a = $doc['accent'];
    $o = $doc['settings'];
    $facts = array_diff_key($doc['facts'], array_flip(['Days paid', 'Loss of pay']));
@endphp
<x-payslip.page :doc="$doc" :design="$design" :embedded="$embedded ?? false" :pdf="$pdf ?? false">
<x-slot:style>
    .md-band { background: {{ $a }}; color: #ffffff; }
    .md-band td { padding: 16px 20px; vertical-align: middle; }
    .md-h1 { font-size: 1.4em; font-weight: bold; color: #ffffff; }
    .md-sub { color: {{ $doc['accent_soft'] }}; }
    .md-month { font-size: 1.15em; font-weight: bold; color: #ffffff; }
    .md-hero { background: {{ $doc['accent_dark'] }}; color: #ffffff; }
    .md-hero td { padding: 12px 20px; vertical-align: middle; }
    .md-lbl { font-size: 0.8em; text-transform: uppercase; letter-spacing: .08em; color: {{ $doc['accent_soft'] }}; }
    .md-note { font-size: 0.85em; color: {{ $doc['accent_soft'] }}; }
    .md-net { font-size: 2.3em; font-weight: bold; line-height: 1.15; color: #ffffff; }
    .md-chip { border-left: 1px solid {{ $a }}; }
    .md-chipv { font-size: 1.1em; font-weight: bold; color: #ffffff; }
    .md-facts { margin-top: 14px; }
    .md-facts td { padding: 4px 8px 6px 0; width: 25%; }
    .md-k { color: #6b7280; font-size: 0.85em; }
    .md-v { font-weight: bold; color: #111827; }
    .md-cards { margin-top: 12px; }
    .md-card { border: 1px solid #e5e7eb; }
    .md-card td { padding: 5px 12px; border-bottom: 1px solid #f1f5f9; }
    .md-card .hd td { padding-top: 10px; font-size: 0.85em; text-transform: uppercase; letter-spacing: .06em; color: #6b7280; font-weight: bold; border-bottom: 0; }
    .md-card .tot td { font-weight: bold; color: #111827; border-top: 2px solid #111827; border-bottom: 0; padding-bottom: 10px; }
</x-slot:style>
    <table class="md-band"><tr>
        <td>
            @if ($doc['company']['logo'])<img class="logo" style="max-height:34px;background:#ffffff;padding:4px" src="{{ $doc['company']['logo'] }}" alt=""><br>@endif
            <div class="md-h1">{{ $doc['company']['name'] }}</div>@if ($doc['company']['place'])<div class="md-sub">{{ $doc['company']['place'] }}</div>@endif
        </td>
        <td style="text-align:right">
            <div class="md-sub">{{ $doc['title'] }}@if ($doc['revision']) <span class="revised">Revised</span>@endif</div>
            <div class="md-month">{{ $doc['period']['month'] }}</div>
            <div class="md-sub">No. {{ $doc['number'] }}</div>
        </td>
    </tr></table>
    <table class="md-hero"><tr>
        <td style="width:46%"><div class="md-lbl">Take-home</div><div class="md-net">{{ $m($doc['totals']['net']) }}</div>@if ($doc['words'])<div class="md-note">{{ $doc['words'] }}</div>@endif</td>
        <td class="md-chip"><div class="md-lbl">Gross earnings</div><div class="md-chipv">{{ $m($doc['totals']['earnings']) }}</div></td>
        <td class="md-chip"><div class="md-lbl">Deductions</div><div class="md-chipv">{{ $m($doc['totals']['deductions']) }}</div></td>
        @if ($o['show_attendance'])
            <td class="md-chip"><div class="md-lbl">Days paid</div><div class="md-chipv">{{ $num($doc['days']['paid']) }} / {{ $num($doc['days']['in_period']) }}</div>@if ((float) $doc['days']['lop'] > 0)<div class="md-note">{{ $num($doc['days']['lop']) }} loss of pay</div>@endif</td>
        @endif
    </tr></table>

    <table class="md-facts">
        @foreach (array_chunk($facts, 4, true) as $row)
            <tr>@foreach ($row as $label => $value)<td><div class="md-k">{{ $label }}</div><div class="md-v">{{ $value }}</div></td>@endforeach @for ($i = count($row); $i < 4; $i++)<td></td>@endfor</tr>
        @endforeach
    </table>

    <table class="md-cards"><tr>
        <td style="width:50%;padding-right:7px">
            <table class="md-card">
                <tr class="hd"><td><span style="color:#15803d">●</span> Earnings</td><td class="num">This month</td>@if ($doc['ytd'])<td class="num">Year to date</td>@endif</tr>
                @foreach ($doc['earnings'] as $l)<tr><td>{{ $l['name'] }}</td><td class="num">{{ $m($l['amount']) }}</td>@if ($doc['ytd'])<td class="num muted">{{ $m($l['ytd']) }}</td>@endif</tr>@endforeach
                <tr class="tot"><td>Total</td><td class="num">{{ $m($doc['totals']['earnings']) }}</td>@if ($doc['ytd'])<td class="num">{{ $m($doc['ytd']['earnings']) }}</td>@endif</tr>
            </table>
        </td>
        <td style="width:50%;padding-left:7px">
            <table class="md-card">
                <tr class="hd"><td><span style="color:#b91c1c">●</span> Deductions</td><td class="num">This month</td>@if ($doc['ytd'])<td class="num">Year to date</td>@endif</tr>
                @forelse ($doc['deductions'] as $l)<tr><td>{{ $l['name'] }}</td><td class="num">{{ $m($l['amount']) }}</td>@if ($doc['ytd'])<td class="num muted">{{ $m($l['ytd']) }}</td>@endif</tr>
                @empty<tr><td class="muted">Nothing deducted this month</td><td></td>@if ($doc['ytd'])<td></td>@endif</tr>@endforelse
                <tr class="tot"><td>Total</td><td class="num">{{ $m($doc['totals']['deductions']) }}</td>@if ($doc['ytd'])<td class="num">{{ $m($doc['ytd']['deductions']) }}</td>@endif</tr>
            </table>
        </td>
    </tr>
    @if ($doc['employer'] !== [] || $doc['tax'])
        <tr>
            <td style="width:50%;padding:12px 7px 0 0">
                @if ($doc['employer'] !== [])
                    <table class="md-card"><tr class="hd"><td>Employer contributions</td><td></td></tr>
                        @foreach ($doc['employer'] as $l)<tr><td>{{ $l['name'] }}</td><td class="num">{{ $m($l['amount']) }}</td></tr>@endforeach</table>
                @endif
            </td>
            <td style="width:50%;padding:12px 0 0 7px">
                @if ($doc['tax'])
                    <table class="md-card"><tr class="hd"><td>Income tax</td><td></td></tr>
                        @foreach ($doc['tax'] as $label => $value)<tr><td>{{ $label }}</td><td class="num">{{ $value }}</td></tr>@endforeach</table>
                @endif
            </td>
        </tr>
    @endif
    </table>
</x-payslip.page>
