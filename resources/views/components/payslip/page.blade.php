@props(['doc', 'design', 'embedded' => false, 'pdf' => false])
{{--
    Owner, 2026-11-27/28: the page every payslip design prints on (PayslipDesigns) — in the browser and, the same HTML, in
    the password-protected PDF (PayslipPdf, mPDF). So the designs are built from tables with plain colours: no flexbox,
    grid, CSS variables or color-mix, which the PDF engine does not know. A component, not a layout: the design gallery
    renders several designs in one request. $embedded (previews) and $pdf leave out the toolbar and the grey backdrop.
--}}
@php
    $plain = $embedded || $pdf;
    $a = $doc['accent'];
@endphp
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="robots" content="noindex">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $doc['title'] }} · {{ $doc['employee']['name'] }} · {{ $doc['period']['month'] }}</title>
    <style>
        body { margin: 0; background: {{ $plain ? '#ffffff' : '#e9ecf2' }}; font-family: {!! $pdf ? '"DejaVu Sans", sans-serif' : '"Inter", "Segoe UI", Roboto, Arial, Helvetica, sans-serif' !!}; color: #1f2937; font-size: {{ $pdf ? '9.5pt' : '12.5px' }}; line-height: 1.45; -webkit-print-color-adjust: exact; print-color-adjust: exact; }
        .toolbar { position: sticky; top: 0; z-index: 2; padding: 10px 18px; background: #111827; color: #e5e7eb; font-size: 13px; overflow: hidden; }
        .toolbar span { line-height: 34px; }
        .toolbar button { float: right; border: 0; border-radius: 8px; padding: 8px 14px; background: {{ $a }}; color: #ffffff; font: inherit; font-weight: bold; cursor: pointer; }
        .sheet { {{ $pdf ? '' : 'width: 210mm; max-width: 100%; box-sizing: border-box; padding: 12mm 13mm;' }} margin: {{ $plain ? '0 auto' : '24px auto' }}; background: #ffffff; {{ $plain ? '' : 'box-shadow: 0 1px 3px rgba(15,23,42,.08), 0 12px 32px rgba(15,23,42,.08);' }} }
        h1, h2, h3, p { margin: 0; }
        table { width: 100%; border-collapse: collapse; }
        td, th { vertical-align: top; }
        .num { text-align: right; white-space: nowrap; }
        .muted { color: #6b7280; }
        .ink { color: #111827; }
        .accent { color: {{ $a }}; }
        .logo { max-height: 44px; max-width: 150px; }
        .foot { margin-top: 14px; padding-top: 8px; border-top: 1px solid #e5e7eb; color: #6b7280; font-size: 0.85em; text-align: center; }
        .revised { padding: 1px 6px; background: #fef3c7; color: #92400e; font-size: 0.8em; font-weight: bold; }
        {{-- The browser's page; the PDF engine sets its own (an @page rule there spreads the payslip over many pages). --}}
        {!! $pdf ? '' : '@page { size: A4; margin: 10mm; }' !!}
        @media print { body { background: #ffffff; } .toolbar { display: none; } .sheet { margin: 0; width: auto; padding: 0; box-shadow: none; } }
        {{ $style ?? '' }}
    </style>
</head>
<body>
@unless ($plain)
    <div class="toolbar"><button type="button" onclick="window.print()">Print or save as PDF</button><span>{{ $doc['title'] }} · {{ $doc['employee']['name'] }} · {{ $doc['period']['month'] }}</span></div>
@endunless
<div class="sheet" data-payslip-design="{{ $design }}">
    {{ $slot }}
    @if (filled($doc['footer']))
        <p class="foot">{{ $doc['footer'] }}</p>
    @endif
</div>
</body>
</html>
