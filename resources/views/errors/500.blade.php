<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Error 500</title>
    <style>
        * { box-sizing: border-box; }
        body {
            min-height: 100vh;
            margin: 0;
            display: grid;
            place-items: center;
            padding: 24px;
            background: #f8fafc;
            color: #0f172a;
            font-family: Arial, Helvetica, sans-serif;
        }
        main {
            width: min(100%, 520px);
            padding: 48px 32px;
            border: 1px solid #e2e8f0;
            border-radius: 24px;
            background: #fff;
            box-shadow: 0 20px 40px rgb(15 23 42 / 8%);
            text-align: center;
        }
        .icon {
            width: 64px;
            height: 64px;
            margin: 0 auto 24px;
            display: grid;
            place-items: center;
            border-radius: 20px;
            background: #fff1f2;
            color: #e11d48;
            font-size: 32px;
            font-weight: 700;
        }
        h1 { margin: 0; font-size: clamp(24px, 5vw, 32px); }
        p { margin: 16px 0 0; color: #64748b; line-height: 1.7; }
        a, button {
            min-height: 44px;
            margin: 28px 4px 0;
            padding: 12px 18px;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            border: 0;
            border-radius: 8px;
            background: #e11d48;
            color: #fff;
            font: inherit;
            font-weight: 600;
            text-decoration: none;
            cursor: pointer;
        }
        a:hover, button:hover { background: #be123c; }
        .help { margin-top: 24px; font-size: 14px; }
        @media (max-width: 420px) {
            main { padding: 36px 22px; }
            a, button { width: 100%; margin: 12px 0 0; }
        }
    </style>
</head>
<body>
    <main>
        <div class="icon" aria-hidden="true">!</div>
        <h1>No pudimos completar tu solicitud</h1>
        <p>
            Estamos presentando una dificultad temporal. Inténtalo nuevamente en unos minutos.
            Si el problema continúa, comunícate con el área de Sistemas.
        </p>
        <button type="button" onclick="window.location.reload()">Intentar de nuevo</button>
        <a href="/">Ir al inicio</a>
        <p class="help">Lamentamos el inconveniente.</p>
    </main>
</body>
</html>
