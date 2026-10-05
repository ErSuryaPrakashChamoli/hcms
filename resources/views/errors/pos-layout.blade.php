{{-- PeopleOS error state: what happened, what it means, what to do. Self-contained (no build assets needed). --}}
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $title }} · PeopleOS</title>
    <style>
        :root { --bg: #f6f6fa; --card: #fff; --text: #15172e; --muted: #5d617e; --primary: #574bc4; --on-primary: #fff; --soft: #f0eefc; --border: #e7e7f0; }
        @media (prefers-color-scheme: dark) { :root { --bg: #0c0e1d; --card: #13152b; --text: #eceaf8; --muted: #9093b4; --primary: #a49cf2; --on-primary: #0c0e1d; --soft: rgb(164 156 242 / .16); --border: #262a4d; } }
        * { box-sizing: border-box; }
        body { margin: 0; min-height: 100vh; display: grid; place-items: center; padding: 24px; background: var(--bg); color: var(--text); font: 15px/1.6 ui-sans-serif, system-ui, -apple-system, "Segoe UI", sans-serif; }
        main { width: min(560px, 100%); padding: 32px; border-radius: 20px; background: var(--card); box-shadow: 0 0 0 1px var(--border), 0 24px 48px -24px rgb(20 20 60 / .25); }
        .code { display: inline-block; padding: 2px 10px; border-radius: 999px; background: var(--soft); color: var(--primary); font-weight: 600; font-size: 13px; }
        h1 { margin: 14px 0 6px; font: 400 32px/1.15 Georgia, "Times New Roman", serif; letter-spacing: -.01em; }
        dl { margin: 18px 0 0; display: grid; gap: 12px; }
        dt { font-size: 11.5px; letter-spacing: .08em; text-transform: uppercase; font-weight: 600; color: var(--muted); }
        dd { margin: 2px 0 0; }
        .actions { display: flex; flex-wrap: wrap; gap: 10px; margin-top: 24px; }
        a { display: inline-flex; align-items: center; height: 38px; padding: 0 16px; border-radius: 10px; font-weight: 600; text-decoration: none; }
        /* UX.18 (1.4.3): the dark primary is a light violet, so its label is dark (white on it was 2.4:1) */
        .primary { background: var(--primary); color: var(--on-primary); }
        .ghost { color: var(--text); box-shadow: inset 0 0 0 1px var(--border); }
        a:focus-visible { outline: 2px solid var(--primary); outline-offset: 2px; }
    </style>
</head>
<body>
    <main role="main" aria-labelledby="t">
        <span class="code">{{ $code }}</span>
        <h1 id="t">{{ $title }}</h1>
        <dl>
            <div><dt>What happened</dt><dd>{{ $happened }}</dd></div>
            <div><dt>What it means</dt><dd>{{ $means }}</dd></div>
            <div><dt>What to do</dt><dd>{{ $todo }}</dd></div>
        </dl>
        <div class="actions">
            <a class="primary" href="{{ url('/admin') }}">Go to Home</a>
            <a class="ghost" href="javascript:history.back()">Go back</a>
        </div>
    </main>
</body>
</html>
