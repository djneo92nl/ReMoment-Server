<!DOCTYPE html>
<html>
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>ReMoment Player</title>
<script>
  // Diagnostics that do not depend on player.js: say what is wrong instead of sitting on "Connecting…".
  window.addEventListener('error', function (e) { var el = document.getElementById('status'); if (el) el.textContent = 'Script error: ' + e.message + ' (' + String(e.filename || '').split('/').pop() + ':' + e.lineno + ')'; });
  window.addEventListener('unhandledrejection', function (e) { var el = document.getElementById('status'); if (el) el.textContent = 'Error: ' + (e.reason && e.reason.message || e.reason); });
  setTimeout(function () { if (!window.__playerStarted) { var el = document.getElementById('status'); if (el) el.textContent = 'player.js did not start (not loaded, or blocked?)'; } }, 4000);
</script>
<script defer src="{{ asset('js/player.js') }}?v={{ filemtime(public_path('js/player.js')) }}"></script>
<x-live-scripts />
<style>
  * { margin: 0; padding: 0; box-sizing: border-box; }
  :root { --accent: #ffffff; }
  body { background: #0a0a0a; color: #fff; font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, 'Helvetica Neue', Arial, sans-serif; min-height: 100vh; display: flex; align-items: center; justify-content: center; padding: 24px; position: relative; overflow-x: hidden; }
  #bg { position: fixed; inset: 0; background: center / cover no-repeat; filter: blur(80px) brightness(.2) saturate(1.4); transform: scale(1.1); transition: opacity 1.2s ease; opacity: 0; z-index: 0; }
  main { position: relative; z-index: 1; width: 100%; max-width: 420px; text-align: center; }
  #art { width: 100%; aspect-ratio: 1; border-radius: 16px; background: #1a1a1a center / cover no-repeat; margin-bottom: 22px; box-shadow: 0 30px 80px rgba(0,0,0,.55), 0 6px 20px rgba(0,0,0,.35); }
  #title { font-size: 22px; font-weight: 500; letter-spacing: -.01em; color: var(--accent); transition: color .6s; overflow-wrap: anywhere; }
  #artist { color: rgba(255,255,255,.6); margin-top: 4px; min-height: 1.3em; overflow-wrap: anywhere; }
  #bar-wrap { padding: 10px 0; margin-top: 14px; cursor: pointer; }
  #bar { height: 4px; background: rgba(255,255,255,.15); border-radius: 2px; overflow: hidden; }
  #bar i { display: block; height: 100%; width: 0; background: var(--accent); }
  #times { display: flex; justify-content: space-between; color: rgba(255,255,255,.45); font-size: 12px; font-variant-numeric: tabular-nums; margin-bottom: 8px; }
  button { border: 0; cursor: pointer; font: inherit; color: #fff; }
  #controls { display: flex; align-items: center; justify-content: center; gap: 10px; margin: 8px 0 18px; }
  .ctl { width: 44px; height: 44px; border-radius: 50%; background: rgba(255,255,255,.08); display: inline-flex; align-items: center; justify-content: center; transition: background .15s; }
  .ctl:hover { background: rgba(255,255,255,.16); }
  .ctl svg { width: 22px; height: 22px; fill: currentColor; }
  #playpause { width: 60px; height: 60px; background: var(--accent); color: #111; margin: 0 4px; }
  #playpause:hover { background: var(--accent); filter: brightness(1.1); }
  .mode { background: transparent; opacity: .45; width: 38px; height: 38px; }
  .mode.on { opacity: 1; color: var(--accent); }
  .mode[hidden] { display: none; }
  #volume { display: flex; align-items: center; gap: 12px; margin: 0; }
  #volume button { width: 28px; height: 28px; background: transparent; color: rgba(255,255,255,.5); display: inline-flex; align-items: center; justify-content: center; flex: none; }
  #volume button:hover { color: #fff; }
  #volume svg { width: 18px; height: 18px; fill: currentColor; }
  #vol { -webkit-appearance: none; appearance: none; flex: 1; min-width: 0; height: 4px; border-radius: 2px; outline: 0; cursor: pointer;
         background: linear-gradient(to right, var(--accent) var(--v, 100%), rgba(255,255,255,.15) var(--v, 100%)); }
  #vol::-webkit-slider-thumb { -webkit-appearance: none; width: 12px; height: 12px; border-radius: 50%; background: #fff; border: 0; }
  #vol::-moz-range-thumb { width: 12px; height: 12px; border-radius: 50%; background: #fff; border: 0; }
  #vol::-moz-range-track { background: transparent; }
  #quick { margin-top: 28px; display: none; }
  #quick h2 { font-size: 11px; font-weight: 400; letter-spacing: .15em; text-transform: uppercase; color: rgba(255,255,255,.35); margin-bottom: 10px; }
  #quick .tiles { display: grid; grid-template-columns: repeat(3, 1fr); gap: 10px; }
  .tile { background: rgba(255,255,255,.07); border-radius: 12px; padding: 8px; text-align: left; transition: background .15s; min-width: 0; }
  .tile:hover { background: rgba(255,255,255,.14); }
  .tile .img { aspect-ratio: 1; border-radius: 8px; background: #1a1a1a center / cover no-repeat; margin-bottom: 8px; position: relative; }
  .tile .img b { position: absolute; left: 6px; bottom: 6px; font-size: 9px; font-weight: 600; letter-spacing: .1em; text-transform: uppercase; background: rgba(0,0,0,.6); border-radius: 4px; padding: 2px 5px; }
  .tile .t, .tile .s { display: block; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
  .tile .t { font-size: 13px; }
  .tile .s { font-size: 11px; color: rgba(255,255,255,.45); margin-top: 1px; }
  #next-up { margin-top: 24px; text-align: left; display: none; }
  #next-up h2 { font-size: 11px; font-weight: 400; letter-spacing: .15em; text-transform: uppercase; color: rgba(255,255,255,.35); margin-bottom: 8px; }
  #next-up li { list-style: none; padding: 6px 0; font-size: 14px; border-top: 1px solid rgba(255,255,255,.07); display: flex; justify-content: space-between; gap: 12px; }
  #next-up li span:first-child { overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
  #next-up li span:last-child { color: rgba(255,255,255,.4); white-space: nowrap; }
  #foot { margin-top: 36px; padding-top: 18px; border-top: 1px solid rgba(255,255,255,.08); display: flex; flex-direction: column; align-items: center; gap: 6px; }
  #status { color: rgba(255,255,255,.4); font-size: 12px; }
  .pill { background: #fff; color: #000; border-radius: 999px; padding: 12px 28px; font-size: 16px; }
  input[type=text] { background: transparent; color: rgba(255,255,255,.7); border: 0; border-bottom: 1px solid transparent; border-radius: 0; padding: 4px 8px; font-size: 14px; text-align: center; width: 100%; max-width: 260px; outline: 0; }
  input[type=text]:hover, input[type=text]:focus { border-bottom-color: rgba(255,255,255,.25); color: #fff; }
  select { background: #1a1a1a; color: #fff; border: 1px solid #333; border-radius: 8px; padding: 8px 12px; font-size: 14px; }
  #start { display: none; width: 100%; margin-bottom: 18px; padding: 12px 22px; font-size: 15px; }
  #choose { margin-bottom: 18px; background: rgba(255,255,255,.08); border-radius: 12px; padding: 14px; }
  .hint { color: rgba(255,255,255,.22); font-size: 11px; margin-top: 8px; }
  @media (max-width: 480px), (pointer: coarse) { .hint { display: none; } }
</style>
</head>
<body>
<div id="bg"></div>
<main>
  <div id="notice">
    <button id="start" class="pill" type="button">Allow audio</button>
    <div id="choose" style="display:none"><p style="color:rgba(255,255,255,.7);font-size:14px;margin-bottom:10px">Use an existing player or add this browser as a new one:</p><select id="existing"></select> <button id="use" class="pill" type="button" style="padding:8px 18px;font-size:14px">Use</button></div>
  </div>
  <div id="art"></div>
  <div id="title">Nothing playing</div>
  <div id="artist"></div>
  <div id="bar-wrap"><div id="bar"><i></i></div></div>
  <div id="times"><span id="elapsed">0:00</span><span id="total">0:00</span></div>
  <div id="controls">
    <button class="ctl mode" id="shuffle" type="button" title="Shuffle" aria-label="Shuffle" hidden><svg viewBox="0 0 24 24"><path d="M10.6 9.2 5.4 4 4 5.4l5.2 5.2zM14.5 4l2.0 2-12.5 12.6L5.4 20 18 7.5l2 2V4zm.5 11.4-1.4 1.4 3.4 3.4-2 2H20v-5.5l-2 2z"/></svg></button>
    <button class="ctl" id="prev" type="button" title="Previous (P)" aria-label="Previous"><svg viewBox="0 0 24 24"><path d="M6 6h2v12H6zM9.5 12 18 18V6z"/></svg></button>
    <button class="ctl" id="playpause" type="button" title="Play (Space)" aria-label="Play"><svg viewBox="0 0 24 24"><path d="M8 5v14l11-7z"/></svg></button>
    <button class="ctl" id="next" type="button" title="Next (N)" aria-label="Next"><svg viewBox="0 0 24 24"><path d="M16 6h2v12h-2zM6 18l8.5-6L6 6z"/></svg></button>
    <button class="ctl mode" id="repeat" type="button" title="Repeat" aria-label="Repeat" hidden><svg viewBox="0 0 24 24"><path d="M7 7h10v3l4-4-4-4v3H5v6h2zm10 10H7v-3l-4 4 4 4v-3h12v-6h-2z"/></svg></button>
  </div>
  <div id="volume">
    <button id="mute" type="button" title="Mute (M)" aria-label="Mute"><svg viewBox="0 0 24 24"><path d="M3 9v6h4l5 5V4L7 9z"/></svg></button>
    <input id="vol" type="range" min="0" max="100" value="100" aria-label="Volume">
    <button id="max" type="button" title="Full volume" aria-label="Full volume"><svg viewBox="0 0 24 24"><path d="M3 9v6h4l5 5V4L7 9zm13.5 3A4.5 4.5 0 0 0 14 8v8a4.5 4.5 0 0 0 2.5-4zM14 3.2v2.1c2.9.9 5 3.5 5 6.7s-2.1 5.800-5 6.700v2.100c4-.9 7-4.500 7-8.800s-3-7.900-7-8.800z"/></svg></button>
  </div>
  <div id="quick"><h2>Quick start</h2><div class="tiles"></div></div>
  <div id="next-up"><h2>Up next</h2><ol id="queue"></ol></div>
  <div id="foot">
    <input id="name" type="text" aria-label="Player name" maxlength="60">
    <div id="status">Connecting…</div>
    <div class="hint">Space play/pause · ←/→ seek · ↑/↓ volume · N/P next/previous · M mute</div>
  </div>
</main>
</body>
</html>
