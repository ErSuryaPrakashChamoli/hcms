<!doctype html>
<html>
<head>
    <meta charset="utf-8">
    <title>{{ $expense['report_number'] }}</title>
    <style>
        body { font-family: Georgia, serif; max-width: 760px; margin: 40px auto; line-height: 1.5; color: #111 }
        header { border-bottom: 2px solid #111; margin-bottom: 24px; padding-bottom: 8px }
        dl { display: grid; grid-template-columns: 180px 1fr; gap: 4px 16px }
        dt { color: #555 }
        td, th { vertical-align: top; font-size: 13px }
        .sign { display: flex; justify-content: space-between; margin-top: 64px }
        .sign div { border-top: 1px solid #111; padding-top: 4px; width: 40% }
        @media print { body { margin: 0 auto } }
    </style>
</head>
<body>
<header><strong>{{ $company }}</strong><div style="float:right">{{ $expense['report_number'] }}</div></header>
<h2>Expense report cover letter</h2>
<dl>
    <dt>Employee</dt><dd>{{ $employee['name'] ?? '' }} ({{ $employee['code'] ?? '' }})</dd>
    <dt>Designation</dt><dd>{{ $employee['designation'] ?? '—' }}</dd>
    <dt>Department</dt><dd>{{ $employee['department'] ?? '—' }}</dd>
    <dt>Report</dt><dd>{{ $expense['report_number'] }} · {{ $expense['title'] }}</dd>
    <dt>Period</dt><dd>{{ $expense['period'] ?? '—' }}</dd>
    <dt>Status</dt><dd>{{ $expense['status'] }}</dd>
    <dt>Submitted on</dt><dd>{{ $expense['submitted_on'] ?? '—' }}</dd>
</dl>
<h3>Expenses</h3>
{!! $table !!}
<dl style="margin-top:16px">
    <dt>Total claimed</dt><dd>{{ $expense['currency'] }} {{ $expense['total_claimed'] }}</dd>
    <dt>Total approved</dt><dd>{{ $expense['currency'] }} {{ $expense['total_approved'] }}</dd>
    <dt>Advance adjusted</dt><dd>{{ $expense['currency'] }} {{ $expense['advance_adjusted'] }}</dd>
    <dt>Net payable</dt><dd>{{ $expense['currency'] }} {{ $expense['net_payable'] }}</dd>
</dl>
<p>I declare that the expenses above were incurred on official work and that the attached receipts are genuine.</p>
<div class="sign"><div>Employee signature</div><div>Approver signature{{ $expense['approver'] ? ' ('.$expense['approver'].')' : '' }}</div></div>
</body>
</html>
