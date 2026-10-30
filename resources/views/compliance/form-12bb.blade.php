@php($inr = fn ($v) => '₹'.number_format((float) $v, 2))
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>Form 12BB {{ $declaration->financial_year }}</title>
    <style>
        body { font-family: Arial, Helvetica, sans-serif; margin: 0; padding: 32px; color: #111827; font-size: 13px; }
        h1 { font-size: 18px; text-align: center; margin: 0 0 4px; }
        .sub { text-align: center; color: #4b5563; margin-bottom: 20px; }
        table { width: 100%; border-collapse: collapse; margin-bottom: 18px; }
        th, td { border: 1px solid #9ca3af; padding: 6px 8px; text-align: left; vertical-align: top; }
        th { background: #f3f4f6; }
        td.num { text-align: right; white-space: nowrap; }
        h2 { font-size: 14px; margin: 18px 0 6px; }
        .muted { color: #6b7280; }
        .sign { margin-top: 36px; display: flex; justify-content: space-between; }
        @media print { body { padding: 0; } .noprint { display: none; } }
    </style>
</head>
<body>
<p class="noprint" style="text-align:right"><button onclick="window.print()">Print</button></p>
<h1>FORM NO. 12BB</h1>
<div class="sub">Statement showing particulars of claims by an employee for deduction of tax (rule 26C) · Financial year {{ $declaration->financial_year }}</div>

<table>
    <tr><th style="width:35%">Name of the employee</th><td>{{ $employee?->person?->full_name }}</td></tr>
    <tr><th>Employee code</th><td>{{ $employee?->employee_code }}</td></tr>
    <tr><th>Permanent Account Number of the employee</th><td>{{ $pan ?? '—' }}</td></tr>
    <tr><th>Tax regime chosen</th><td>{{ config("peopleos.compliance.tax_regimes.{$declaration->regime}", $declaration->regime) }}</td></tr>
</table>

<h2>1. House rent allowance</h2>
<table>
    <tr><th style="width:35%">Rent paid to the landlord</th><td class="num">{{ $rent ? $inr($rent->declared_amount) : '—' }}</td></tr>
    @if ($rent)
        <tr><th>Address of the property</th><td>{{ $rent->details['address'] ?? '—' }}</td></tr>
        @foreach ($landlords as $i => $landlord)
            <tr><th>Landlord {{ $i + 1 }}: name and PAN</th><td>{{ $landlord['name'] ?? '—' }} · {{ $landlord['pan'] ?? 'PAN not given' }}</td></tr>
        @endforeach
    @endif
</table>

<h2>2. Leave travel concession or assistance</h2>
<table><tr><th style="width:35%">Amount</th><td class="muted">Not claimed through this declaration</td></tr></table>

<h2>3. Deduction of interest on borrowing (section 24(b))</h2>
<table>
    <tr><th>Item</th><th class="num" style="width:20%">Declared</th><th class="num" style="width:20%">Verified</th></tr>
    @forelse ($housing as $item)
        <tr><td>{{ $item->description }}</td><td class="num">{{ $inr($item->declared_amount) }}</td><td class="num">{{ $item->proof_status === 'verified' ? $inr($item->proof_amount) : '—' }}</td></tr>
    @empty
        <tr><td colspan="3" class="muted">None claimed</td></tr>
    @endforelse
</table>

<h2>4. Deductions under Chapter VI-A</h2>
<table>
    <tr><th style="width:18%">Section</th><th>Item</th><th class="num" style="width:18%">Declared</th><th class="num" style="width:18%">Verified</th></tr>
    @forelse ($chapterVia as $section => $rows)
        @foreach ($rows as $item)
            <tr><td>{{ $sections[$section]['code'] ?? $section }}</td><td>{{ $item->description }}</td><td class="num">{{ $inr($item->declared_amount) }}</td><td class="num">{{ $item->proof_status === 'verified' ? $inr($item->proof_amount) : '—' }}</td></tr>
        @endforeach
    @empty
        <tr><td colspan="4" class="muted">None claimed</td></tr>
    @endforelse
</table>

@if ($otherIncome->isNotEmpty())
    <h2>Other income reported for tax deduction (section 192(2B))</h2>
    <table>
        @foreach ($otherIncome as $item)
            <tr><td>{{ $item->description }}</td><td class="num" style="width:20%">{{ $inr($item->declared_amount) }}</td></tr>
        @endforeach
    </table>
@endif

@if ((float) $declaration->previous_employer_income > 0 || (float) $declaration->previous_employer_tds > 0)
    <h2>Income from a previous employer in the year (Form 12B)</h2>
    <table>
        <tr><th style="width:35%">Employer</th><td>{{ $declaration->previousEmployer('name') ?? '—' }} @if ($declaration->previousEmployer('tan')) (TAN {{ $declaration->previousEmployer('tan') }}) @endif</td></tr>
        <tr><th>Period</th><td>{{ $declaration->previousEmployer('from') ?? '—' }} to {{ $declaration->previousEmployer('to') ?? '—' }}</td></tr>
        <tr><th>Taxable salary</th><td class="num">{{ $inr($declaration->previous_employer_income) }}</td></tr>
        <tr><th>Tax deducted</th><td class="num">{{ $inr($declaration->previous_employer_tds) }}</td></tr>
        <tr><th>PF / professional tax deducted</th><td class="num">{{ $inr($declaration->previousEmployer('pf') ?? 0) }} / {{ $inr($declaration->previousEmployer('pt') ?? 0) }}</td></tr>
    </table>
@endif

<h2>Verification</h2>
<p>I, {{ $employee?->person?->full_name }}, do hereby certify that the information given above is complete and correct.</p>
<div class="sign"><span>Place: ______________ &nbsp; Date: ______________</span><span>Signature of the employee</span></div>
<p class="muted" style="margin-top:24px">Status of the declaration: {{ \App\Domain\Compliance\Models\EmployeeTaxDeclaration::STATUSES[$declaration->status] ?? $declaration->status }}. Generated by PeopleOS on {{ now()->format('j M Y') }}.</p>
</body>
</html>
