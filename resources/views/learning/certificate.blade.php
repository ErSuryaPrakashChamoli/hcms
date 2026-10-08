<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>Certificate {{ $certificate->number }}</title>
    <style>
        body { font-family: Georgia, serif; margin: 0; padding: 48px; color: #1f2937; }
        .frame { border: 6px double #1f2937; padding: 48px; text-align: center; }
        h1 { font-size: 32px; letter-spacing: 2px; margin: 0 0 24px; }
        .name { font-size: 28px; margin: 16px 0; }
        .meta { margin-top: 32px; font-size: 13px; color: #4b5563; }
    </style>
</head>
<body>
<div class="frame">
    <h1>Certificate of completion</h1>
    <p>This certifies that</p>
    <p class="name">{{ $certificate->employee?->person?->full_name }}</p>
    <p>has completed</p>
    <p class="name">{{ $certificate->courseVersion?->title ?? $certificate->course?->title }}@if ($certificate->courseVersion) (version {{ $certificate->courseVersion->version }})@endif</p>
    <div class="meta">
        <div>Certificate {{ $certificate->number }} · issued {{ $certificate->issued_on?->toDateString() }}@if ($certificate->expires_on) · valid until {{ $certificate->expires_on->toDateString() }}@endif</div>
        <div>Issued by {{ $certificate->issuer }}</div>
    </div>
</div>
</body>
</html>
