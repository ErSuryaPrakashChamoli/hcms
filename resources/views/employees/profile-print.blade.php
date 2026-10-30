@php($date = fn ($d) => $d ? \Illuminate\Support\Carbon::parse($d)->format('j M Y') : '—')
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="robots" content="noindex">
    <title>{{ $person?->full_name }} · {{ $employee->employee_code }}</title>
    <style>
        body { font-family: Arial, Helvetica, sans-serif; margin: 0; padding: 32px; color: #111827; font-size: 13px; }
        h1 { font-size: 20px; margin: 0 0 2px; }
        .sub { color: #4b5563; margin-bottom: 20px; }
        table { width: 100%; border-collapse: collapse; margin-bottom: 16px; }
        th, td { border: 1px solid #d1d5db; padding: 5px 8px; text-align: left; vertical-align: top; }
        th { background: #f3f4f6; width: 30%; }
        thead th { width: auto; }
        h2 { font-size: 14px; margin: 18px 0 6px; }
        .muted { color: #6b7280; }
        @media print { body { padding: 0; } .noprint { display: none; } }
    </style>
</head>
<body>
<p class="noprint" style="text-align:right"><button onclick="window.print()">Print or save as PDF</button></p>
<h1>{{ $person?->full_name }}</h1>
<div class="sub">{{ $employee->employee_code }} · {{ $position?->designation?->name ?? '—' }} · {{ $position?->department?->name ?? '—' }} · printed {{ now()->format('j M Y, H:i') }}</div>

<h2>Employment</h2>
<table>
    <tr><th>Status</th><td>{{ $employee->lifecycle_state->getLabel() }}</td></tr>
    <tr><th>Company</th><td>{{ $position?->company?->name ?? '—' }}</td></tr>
    <tr><th>Business unit</th><td>{{ $position?->businessUnit?->name ?? '—' }}</td></tr>
    <tr><th>Location</th><td>{{ $position?->location?->name ?? '—' }}</td></tr>
    <tr><th>Grade</th><td>{{ $position?->grade?->name ?? '—' }}</td></tr>
    <tr><th>Employment type</th><td>{{ $position?->employmentType?->name ?? '—' }}</td></tr>
    <tr><th>Reporting manager</th><td>{{ $employee->currentManager?->manager?->person?->full_name ?? 'Not available' }}</td></tr>
    <tr><th>Date of joining</th><td>{{ $date($employee->joining_date) }}</td></tr>
    <tr><th>Probation ends</th><td>{{ $date($employee->probation_end_date) }}</td></tr>
    <tr><th>Confirmation date</th><td>{{ $date($employee->confirmation_date) }}</td></tr>
    <tr><th>Work e-mail</th><td>{{ $employee->work_email ?? '—' }}</td></tr>
    <tr><th>Work phone</th><td>{{ $employee->work_phone ?? '—' }}</td></tr>
</table>

<h2>Personal</h2>
<table>
    <tr><th>Preferred name</th><td>{{ $person?->preferred_name ?? '—' }}</td></tr>
    <tr><th>Gender</th><td>{{ $person?->gender ?? '—' }}</td></tr>
    <tr><th>Marital status</th><td>{{ $person?->marital_status ?? '—' }}</td></tr>
    <tr><th>Nationality</th><td>{{ $person?->nationality ?? '—' }}</td></tr>
    @if ($withSensitive)
        <tr><th>Date of birth</th><td>{{ $date($person?->date_of_birth) }}</td></tr>
        <tr><th>Blood group</th><td>{{ $person?->blood_group ?? '—' }}</td></tr>
        <tr><th>Personal e-mail</th><td>{{ $person?->personal_email ?? '—' }}</td></tr>
        <tr><th>Personal phone</th><td>{{ $person?->personal_phone ?? '—' }}</td></tr>
    @endif
</table>

<h2>Positions</h2>
<table>
    <thead><tr><th>From</th><th>Until</th><th>Designation</th><th>Department</th><th>Location</th><th>Change</th></tr></thead>
    @forelse ($positions as $p)
        <tr><td>{{ $date($p->effective_from) }}</td><td>{{ $date($p->getAttributes()['effective_to'] ?? null) }}</td><td>{{ $p->designation?->name ?? '—' }}</td><td>{{ $p->department?->name ?? '—' }}</td><td>{{ $p->location?->name ?? '—' }}</td><td>{{ $p->change_type }}</td></tr>
    @empty
        <tr><td colspan="6" class="muted">No positions recorded.</td></tr>
    @endforelse
</table>

<h2>Reporting lines</h2>
<table>
    <thead><tr><th>Relationship</th><th>Manager</th><th>From</th><th>Until</th></tr></thead>
    @forelse ($reporting as $r)
        <tr><td>{{ config('peopleos.people.reporting_types.'.$r->type, $r->type) }}</td><td>{{ $r->manager?->person?->full_name ?? '—' }}</td><td>{{ $date($r->effective_from) }}</td><td>{{ $date($r->getAttributes()['effective_to'] ?? null) }}</td></tr>
    @empty
        <tr><td colspan="4" class="muted">No reporting lines recorded.</td></tr>
    @endforelse
</table>

@if ($shows['personal'])
<h2>Addresses</h2>
<table>
    <thead><tr><th>Type</th><th>Address</th></tr></thead>
    @forelse ($person?->addresses ?? [] as $a)
        <tr><td>{{ $a->type }}</td><td>{{ collect([$a->address_line_1, $a->address_line_2, $a->city, $a->state_code, $a->postal_code, $a->country_code])->filter()->implode(', ') }}</td></tr>
    @empty
        <tr><td colspan="2" class="muted">None recorded.</td></tr>
    @endforelse
</table>
@endif

@php($family = $shows['family'] ? ($person?->familyMembers ?? collect()) : collect())
@php($contacts = $shows['personal'] ? ($person?->emergencyContacts ?? collect()) : collect())
@if ($shows['family'] || $shows['personal'])
<h2>{{ $shows['family'] && $shows['personal'] ? 'Family and emergency contacts' : ($shows['family'] ? 'Family' : 'Emergency contacts') }}</h2>
<table>
    <thead><tr><th>Name</th><th>Relation</th><th>Notes</th></tr></thead>
    @foreach ($family as $f)
        <tr><td>{{ $f->name }}</td><td>{{ $f->relation }}</td><td>{{ collect([$f->is_dependent ? 'Dependant' : null, $f->is_nominee ? 'Nominee' : null])->filter()->implode(', ') ?: '—' }}</td></tr>
    @endforeach
    @foreach ($contacts as $c)
        <tr><td>{{ $c->name }}</td><td>{{ $c->relation }}</td><td>Emergency contact · {{ $c->phone }}</td></tr>
    @endforeach
    @if ($family->isEmpty() && $contacts->isEmpty())
        <tr><td colspan="3" class="muted">None recorded.</td></tr>
    @endif
</table>
@endif

@if ($shows['professional'])
<h2>Qualifications and experience</h2>
<table>
    <thead><tr><th>Qualification / employer</th><th>Institution / designation</th><th>Year / period</th></tr></thead>
    @foreach ($person?->qualifications ?? [] as $q)
        <tr><td>{{ $q->qualification }}{{ $q->specialisation ? ' · '.$q->specialisation : '' }}</td><td>{{ $q->institution ?? '—' }}</td><td>{{ $q->year_of_completion ?? '—' }}</td></tr>
    @endforeach
    @foreach ($person?->experiences ?? [] as $x)
        <tr><td>{{ $x->employer }}</td><td>{{ $x->designation ?? '—' }}</td><td>{{ $date($x->from_date) }} – {{ $date($x->to_date) }}</td></tr>
    @endforeach
    @if (($person?->qualifications ?? collect())->isEmpty() && ($person?->experiences ?? collect())->isEmpty())
        <tr><td colspan="3" class="muted">None recorded.</td></tr>
    @endif
</table>
@endif

@if ($withSensitive)
    <h2>{{ $shows['bank'] ? 'Statutory and bank (masked)' : 'Statutory (masked)' }}</h2>
    <table>
        <tr><th>PAN</th><td>{{ $mask($statutory?->pan) }}</td></tr>
        <tr><th>UAN</th><td>{{ $mask($statutory?->uan) }}</td></tr>
        <tr><th>ESIC number</th><td>{{ $mask($statutory?->esic_number) }}</td></tr>
        @if ($shows['bank'])
            @forelse ($bank as $b)
                <tr><th>Bank account{{ $b->is_primary ? ' (primary)' : '' }}</th><td>{{ $b->bank_name ?? '—' }} · {{ $mask($b->account_number) }}</td></tr>
            @empty
                <tr><th>Bank account</th><td class="muted">None recorded.</td></tr>
            @endforelse
        @endif
    </table>
@else
    <p class="muted">Date of birth, personal contacts, statutory and bank details are left out: they need access to sensitive personal data.</p>
@endif
</body>
</html>
