<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0" />
    <title>Now Playing</title>

    <style>
        :root {
            --bg: #0f0f10;
            --surface: #161618;
            --text-main: #f2f2f2;
            --text-muted: #9a9a9f;
            --accent: #cfcfcf;
        }

        * {
            box-sizing: border-box;
            margin: 0;
            padding: 0;
        }

        body {
            height: 100vh;
            background-image: url('https://images.unsplash.com/photo-1765873360413-6c79486d1fda?q=80&w=2675&auto=format&fit=crop&ixlib=rb-4.1.0&ixid=M3wxMjA3fDB8MHxwaG90by1wYWdlfHx8fGVufDB8fHx8fA%3D%3D');
            font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Helvetica, Arial, sans-serif;
            color: var(--text-main);
            display: flex;
            align-items: center;
            justify-content: center;
            background-size: cover;
        }

        .player {
            width: 320px;
            background: rgba(51, 51, 51, 0.59);
            border-radius: 24px;
            padding: 28px;
            #box-shadow: 0 30px 80px rgba(0,0,0,0.6);
            text-align: center;

            box-shadow: 0px 6px 20px rgba(0, 0, 0, 0.2);
        }

        .album-art {
            width: 100%;
            aspect-ratio: 1 / 1;
            border-radius: 18px;
            background: linear-gradient(135deg, #3a3a3f, #1f1f22);
            margin-bottom: 24px;
        }

        .track {
            font-size: 1.1rem;
            font-weight: 500;
            letter-spacing: 0.02em;
            margin-bottom: 6px;
        }

        .artist {
            font-size: 0.85rem;
            color: var(--text-muted);
            margin-bottom: 28px;
        }

        .progress {
            width: 100%;
            height: 2px;
            background: #2a2a2d;
            border-radius: 2px;
            margin-bottom: 10px;
            position: relative;
        }

        .progress::after {
            content: "";
            position: absolute;
            height: 100%;
            width: 45%;
            background: var(--accent);
            border-radius: 2px;
        }

        .time {
            display: flex;
            justify-content: space-between;
            font-size: 0.7rem;
            color: var(--text-muted);
            margin-bottom: 28px;
        }

        .controls {
            display: flex;
            align-items: center;
            justify-content: space-between;
        }

        .btn {
            width: 42px;
            height: 42px;
            border-radius: 50%;
            border: 1px solid #2a2a2d;
            display: flex;
            align-items: center;
            justify-content: center;
            color: var(--text-main);
            font-size: 0.9rem;
            transition: all 0.25s ease;
        }

        .btn.play {
            width: 56px;
            height: 56px;
            font-size: 1.2rem;
            border-color: var(--accent);
        }

        .btn:hover {
            background: rgba(255,255,255,0.05);
            transform: scale(1.05);
        }
    </style>
</head>

<body>

<div class="player">
    <div class="album-art"></div>

    <div class="track">Midnight Signal</div>
    <div class="artist">Aurora Static</div>

    <div class="progress"></div>
    <div class="time">
        <span>1:42</span>
        <span>4:05</span>
    </div>

    <div class="controls">
        <div class="btn">⏮</div>
        <div class="btn play">⏯</div>
        <div class="btn">⏭</div>
    </div>
</div>

</body>
</html>
