<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex">
    <title>{{ $title ?? 'Error' }} – MOVA</title>
    {{-- Sin hojas de estilo externas: la página de error debe verse bien aunque falle todo lo demás (también en 500/503). --}}
    <style>
        @font-face { font-family: 'Plus Jakarta Sans'; src: url('/fonts/PlusJakartaSans-Regular.woff2') format('woff2'); font-weight: 400; font-display: swap; }
        @font-face { font-family: 'Plus Jakarta Sans'; src: url('/fonts/PlusJakartaSans-ExtraBold.woff2') format('woff2'); font-weight: 800; font-display: swap; }
        :root { --canvas: #F8FAFC; --surface: #FFFFFF; --ink: #0F172A; --ink-muted: #475569; --line: #E2E8F0; --brand: #1F5AA6; --brand-hover: #0D409A; --code: #64748B; }
        @media (prefers-color-scheme: dark) {
            :root { --canvas: #0B1220; --surface: #121A2B; --ink: #F2F5FA; --ink-muted: #A8B3C7; --line: #2A3650; --brand: #4F8ADB; --brand-hover: #8FB5E8; --code: #A8B3C7; }
        }
        * { box-sizing: border-box; }
        body {
            margin: 0; min-height: 100vh; min-height: 100dvh; display: flex; align-items: center; justify-content: center;
            background: var(--canvas); color: var(--ink); padding: 1.5rem;
            font-family: 'Plus Jakarta Sans', system-ui, -apple-system, 'Segoe UI', Roboto, sans-serif;
        }
        .card {
            max-width: 30rem; width: 100%; text-align: center; background: var(--surface);
            border: 1px solid var(--line); border-radius: 1.5rem; padding: 2rem 1.75rem 2.25rem;
            box-shadow: 0 8px 30px rgba(9, 37, 80, 0.07);
        }
        .mascot { width: 7.5rem; height: auto; display: block; margin: 0 auto 0.75rem; }
        .code { font-size: 0.8rem; font-weight: 800; letter-spacing: 0.08em; color: var(--code); text-transform: uppercase; margin: 0 0 0.5rem; }
        h1 { font-size: 1.5rem; font-weight: 800; line-height: 1.2; margin: 0 0 0.75rem; text-wrap: balance; }
        p.msg { font-size: 1rem; color: var(--ink-muted); line-height: 1.55; margin: 0 0 1.75rem; text-wrap: pretty; }
        a.btn {
            display: inline-flex; align-items: center; justify-content: center; min-height: 2.75rem; padding: 0.65rem 1.5rem;
            background: var(--brand); color: #fff; text-decoration: none; font-weight: 800; font-size: 0.95rem; border-radius: 0.875rem;
        }
        a.btn:hover { background: var(--brand-hover); }
        a.btn:focus-visible { outline: 3px solid var(--brand); outline-offset: 3px; }
        @media (prefers-color-scheme: dark) { a.btn { color: #0B1220; } }
    </style>
</head>
<body>
    <main class="card">
        <img class="mascot" src="/images/mascot/movi-lee-192.webp" width="192" height="183" alt="" decoding="async">
        <p class="code">Error {{ $code }}</p>
        <h1>{{ $title ?? 'Ocurrió un problema' }}</h1>
        <p class="msg">{{ $message }}</p>
        <a class="btn" href="{{ url('/') }}">Volver al inicio</a>
    </main>
</body>
</html>
