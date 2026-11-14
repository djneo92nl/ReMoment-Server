<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="color-scheme" content="light dark">
    <title>Offline · {{ config('app.name', 'ReMoment') }}</title>
    <style>
        body { margin: 0; min-height: 100vh; display: flex; align-items: center; justify-content: center; font: 16px system-ui, sans-serif; background: rgb(249 250 251); color: rgb(17 24 39); text-align: center; padding: 1rem; }
        .box { max-width: 22rem; }
        h1 { font-size: 1.25rem; margin: 0 0 .5rem; }
        p { color: rgb(107 114 128); margin: 0 0 1.25rem; font-size: .875rem; }
        button { font: inherit; font-size: .875rem; padding: .625rem 1.25rem; border: 0; border-radius: .75rem; background: rgb(17 24 39); color: #fff; cursor: pointer; }
        button:focus-visible { outline: 2px solid rgb(220 38 38); outline-offset: 2px; }
        @media (prefers-color-scheme: dark) {
            body { background: rgb(12 10 9); color: rgb(243 244 246); }
            p { color: rgb(156 163 175); }
            button { background: rgb(68 64 60); }
        }
    </style>
</head>
<body>
    <div class="box">
        <h1>The server can't be reached</h1>
        <p>ReMoment shows live data only, so nothing is kept for offline use. Check that the server is on and you are on the home network, then try again.</p>
        <button type="button" onclick="location.href = '/devices'">Try again</button>
    </div>
</body>
</html>
