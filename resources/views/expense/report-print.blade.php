@php($money = fn ($v) => $v === null ? '—' : number_format((float) $v, 2))
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="robots" content="noindex">
    <title>{{ $report->number }} · {{ $report->title }}</title>
    <style>
        body { font-family: Arial, Helvetica, sans-serif; margin: 0; padding: 32px; color: #111827; font-size: 13px; }
        h1 { font-size: 20px; margin: 0 0 2px; }
        .sub { color: #4b5563; margin-bottom: 20px; }
        table { width: 100%; border-collapse: collapse; margin-bottom: 16px; }
        th, td { border: 1px solid #d1d5db; padding: 5px 8px; text-align: left; vertical-align: top; }
        th { background: #f3f4f6; }
        .facts th { width: 30%; }
        .num { text-align: right; white-space: nowrap; }
        h2 { font-size: 14px; margin: 18px 0 6px; }
        @media print { body { padding: 0; } .noprint { display: none; } }
    </style>
</head>
<body>
<p class="noprint" style="text-align:right"><button onclick="window.print()">Print or save as PDF</button></p>
<h1>{{ $report->number }} · {{ $report->title }}</h1>
<div class="sub">{{ $report->employee?->person?->full_name }} ({{ $report->employee?->employee_code }}) · {{ \App\Domain\Expense\Models\ExpenseReport::STATUSES[$report->status] ?? $report->status }} · printed {{ now()->format('j M Y, H:i') }}</div>

<h2>Report</h2>
<table class="facts">
    <tr><th>Period</th><td>{{ $report->period_from ? $report->period_from->format('j M Y').' – '.$report->period_to?->format('j M Y') : '—' }}</td></tr>
    <tr><th>Created on</th><td>{{ $report->created_at?->format('j M Y') ?? '—' }}</td></tr>
    <tr><th>Submitted on</th><td>{{ $report->submitted_at?->format('j M Y') ?? '—' }}</td></tr>
    <tr><th>Decided on</th><td>{{ $report->decided_at?->format('j M Y') ?? '—' }}</td></tr>
    <tr><th>Trips</th><td>{{ $trips->isEmpty() ? '—' : $trips->map(fn ($t) => $t->number.' ('.$t->start_date?->format('j M').'–'.$t->end_date?->format('j M Y').')')->implode(', ') }}</td></tr>
    @if ($summary['mileage_lines'] > 0)
        <tr><th>Distance requested / approved (km)</th><td>{{ $money($summary['distance_requested']) }} / {{ $money($summary['distance_approved']) }}</td></tr>
    @endif
</table>

<h2>Expenses ({{ $summary['receipts'] }})</h2>
<table>
    <thead><tr><th>Receipt</th><th>Date</th><th>Category</th><th>Type</th><th class="num">Distance (km)</th><th class="num">Claimable ({{ $report->currency }})</th><th class="num">Approved ({{ $report->currency }})</th><th>Status</th><th>Approver note</th></tr></thead>
    <tbody>
    @foreach ($lines as $line)
        <tr>
            <td>{{ $line->number }}</td>
            <td>{{ $line->expense_date?->format('j M Y') }}</td>
            <td>{{ $line->category?->name }}</td>
            <td>{{ config('peopleos.expense.types.'.$line->type, $line->type) }}</td>
            <td class="num">{{ $line->type === 'mileage' ? $money($line->distance_km) : '—' }}</td>
            <td class="num">{{ $money($line->claimable_amount) }}</td>
            <td class="num">{{ $money($line->approved_amount) }}</td>
            <td>{{ \App\Domain\Expense\Models\ExpenseReceipt::STATUSES[$line->status] ?? $line->status }}</td>
            <td>{{ $line->approver_note ?? '—' }}</td>
        </tr>
    @endforeach
    </tbody>
</table>

<h2>Approvals</h2>
<table>
    <thead><tr><th>Approval for</th><th>Status</th><th class="num">Claimed</th><th class="num">Approved</th><th>Decided by</th><th>Decided on</th><th>Note</th></tr></thead>
    <tbody>
    @forelse ($parts as $part)
        <tr>
            <td>{{ $part->label }}</td>
            <td>{{ \App\Domain\Expense\Models\ExpenseReportPart::STATUSES[$part->status] ?? $part->status }}</td>
            <td class="num">{{ $money($part->total_claimed) }}</td>
            <td class="num">{{ $part->status === 'pending' ? '—' : $money($part->total_approved) }}</td>
            <td>{{ $part->decider?->name ?? '—' }}</td>
            <td>{{ $part->decided_at?->format('j M Y') ?? '—' }}</td>
            <td>{{ $part->decision_note ?? '—' }}</td>
        </tr>
    @empty
        <tr><td colspan="7">Not submitted for approval yet.</td></tr>
    @endforelse
    </tbody>
</table>

<h2>Amounts ({{ $report->currency }})</h2>
<table class="facts">
    <tr><th>Claimed</th><td class="num">{{ $money($report->total_claimed) }}</td></tr>
    <tr><th>Approved</th><td class="num">{{ $money($report->total_approved) }}</td></tr>
    <tr><th>Approved, repaid to the employee</th><td class="num">{{ $money($summary['approved_reimbursable']) }}</td></tr>
    <tr><th>Approved, paid by the company</th><td class="num">{{ $money($summary['approved_non_reimbursable']) }}</td></tr>
    <tr><th>Rejected</th><td class="num">{{ $money($summary['rejected']) }}</td></tr>
    @if ($summary['held_count'] > 0)
        <tr><th>Held as suspected ({{ $summary['held_count'] }})</th><td class="num">{{ $money($summary['held']) }}</td></tr>
    @endif
    <tr><th>Advances settled</th><td class="num">{{ $money($report->advance_adjusted) }}</td></tr>
    <tr><th>Net payable</th><td class="num">{{ $money($report->net_payable) }}</td></tr>
</table>

@if ($showsPayment)
    <h2>Payment</h2>
    <table class="facts">
        <tr><th>Payment</th><td>{{ $report->paymentStatus() }}</td></tr>
        @if ($report->paid_on !== null)
            <tr><th>Paid on</th><td>{{ $report->paid_on->format('j M Y') }}</td></tr>
            <tr><th>Reference</th><td>{{ $report->payment_reference ?? '—' }}</td></tr>
            @if ($report->payment_mode !== null)
                {{-- HROne parity XO-4 (§26.4.3 #22): how an outside payment was made. --}}
                <tr><th>Payment mode</th><td>{{ $report->paymentModeLabel() }}</td></tr>
            @endif
            <tr><th>Paid receipts</th><td>{{ $summary['paid_receipts'] }}</td></tr>
        @else
            <tr><th>Scheduled for payroll on</th><td>{{ $report->payment_scheduled_at?->format('j M Y') ?? '—' }}</td></tr>
            @if ($report->payment_scheduled_for !== null)
                <tr><th>Pay month</th><td>{{ $report->payment_scheduled_for->format('M Y') }}</td></tr>
            @endif
        @endif
    </table>
@endif
</body>
</html>
