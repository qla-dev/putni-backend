<!doctype html>
<html lang="bs">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex,nofollow">
    <title>Poziv u firmu · Putni nalozi</title>
    <style>
        :root { color-scheme: light dark; font-family: system-ui, -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif; }
        * { box-sizing: border-box; }
        body { min-height: 100vh; margin: 0; display: grid; place-items: center; padding: 24px; background: #f2f2f7; color: #111114; }
        main { width: min(100%, 430px); padding: 30px 24px; border-radius: 26px; background: #fff; text-align: center; box-shadow: 0 16px 50px rgba(0,0,0,.09); }
        .icon { width: 62px; height: 62px; margin: 0 auto 18px; display: grid; place-items: center; border-radius: 20px; background: #eaf3ff; color: #007aff; font-size: 28px; }
        h1 { margin: 0; font-size: 24px; }
        p { margin: 10px 0 0; color: #6c6c70; line-height: 1.5; }
        .code { margin: 22px 0; font-size: 32px; font-weight: 900; letter-spacing: .16em; color: #007aff; }
        a { display: block; padding: 14px 18px; border-radius: 14px; background: #007aff; color: #fff; font-weight: 800; text-decoration: none; }
        small { display: block; margin-top: 14px; color: #8e8e93; line-height: 1.4; }
        @media (prefers-color-scheme: dark) {
            body { background: #000; color: #f5f5f7; }
            main { background: #1c1c1e; }
            .icon { background: #17263a; }
            p, small { color: #a1a1a6; }
        }
    </style>
</head>
<body>
<main>
    <div class="icon">↗</div>
    <h1>Poziv u firmu</h1>
    <p>Otvorite aplikaciju Putni nalozi kako biste prihvatili poziv.</p>
    <div class="code">{{ $code }}</div>
    <a href="putninalozi://invite/{{ rawurlencode($code) }}">Otvori aplikaciju</a>
    <small>Ako aplikacija nije instalirana, sačuvajte pozivni kod i unesite ga nakon instalacije.</small>
</main>
</body>
</html>
