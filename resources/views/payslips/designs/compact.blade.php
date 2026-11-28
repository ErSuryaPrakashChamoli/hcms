{{-- Compact: everything in one narrow column on half a page; quick to read and to print. --}}
@php
    $m = fn ($v) => $money((float) $v, $doc['currency']);
    $a = $doc['accent'];
@endphp
<x-payslip.page :doc="$doc" :design="$design" :embedded="$embedded ?? false" :pdf="$pdf ?? false">
<x-slot:style>
    .cp { width: 128mm; margin: 0 auto; font-size: 0.93em; }
    .cp td { padding: 2px 0; }
    .cp .head td { text-align: center; padding-bottom: 8px; border-bottom: 2px solid {{ $a }}; }
    .cp-h1 { font-size: 1.25em; font-weight: bold; color: #111827; }
    .cp-h2 { margin-top: 3px; font-size: 1em; font-weight: bold; color: {{ $a }}; letter-spacing: .05em; text-transform: uppercase; }
    .cp .k { color: #6b7280; width: 42%; }
    .cp .sec td { padding-top: 10px; font-size: 0.85em; text-transform: uppercase; letter-spacing: .08em; color: {{ $a }}; font-weight: bold; border-bottom: 1px solid #e5e7eb; }
    .cp .tot td { border-top: 1px solid #111827; font-weight: bold; padding-top: 4px; }
    .cp .net td { background: {{ $a }}; color: #ffffff; padding: 8px 10px; }
    .cp .net .amt { font-size: 1.35em; font-weight: bold; text-align: right; }
    .cp .words td { text-align: center; color: #6b7280; padding-top: 4px; }
    .foot { width: 128mm; margin-left: auto; margin-right: auto; }
</x-slot:style>
    <table class="cp">
        <tr class="head"><td colspan="2">
            @if ($doc['company']['logo'])<img class="logo" style="max-height:30px" src="{{ $doc['company']['logo'] }}" alt=""><br>@endif
            <div class="cp-h1">{{ $doc['company']['name'] }}</div>
            <div class="cp-h2">{{ $doc['title'] }} · {{ $doc['period']['month'] }}@if ($doc['revision']) <span class="revised">Revised</span>@endif</div>
            <div class="muted">No. {{ $doc['number'] }}</div>
        </td></tr>
        <tr><td colspan="2" style="height:6px"></td></tr>
        @foreach ($doc['facts'] as $label => $value)<tr><td class="k">{{ $label }}</td><td>{{ $value }}</td></tr>@endforeach
        <tr class="sec"><td colspan="2">Earnings</td></tr>
        @foreach ($doc['earnings'] as $l)<tr><td>{{ $l['name'] }}</td><td class="num">{{ $m($l['amount']) }}</td></tr>@endforeach
        <tr class="tot"><td>Total earnings</td><td class="num">{{ $m($doc['totals']['earnings']) }}</td></tr>
        <tr class="sec"><td colspan="2">Deductions</td></tr>
        @forelse ($doc['deductions'] as $l)<tr><td>{{ $l['name'] }}</td><td class="num">{{ $m($l['amount']) }}</td></tr>@empty<tr><td class="muted" colspan="2">None</td></tr>@endforelse
        <tr class="tot"><td>Total deductions</td><td class="num">{{ $m($doc['totals']['deductions']) }}</td></tr>
        @if ($doc['employer'] !== [])
            <tr class="sec"><td colspan="2">Employer contributions</td></tr>
            @foreach ($doc['employer'] as $l)<tr><td>{{ $l['name'] }}</td><td class="num">{{ $m($l['amount']) }}</td></tr>@endforeach
        @endif
        <tr><td colspan="2" style="height:10px"></td></tr>
        <tr class="net"><td>Net pay</td><td class="amt">{{ $m($doc['totals']['net']) }}</td></tr>
        @if ($doc['words'])<tr class="words"><td colspan="2">{{ $doc['words'] }}</td></tr>@endif
    </table>
</x-payslip.page>
