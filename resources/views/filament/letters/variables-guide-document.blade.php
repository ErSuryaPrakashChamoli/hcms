@php($tag = \App\Domain\Letters\Support\LetterVariables::tag(...))
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Letter variables</title>
    <style>
        body { font-family: system-ui, -apple-system, "Segoe UI", Roboto, sans-serif; font-size: 14px; line-height: 1.55; color: #1f2937; background: #fff; max-width: 980px; margin: 32px auto; padding: 0 16px; }
        header { border-bottom: 2px solid #111827; margin-bottom: 24px; padding-bottom: 8px; }
        h1 { font-size: 22px; margin: 0; color: #111827; }
        header p { margin: 4px 0 0; color: #6b7280; font-size: 12px; }
        section { margin: 0 0 28px; }
        h3 { font-size: 16px; color: #111827; margin: 0 0 4px; }
        section > p { margin: 0 0 8px; color: #6b7280; }
        ol { padding-left: 22px; }
        li { margin: 4px 0; }
        code { font-family: ui-monospace, SFMono-Regular, Menlo, Consolas, monospace; font-size: 12.5px; font-variant-ligatures: none; background: #f3f4f6; border-radius: 4px; padding: 1px 5px; color: #111827; white-space: nowrap; }
        table { width: 100%; border-collapse: collapse; margin: 6px 0; }
        th { text-align: left; font-size: 11px; text-transform: uppercase; letter-spacing: .04em; color: #6b7280; border-bottom: 1px solid #d1d5db; padding: 6px 8px; }
        td { border-bottom: 1px solid #e5e7eb; padding: 6px 8px; vertical-align: top; }
        td.example { color: #6b7280; }
        @media print { body { margin: 0; max-width: none; } tr { break-inside: avoid; } }
    </style>
</head>
<body>
<header>
    <h1>Letter variables</h1>
    <p>{{ $tenant ? $tenant.' · ' : '' }}PeopleOS · {{ now()->format('j M Y') }}</p>
</header>
<section>
    <h3>How to use variables</h3>
    <ol>
        <li><strong>Written in PeopleOS:</strong> in the letter editor, click the { } button and pick a variable, or type one, such as <code>{{ $tag('employee.name') }}</code>.</li>
        <li><strong>Your own Word letter:</strong> type the variables in Word where the values should go, save as .docx and upload it on the template (choose “Upload a Word file”). Your letterhead, logo, fonts and layout are kept.</li>
        <li><strong>Salary annexure:</strong> <code>{{ $tag('salary.annexure') }}</code> on a line of its own prints the whole table, monthly and yearly. For your own table, use one variable per amount, such as <code>{{ $tag('salary.basic.monthly') }}</code> and <code>{{ $tag('salary.basic.annual') }}</code>.</li>
        <li><strong>Signature:</strong> <code>{{ $tag('signatory.signature') }}</code> prints the signing authority’s signature, with <code>{{ $tag('signatory.name') }}</code> and <code>{{ $tag('signatory.designation') }}</code> below it.</li>
        <li>A variable with no value for an employee prints nothing. Any other name, such as <code>{{ $tag('purpose') }}</code>, is filled in by whoever generates the letter.</li>
    </ol>
</section>
@foreach ($groups as $group)
    <section>
        <h3>{{ $group['title'] }}</h3>
        <p>{{ $group['intro'] }}</p>
        <table>
            <thead><tr><th>Variable</th><th>What it prints</th><th>Example</th></tr></thead>
            <tbody>
                @foreach ($group['variables'] as $variable => [$meaning, $example])
                    <tr><td><code>{{ $tag($variable) }}</code></td><td>{{ $meaning }}</td><td class="example">{{ $example }}</td></tr>
                @endforeach
            </tbody>
        </table>
    </section>
@endforeach
</body>
</html>
