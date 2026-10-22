<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Salary formula guide</title>
    {{-- Standalone: the in-app guide's Tailwind classes do nothing here, so its elements are styled directly. --}}
    <style>
        body { font-family: system-ui, -apple-system, "Segoe UI", Roboto, sans-serif; font-size: 14px; line-height: 1.55; color: #1f2937; background: #fff; max-width: 980px; margin: 32px auto; padding: 0 16px; }
        header { border-bottom: 2px solid #111827; margin-bottom: 24px; padding-bottom: 8px; }
        h1 { font-size: 22px; margin: 0; color: #111827; }
        header p { margin: 4px 0 0; color: #6b7280; font-size: 12px; }
        section { margin: 0 0 28px; }
        h3 { font-size: 16px; color: #111827; margin: 0 0 8px; }
        h4 { font-size: 14px; color: #111827; margin: 16px 0 6px; }
        ol, ul { padding-left: 22px; }
        li { margin: 4px 0; }
        code { font-family: ui-monospace, SFMono-Regular, Menlo, Consolas, monospace; font-size: 12.5px; font-variant-ligatures: none; background: #f3f4f6; border-radius: 4px; padding: 1px 5px; color: #111827; }
        table { width: 100%; border-collapse: collapse; margin: 6px 0; }
        th { text-align: left; font-size: 11px; text-transform: uppercase; letter-spacing: .04em; color: #6b7280; border-bottom: 1px solid #d1d5db; padding: 6px 8px; }
        td { border-bottom: 1px solid #e5e7eb; padding: 6px 8px; vertical-align: top; }
        .text-right { text-align: right; }
        .tabular-nums { font-variant-numeric: tabular-nums; }
        .whitespace-nowrap { white-space: nowrap; }
        .font-semibold { font-weight: 600; }
        .bg-gray-50 { background: #f9fafb; }
        .text-gray-500, .pos-caption { color: #6b7280; }
        .pos-caption { font-size: 12px; }
        @media print { body { margin: 0; max-width: none; } section { break-inside: auto; } tr { break-inside: avoid; } }
    </style>
</head>
<body>
    <header>
        <h1>Salary formula guide</h1>
        <p>{{ $tenant ? $tenant.' · ' : '' }}PeopleOS · downloaded {{ now()->format('j M Y') }}</p>
    </header>
    @include('filament.payroll.formula-guide')
</body>
</html>
