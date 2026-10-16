<!DOCTYPE html>
<html>
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>ReMoment Player</title>
<x-live-scripts />
<script defer src="{{ asset('js/player.js') }}"></script>
<style>
  * { margin: 0; padding: 0; box-sizing: border-box; }
  body { background: #0a0a0a; color: #fff; font-family: system-ui, sans-serif; min-height: 100vh; display: flex; align-items: center; justify-content: center; padding: 24px; }
  main { width: 100%; max-width: 420px; text-align: center; }
  #art { width: 100%; aspect-ratio: 1; border-radius: 10px; background: #1a1a1a center / cover no-repeat; margin-bottom: 20px; }
  #title { font-size: 20px; font-weight: 500; }
  #artist { color: #aaa; margin-top: 4px; min-height: 1.3em; }
  #bar { height: 4px; background: #222; border-radius: 2px; margin: 20px 0 8px; overflow: hidden; }
  #bar i { display: block; height: 100%; width: 0; background: #fff; }
  #status { color: #777; font-size: 13px; margin-top: 16px; }
  button { background: #fff; color: #000; border: 0; border-radius: 999px; padding: 12px 28px; font-size: 16px; cursor: pointer; }
  input { background: #1a1a1a; color: #fff; border: 1px solid #333; border-radius: 8px; padding: 8px 12px; font-size: 14px; text-align: center; }
  select { background: #1a1a1a; color: #fff; border: 1px solid #333; border-radius: 8px; padding: 8px 12px; font-size: 14px; }
  #start { display: none; margin: 16px 0; }
</style>
</head>
<body>
<main>
  <div id="art"></div>
  <div id="title">Nothing playing</div>
  <div id="artist"></div>
  <div id="bar"><i></i></div>
  <button id="start" type="button">Start player</button>
  <div id="choose" style="display:none;margin-bottom:16px"><p style="color:#aaa;font-size:14px;margin-bottom:8px">Use an existing player or add this browser as a new one:</p><select id="existing"></select> <button id="use" type="button" style="padding:8px 18px;font-size:14px">Use</button></div>
  <div><input id="name" aria-label="Player name" maxlength="60"></div>
  <div id="status">Connecting…</div>
</main>
</body>
</html>
