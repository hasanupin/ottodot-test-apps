<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ config('app.name') }}</title>
    <style>body { font-family: system-ui, sans-serif; max-width: 40rem; margin: 2rem auto; padding: 0 1rem; }</style>
</head>
<body>
    <h1>{{ config('app.name') }}</h1>
    <p>Blade page is served. Response from <code>GET /api/ping</code>:</p>
    <pre id="ping">Loading…</pre>
    <script>
        fetch('/api/ping')
            .then(r => r.json())
            .then(data => { document.getElementById('ping').textContent = JSON.stringify(data, null, 2); })
            .catch(err => { document.getElementById('ping').textContent = 'Error: ' + err; });
    </script>
</body>
</html>
