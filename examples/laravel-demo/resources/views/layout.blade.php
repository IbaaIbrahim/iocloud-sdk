<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>@yield('title', 'Acme Portal — IOCloud federation demo')</title>
    <style>
        :root { color-scheme: light dark; --line: color-mix(in srgb, currentColor 15%, transparent); }
        * { box-sizing: border-box; }
        body {
            margin: 0 auto; padding: 2rem 1.25rem; max-width: 52rem; line-height: 1.55;
            font-family: ui-sans-serif, system-ui, -apple-system, "Segoe UI", sans-serif;
        }
        h1 { font-size: 1.5rem; margin: 0 0 .25rem; }
        h2 { font-size: 1.05rem; margin: 2rem 0 .75rem; }
        p.lede { margin: 0 0 2rem; opacity: .75; }
        table { width: 100%; border-collapse: collapse; }
        th, td { text-align: left; padding: .5rem .6rem; border-bottom: 1px solid var(--line); vertical-align: top; }
        th { font-weight: 600; font-size: .8rem; text-transform: uppercase; letter-spacing: .04em; opacity: .7; }
        code, pre { font-family: ui-monospace, "SF Mono", Menlo, Consolas, monospace; font-size: .85rem; }
        pre {
            padding: .85rem; border: 1px solid var(--line); border-radius: .5rem;
            overflow-x: auto; max-width: 100%;
        }
        .wrap { overflow-wrap: anywhere; }
        button {
            font: inherit; padding: .35rem .8rem; border-radius: .4rem;
            border: 1px solid var(--line); background: transparent; cursor: pointer;
        }
        button:hover { border-color: currentColor; }
        .note, .bad {
            padding: .75rem .9rem; border-radius: .5rem; border: 1px solid var(--line); margin: 1rem 0;
        }
        .bad { border-color: #d9534f; }
        .muted { opacity: .7; font-size: .9rem; }
        a { color: inherit; }
    </style>
</head>
<body>
@yield('content')
<p class="muted" style="margin-top:3rem">
    <a href="{{ route('portal') }}">← Acme Portal</a>
    · IOCloud Laravel SDK federation demo
</p>
</body>
</html>
