<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $title ?? 'Error' }} – MOVA</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;600;700;800&display=swap" rel="stylesheet">
    <style>
        * { box-sizing: border-box; }
        body {
            margin: 0;
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            background: #f8fafc;
            font-family: 'Inter', system-ui, sans-serif;
            color: #0f172a;
            padding: 1.5rem;
        }
        .card {
            max-width: 28rem;
            width: 100%;
            text-align: center;
            background: #fff;
            border: 1px solid #f1f5f9;
            border-radius: 1.5rem;
            box-shadow: 0 1px 3px rgba(15, 23, 42, 0.06);
            padding: 2.5rem 2rem;
        }
        .logo {
            width: 2.75rem;
            height: 2.75rem;
            border-radius: 0.75rem;
            background: linear-gradient(135deg, #2563eb, #1d4ed8);
            color: #fff;
            font-weight: 900;
            font-size: 1.25rem;
            display: flex;
            align-items: center;
            justify-content: center;
            margin: 0 auto 1.25rem;
        }
        .code { font-size: 0.8rem; font-weight: 700; letter-spacing: 0.08em; color: #94a3b8; text-transform: uppercase; margin-bottom: 0.5rem; }
        h1 { font-size: 1.35rem; font-weight: 800; margin: 0 0 0.75rem; }
        p { font-size: 0.9rem; color: #64748b; line-height: 1.5; margin: 0 0 1.75rem; }
        a.btn {
            display: inline-block;
            background: #2563eb;
            color: #fff;
            text-decoration: none;
            font-weight: 600;
            font-size: 0.875rem;
            padding: 0.65rem 1.5rem;
            border-radius: 0.75rem;
        }
        a.btn:hover { background: #1d4ed8; }
    </style>
</head>
<body>
    <div class="card">
        <div class="logo">M</div>
        <p class="code">Error {{ $code }}</p>
        <h1>{{ $title ?? 'Ocurrió un problema' }}</h1>
        <p>{{ $message }}</p>
        <a class="btn" href="{{ url('/') }}">Volver al inicio</a>
    </div>
</body>
</html>
