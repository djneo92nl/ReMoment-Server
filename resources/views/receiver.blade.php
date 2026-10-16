<!DOCTYPE html>
<html>
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>ReMoment Receiver</title>
<x-live-scripts />
<style>
  @view-transition { navigation: auto; }
  /* only the named parts (cover, bar) morph between pages; the rest of a page switches as before */
  ::view-transition-old(root), ::view-transition-new(root) { animation: none; }
  ::view-transition-group(np-cover), ::view-transition-group(np-bar) { animation-duration: 0.8s; animation-timing-function: cubic-bezier(0.3, 0.7, 0.2, 1); }
  * { margin: 0; padding: 0; box-sizing: border-box; }

  body {
    background: #0a0a0a;
    color: #fff;
    font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, 'Helvetica Neue', Arial, sans-serif;
    height: 100vh;
    width: 100vw;
    overflow: hidden;
    display: flex;
    flex-direction: column;
  }

  .bg-layer {
    position: absolute;
    inset: 0;
    background-size: cover;
    background-position: center;
    filter: blur(80px) brightness(0.18) saturate(1.4);
    transform: scale(1.1);
    transition: opacity 1.4s ease;
    z-index: 0;
    opacity: 0;
  }

  #main {
    position: relative;
    z-index: 1;
    flex: 1;
    display: flex;
    align-items: center;
    justify-content: center;
    gap: 60px;
    padding: 60px 80px;
    min-height: 0;
    max-width: 1200px;
    margin: 0 auto;
    width: 100%;
  }

  #artwork-wrap {
    flex-shrink: 0;
    width: 425px;
    height: 425px;
    width: min(50vh, 480px);
    height: min(50vh, 480px);
    border-radius: 16px;
    overflow: hidden;
    background: #1a1a1a;
    position: relative;
    view-transition-name: np-cover;
    box-shadow: 0 30px 80px rgba(0,0,0,0.55), 0 6px 20px rgba(0,0,0,0.35);
    transition: transform 10s ease;
  }
  body.ambient #artwork-wrap { transform: scale(1.04); }

  .art-img {
    position: absolute;
    inset: 0;
    width: 100%;
    height: 100%;
    object-fit: cover;
    transition: opacity 0.9s ease;
    opacity: 0;
  }

  #art-placeholder {
    position: absolute;
    inset: 0;
    display: flex;
    align-items: center;
    justify-content: center;
  }

  #art-placeholder svg { opacity: 0.2; }

  #meta {
    flex: 1;
    min-width: 0;
    display: flex;
    flex-direction: column;
    justify-content: center;
    gap: 12px;
    transition: opacity 0.25s ease;
  }

  #platform {
    font-variant-numeric: tabular-nums;
    font-size: 14px;
    font-weight: 300;
    letter-spacing: 0.15em;
    text-transform: uppercase;
    color: rgba(255,255,255,0.35);
  }

  #track-name {
    font-size: 56px;
    font-weight: 500;
    letter-spacing: -0.02em;
    line-height: 1.15;
    overflow: hidden;
    color: var(--title, #fff);
    transition: color 0.6s;
    position: relative;
  }
  #track-name-inner {
    display: inline-block;
    white-space: nowrap;
  }
  #track-name-inner.marquee {
    animation: marquee-scroll linear infinite;
  }
  @keyframes marquee-scroll {
    0%   { transform: translateX(0); }
    15%  { transform: translateX(0); }
    85%  { transform: translateX(var(--marquee-dist)); }
    100% { transform: translateX(var(--marquee-dist)); }
  }

  #artist-name {
    font-size: 28px;
    font-weight: 300;
    color: rgba(255,255,255,0.6);
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
  }

  #album-name {
    font-size: 17px;
    font-weight: 300;
    color: rgba(255,255,255,0.35);
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
    margin-top: 4px;
  }

  #device-name {
    font-variant-numeric: tabular-nums;
    font-size: 14px;
    color: rgba(255,255,255,0.2);
    letter-spacing: 0.08em;
    margin-top: 8px;
  }

  #state-badge {
    display: inline-flex;
    align-items: center;
    gap: 6px;
    font-variant-numeric: tabular-nums;
    font-size: 12px;
    letter-spacing: 0.12em;
    text-transform: uppercase;
    color: rgba(255,255,255,0.3);
    margin-top: 4px;
  }

  #state-dot {
    width: 6px;
    height: 6px;
    border-radius: 50%;
    background: rgba(255,255,255,0.2);
  }

  #state-dot.playing {
    background: #4ade80;
    box-shadow: 0 0 6px rgba(74,222,128,0.6);
    animation: pulse 2s ease-in-out infinite;
  }

  @keyframes pulse {
    0%, 100% { opacity: 1; }
    50% { opacity: 0.5; }
  }

  /* ── Bar: the sticky player bar's look (artwork-coloured glass, accent play button, waveform progress) ── */
  #bar {
    position: relative;
    z-index: 1;
    display: flex;
    align-items: center;
    gap: 6px;
    box-sizing: border-box;
    flex-wrap: wrap;
    width: calc(100% - 160px);
    max-width: 1200px;
    margin: 0 auto 36px;
    padding: 12px 20px 10px;
    border-radius: 16px;
    overflow: hidden;
    color: #fff;
    isolation: isolate;
    view-transition-name: np-bar;
    background-color: rgba(255,255,255,0);
    -webkit-backdrop-filter: blur(0px) saturate(1);
    backdrop-filter: blur(0px) saturate(1);
    /* no real border: the gradient would tile into it and draw a light edge */
    box-shadow: 0 8px 30px rgba(0,0,0,0.35), inset 0 0 0 1px rgba(255,255,255,0);
    transition: box-shadow 1.5s ease, background-color 1.5s ease, -webkit-backdrop-filter 1.5s ease, backdrop-filter 1.5s ease;
  }
  /* idle: the bar turns into frosted glass over the blurred cover */
  body.ambient #bar {
    background-color: rgba(255,255,255,0.07);
    -webkit-backdrop-filter: blur(28px) saturate(1.3);
    backdrop-filter: blur(28px) saturate(1.3);
    box-shadow: 0 8px 30px rgba(0,0,0,0.35), inset 0 0 0 1px rgba(255,255,255,0.12);
  }
  body.ambient #bar::after, body.ambient #bar::before { opacity: 0; }
  /* two fading layers (a gradient can't be transitioned into the glass): the artwork gradient, and a scrim on it */
  #bar::after, #bar::before {
    content: '';
    position: absolute;
    inset: 0;
    pointer-events: none;
    transition: opacity 1.5s ease;
  }
  #bar::after { z-index: -2; background: var(--bar-bg, linear-gradient(100deg, #2a2e36 0%, #1c1f25 100%)); }
  #bar::before { z-index: -1; background: rgba(0,0,0,0.45); }
  #bar > * { position: relative; }

  #controls { display: none; align-items: center; gap: 4px; flex: none; }
  #vol-row { display: none; }
  #bar.has-controls #controls, #bar.has-controls #vol-row { display: flex; }

  .ctrl-btn {
    background: none;
    border: none;
    cursor: pointer;
    color: #fff;
    width: 38px; height: 38px;
    padding: 0;
    border-radius: 50%;
    display: flex;
    align-items: center;
    justify-content: center;
    flex: none;
    transition: background 0.15s, transform 0.1s, opacity 1s ease;
    -webkit-tap-highlight-color: transparent;
  }
  .ctrl-btn:hover { background: rgba(255,255,255,0.12); }
  .ctrl-btn:active { transform: scale(0.92); }
  .ctrl-btn svg { display: block; }
  .ctrl-btn.primary { width: 48px; height: 48px; margin-right: 6px; background: var(--accent, #e8a46b); color: #111; }
  .ctrl-btn.primary:hover { background: var(--accent, #e8a46b); filter: brightness(1.1); }
  .ctrl-btn.mode { opacity: 0.6; }
  .ctrl-btn.mode.on { opacity: 1; color: var(--accent, #e8a46b); }

  #progress-wrap {
    order: -1;
    flex: 1 1 100%;
    margin: 0 0 6px;
    display: flex;
    align-items: center;
    gap: 10px;
    font-variant-numeric: tabular-nums;
    font-size: 12px;
    font-variant-numeric: tabular-nums;
    color: rgba(255,255,255,0.75);
  }
  #time-pos { min-width: 36px; text-align: right; }
  #progress-bar-bg {
    position: relative;
    flex: 1;
    height: 44px;
  }
  #progress-wrap.seekable #progress-bar-bg { cursor: pointer; }
  #progress-line {
    position: absolute;
    left: 0; right: 0; top: 50%;
    height: 4px; margin-top: -2px;
    border-radius: 2px;
    background: rgba(255,255,255,0.28);
    overflow: hidden;
  }
  #progress-bar-fill {
    height: 100%;
    width: 0%;
    border-radius: 2px;
    background: var(--accent, #e8a46b);
    transition: width 1s linear;
  }
  #wf-base, #wf-played { position: absolute; inset: 0; display: none; }
  #wf-played {
    color: var(--accent, #e8a46b);
    -webkit-clip-path: inset(0 100% 0 0);
    clip-path: inset(0 100% 0 0);
    transition: clip-path 1s linear, -webkit-clip-path 1s linear;
  }
  #wf-base { color: rgba(255,255,255,0.35); }
  #wf-base svg, #wf-played svg { width: 100%; height: 100%; display: block; }
  #progress-bar-bg.has-wave #wf-base, #progress-bar-bg.has-wave #wf-played { display: block; }
  #progress-bar-bg.has-wave #progress-line { display: none; }

  #vol-row { transition: opacity 1s ease; }
  #bar-right { display: flex; align-items: center; gap: 2px; margin-left: auto; flex: none; }
  #bar-right > .ctrl-btn { display: none; }
  #vol-row {
    align-items: center;
    gap: 4px;
    width: 130px;
    flex: none;
    margin-right: 8px;
  }
  #vol-mute { color: #fff; }
  #vol-row.muted #vol-slider, #vol-row.muted #vol-val { opacity: 0.35; }
  #vol-row.muted #vol-mute { color: #f87171; }
  #vol-slider {
    flex: 1;
    min-width: 0;
    -webkit-appearance: none;
    height: 4px;
    background: rgba(255,255,255,0.3);
    border-radius: 2px;
    outline: none;
    cursor: pointer;
  }
  #vol-slider::-webkit-slider-thumb {
    -webkit-appearance: none;
    width: 12px; height: 12px;
    border-radius: 50%;
    background: #fff;
    box-shadow: 0 1px 4px rgba(0,0,0,0.4);
    cursor: pointer;
  }
  #vol-slider::-moz-range-thumb {
    width: 12px; height: 12px;
    border: none;
    border-radius: 50%;
    background: #fff;
    cursor: pointer;
  }
  #vol-val { display: none; }

  #picker-panel {
    position: fixed;
    inset: 0;
    background: #0a0a0a;
    z-index: 100;
    display: flex;
    flex-direction: column;
    align-items: center;
    justify-content: center;
    gap: 16px;
    padding: 40px;
  }

  #picker-panel h1 {
    font-variant-numeric: tabular-nums;
    font-size: 16px;
    letter-spacing: 0.2em;
    text-transform: uppercase;
    color: rgba(255,255,255,0.4);
    margin-bottom: 8px;
  }

  .picker-device {
    width: 360px;
    background: #1a1a1a;
    border: 1px solid rgba(255,255,255,0.1);
    border-radius: 4px;
    color: #fff;
    font-variant-numeric: tabular-nums;
    font-size: 14px;
    padding: 14px 16px;
    cursor: pointer;
    text-align: left;
    display: flex;
    align-items: center;
    justify-content: space-between;
    transition: background 0.15s, border-color 0.15s;
  }

  .picker-device:hover {
    background: #242424;
    border-color: rgba(255,255,255,0.2);
  }

  .picker-device .device-label { font-weight: 400; }
  .picker-device .device-sub { font-size: 11px; color: rgba(255,255,255,0.3); letter-spacing: 0.05em; }

  #picker-status {
    font-variant-numeric: tabular-nums;
    font-size: 13px;
    color: rgba(255,255,255,0.3);
    min-height: 16px;
    text-align: center;
  }

  /* back to where the receiver was opened from (only shown when it was opened from this site) */
  #btn-back {
    display: none;
    position: fixed;
    top: 20px;
    left: 20px;
    z-index: 10;
    background: none;
    border: none;
    cursor: pointer;
    padding: 10px;
    border-radius: 8px;
    color: rgba(255,255,255,0.25);
    transition: color 0.15s, background 0.15s;
    line-height: 0;
  }
  #btn-back:hover { color: rgba(255,255,255,0.7); background: rgba(255,255,255,0.08); }
  body.ambient #btn-back { opacity: 0 !important; pointer-events: none; transition: opacity 2.5s ease !important; }

  #btn-fullscreen {
    position: fixed;
    top: 20px;
    right: 20px;
    z-index: 10;
    background: none;
    border: none;
    cursor: pointer;
    padding: 10px;
    border-radius: 8px;
    color: rgba(255,255,255,0.25);
    transition: color 0.15s, background 0.15s;
    line-height: 0;
  }
  #btn-fullscreen:hover { color: rgba(255,255,255,0.7); background: rgba(255,255,255,0.08); }

  /* ── Up next: a panel floating above the bar, like the sticky bar's ── */
  #bar-right > .ctrl-btn.active { color: var(--accent, #e8a46b); }
  #queue-panel.open, #multi-panel.open, #switch-panel.open { display: flex; }
  #queue-panel, #multi-panel, #switch-panel {
    display: none;
    position: fixed;
    z-index: 1; /* under the bar (later in the DOM), so it slides out from behind it */
    right: 0; bottom: 0;
    width: 26rem; max-width: calc(100vw - 24px);
    max-height: 60vh;
    flex-direction: column;
    box-sizing: border-box;
    padding: 16px 8px 28px 16px; /* the bottom 16px sit behind the bar */
    border-radius: 16px 16px 0 0;
    overflow: hidden;
    color: #fff;
    background: var(--bar-bg, linear-gradient(100deg, #2a2e36 0%, #1c1f25 100%));
    box-shadow: 0 -8px 30px rgba(0,0,0,0.25);
  }
  /* the artwork colours, darkened like the bar */
  .fpanel::before { content: ''; position: absolute; inset: 0; background: rgba(0,0,0,0.45); pointer-events: none; }
  .fpanel > * { position: relative; }
  #bar.panel-open { border-top-right-radius: 0; }
  .fpanel h3 { font-size: 17px; font-weight: 700; margin: 0 0 10px; flex: none; }
  .fpanel .q-row {
    display: flex; align-items: center; gap: 10px; width: 100%;
    padding: 6px 8px; border: 0; border-radius: 10px;
    background: none; color: inherit; text-align: left; font: inherit; flex: none;
  }
  .fpanel button.q-row { cursor: pointer; }
  .fpanel button.q-row:hover { background: rgba(255,255,255,0.12); }
  .fpanel .q-row.now { background: rgba(255,255,255,0.12); margin-bottom: 6px; }
  .fpanel .q-n { width: 20px; text-align: right; font-size: 12px; opacity: 0.5; flex: none; }
  .fpanel .q-art { width: 40px; height: 40px; border-radius: 6px; overflow: hidden; flex: none; background: rgba(255,255,255,0.12); }
  .fpanel .q-art img { width: 100%; height: 100%; object-fit: cover; display: block; }
  .fpanel .q-txt { min-width: 0; flex: 1; }
  .fpanel .q-name, #queue-panel .q-sub { display: block; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
  .fpanel .q-name { font-size: 14px; font-weight: 600; }
  .fpanel .q-sub { font-size: 12px; opacity: 0.6; }
  .fpanel .q-dur { font-size: 12px; opacity: 0.55; flex: none; font-variant-numeric: tabular-nums; }
  .fpanel .q-msg { font-size: 14px; opacity: 0.7; margin: 0; }
  #queue-list { list-style: none; margin: 0; padding: 0 8px 0 0; overflow-y: auto; min-height: 0; flex: 1 1 auto; }
  .fpanel .q-row.now .q-name { color: var(--accent, #e8a46b); }

  /* ── Lyrics toggle: a bar button, shown only when the track has lyrics ── */

  /* ── Lyrics 3-line view ── */
  #lyrics-view {
    display: none;
    flex: 1;
    min-width: 0;
    flex-direction: column;
    justify-content: center;
    gap: 18px;
    transition: opacity 0.2s ease;
  }
  #lyrics-title {
    font-size: 15px;
    font-weight: 500;
    letter-spacing: 0.02em;
    color: var(--title, rgba(255,255,255,0.7));
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
    opacity: 0.85;
    margin-bottom: 14px;
  }
  #lyric-prev, #lyric-next {
    font-size: 20px;
    font-weight: 300;
    color: rgba(255,255,255,0.22);
    white-space: normal;
    overflow: hidden;
    max-height: 1.6em;
  }
  #lyric-curr {
    font-size: 34px;
    font-weight: 500;
    line-height: 1.25;
    color: rgba(255,255,255,0.92);
    white-space: nowrap;
    overflow: hidden;
    position: relative;
  }
  #lyric-curr-inner {
    display: inline-block;
    white-space: nowrap;
  }
  #lyric-curr-inner.marquee {
    animation: marquee-scroll linear infinite;
  }

  /* ── Idle clock ── */
  #idle-screen {
    position: fixed;
    inset: 0;
    display: flex;
    flex-direction: column;
    align-items: center;
    justify-content: center;
    z-index: 4;
    opacity: 0;
    pointer-events: none;
    transition: opacity 1s ease;
  }
  #idle-screen.visible { opacity: 1; }
  #idle-time {
    font-size: 14vw;
    font-size: clamp(80px, 14vw, 160px);
    font-weight: 300;
    letter-spacing: -0.02em;
    color: rgba(255,255,255,0.88);
    font-variant-numeric: tabular-nums;
    line-height: 1;
  }
  #idle-date {
    font-variant-numeric: tabular-nums;
    font-size: 15px;
    color: rgba(255,255,255,0.28);
    letter-spacing: 0.18em;
    text-transform: uppercase;
    margin-top: 22px;
  }

  /* ── Ambient mode ── */
  body.ambient:not(.ambient-plain) #meta,
  body.ambient #btn-fullscreen,
  body.ambient .fpanel,
  /* no lyrics to show: keep play/pause, progress, track and artist, fade the rest */
  body.ambient-plain #album-name,
  body.ambient-plain #device-name,
  body.ambient-plain #state-badge,
  body.ambient-plain #platform { opacity: 0 !important; pointer-events: none; transition: opacity 2.5s ease !important; }
  /* the bar stays (with or without lyrics): play/pause and progress as they are, the other buttons darkened so the bar doesn't look empty */
  body.ambient #vol-row,
  body.ambient #controls .ctrl-btn:not(.primary),
  body.ambient #bar-right > .ctrl-btn { opacity: 0.25 !important; pointer-events: none; transition: opacity 2.5s ease !important; }

  /* ── Device switcher / multiroom panels share the queue panel's look ── */
  .switch-dot { width: 8px; height: 8px; border-radius: 50%; flex-shrink: 0; margin: 0 6px; }
  .fpanel .q-section { font-size: 11px; font-weight: 600; letter-spacing: 0.08em; text-transform: uppercase; opacity: 0.5; margin: 8px 0 4px; flex: none; }
  .fpanel .q-scroll { overflow-y: auto; min-height: 0; flex: 1 1 auto; padding-right: 8px; }
  .fpanel .q-row.active { opacity: 0.45; cursor: default; }
  .eq { display: inline-flex; align-items: flex-end; gap: 2px; height: 14px; width: 20px; justify-content: center; flex: none; }
  .eq i { width: 3px; height: 100%; background: var(--accent, #e8a46b); border-radius: 1px; transform-origin: bottom; transform: scaleY(0.5); }
  body.is-playing .eq i { animation: eq-bar 0.9s ease-in-out infinite alternate; }
  .eq i:nth-child(2) { animation-delay: 0.2s; }
  .eq i:nth-child(3) { animation-delay: 0.4s; }
  @keyframes eq-bar { from { transform: scaleY(0.3); } to { transform: scaleY(1); } }

  /* ── Responsive: portrait tablet (≤ 900px) ── */
  @media (max-width: 900px) {
    #main {
      flex-direction: column;
      align-items: center;
      gap: 20px;
      padding: 20px 24px 0;
    }
    #artwork-wrap { width: 260px; height: 260px; }
    #track-name { font-size: 34px; }
    #meta, #lyrics-view { width: 100%; }
    #artist-name { font-size: 18px; }
    #album-name { font-size: 13px; }
    #bar { width: calc(100% - 48px); margin-bottom: 20px; }
    .ctrl-btn { width: 44px; height: 44px; }
    .ctrl-btn.primary { width: 52px; height: 52px; }
    #lyric-curr { font-size: 24px; }
    #lyric-prev, #lyric-next { font-size: 15px; }
    .picker-device { width: 100%; max-width: 360px; }
    .ctrl-btn, #btn-fullscreen { touch-action: manipulation; }
  }

  /* ── Responsive: phone (≤ 480px) ── */
  @media (max-width: 480px) {
    #main { gap: 14px; padding: 14px 16px 0; }
    #artwork-wrap { width: 52vw; height: 52vw; }
    #track-name { font-size: 22px; }
    #artist-name { font-size: 14px; }
    #album-name { font-size: 11px; }
    #bar { width: calc(100% - 24px); padding: 10px 12px; margin-bottom: 12px; }
    #lyric-curr { font-size: 19px; }
    #lyric-prev, #lyric-next { font-size: 12px; }
    #lyrics-view { gap: 14px; }
    #vol-slider, #vol-val { display: none; }
    #vol-row { width: auto; margin-right: 0; }
    #progress-bar-bg { height: 36px; }
  }
  /* ── Easter egg: vinyl mode (↑↑↓↓←→←→BA) ── */
  body.vinyl #artwork-wrap {
    border-radius: 50%;
    animation: vinyl-spin 1.8s linear infinite;
    animation-play-state: paused;
    box-shadow: 0 0 0 6px #111, 0 20px 60px rgba(0,0,0,0.6);
  }
  body.vinyl.is-playing #artwork-wrap { animation-play-state: running; }
  body.vinyl #artwork-wrap::after {
    content: '';
    position: absolute;
    inset: 0;
    z-index: 2;
    border-radius: 50%;
    background:
      radial-gradient(circle, transparent 18%, rgba(0,0,0,0.55) 18.5%, rgba(0,0,0,0.55) 20%, transparent 20.5%),
      repeating-radial-gradient(circle, rgba(0,0,0,0.35) 0 1px, transparent 1px 4px),
      linear-gradient(135deg, rgba(255,255,255,0.12), transparent 40%, rgba(255,255,255,0.08) 60%, transparent);
  }
  body.vinyl #artwork-wrap::before {
    content: '';
    position: absolute;
    inset: 42%;
    z-index: 3;
    border-radius: 50%;
    background: radial-gradient(circle, #000 0 12%, var(--vinyl-label, #c0392b) 13%);
  }
  @keyframes vinyl-spin { to { transform: rotate(360deg); } }
</style>
</head>
<body>

<div id="picker-panel">
  <h1>remoment receiver</h1>
  <div id="picker-devices"></div>
  <div id="picker-status">Loading…</div>
</div>

<button id="btn-back" onclick="history.back()" title="Back">
  <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"><polyline points="15 18 9 12 15 6"/></svg>
</button>

<button id="btn-fullscreen" onclick="toggleFullscreen()" title="Toggle fullscreen">
  <svg id="icon-expand" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round">
    <polyline points="15 3 21 3 21 9"/><polyline points="9 21 3 21 3 15"/>
    <line x1="21" y1="3" x2="14" y2="10"/><line x1="3" y1="21" x2="10" y2="14"/>
  </svg>
  <svg id="icon-compress" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" style="display:none">
    <polyline points="4 14 10 14 10 20"/><polyline points="20 10 14 10 14 4"/>
    <line x1="10" y1="14" x2="3" y2="21"/><line x1="21" y1="3" x2="14" y2="10"/>
  </svg>
</button>

<div class="bg-layer" id="bg-a"></div>
<div class="bg-layer" id="bg-b"></div>

<div id="idle-screen">
  <div id="idle-time">00:00</div>
  <div id="idle-date"></div>
</div>

<div id="main">
  <div id="artwork-wrap">
    <div id="art-placeholder">
      <svg width="48" height="48" viewBox="0 0 24 24" fill="none" stroke="white" stroke-width="1">
        <circle cx="12" cy="12" r="10"/><circle cx="12" cy="12" r="3"/>
        <line x1="12" y1="2" x2="12" y2="5"/><line x1="12" y1="19" x2="12" y2="22"/>
      </svg>
    </div>
    <img class="art-img" id="aw-a" src="" alt="">
    <img class="art-img" id="aw-b" src="" alt="">
  </div>

  <div id="meta">
    <div id="platform">—</div>
    <div id="track-name"><span id="track-name-inner">—</span></div>
    <div id="artist-name">—</div>
    <div id="album-name"></div>
    <div id="device-name"></div>
    <div id="state-badge"><span id="state-dot"></span><span id="state-label">idle</span></div>
  </div>

  <div id="lyrics-view">
    <p id="lyrics-title"></p>
    <p id="lyric-prev"></p>
    <p id="lyric-curr"><span id="lyric-curr-inner"></span></p>
    <p id="lyric-next"></p>
  </div>
</div>

<div id="queue-panel" class="fpanel" onclick="event.stopPropagation()">
  <h3>Up next</h3>
  <div id="queue-now"></div>
  <p class="q-msg" id="queue-msg"></p>
  <ol id="queue-list"></ol>
</div>
<div id="multi-panel" class="fpanel" onclick="event.stopPropagation()">
  <h3>Multiroom</h3>
  <p class="q-msg" id="multi-msg"></p>
  <div class="q-scroll" id="multi-body"></div>
</div>
<div id="switch-panel" class="fpanel" onclick="event.stopPropagation()">
  <h3>Player</h3>
  <div class="q-scroll" id="switch-body"></div>
</div>

<div id="bar">
  <div id="controls">
    <button class="ctrl-btn primary" id="btn-playpause" onclick="togglePlayPause()" title="Play/Pause">
      <svg id="icon-play" width="20" height="20" viewBox="0 0 24 24" fill="currentColor" stroke="none" style="margin-left:2px">
        <polygon points="5 3 19 12 5 21 5 3"/>
      </svg>
      <svg id="icon-pause" width="20" height="20" viewBox="0 0 24 24" fill="currentColor" stroke="none" style="display:none">
        <rect x="6" y="4" width="4" height="16"/><rect x="14" y="4" width="4" height="16"/>
      </svg>
    </button>
    <button class="ctrl-btn" onclick="sendAction('previous')" title="Previous">
      <svg width="16" height="16" viewBox="0 0 24 24" fill="currentColor" stroke="currentColor" stroke-width="2" stroke-linejoin="round" stroke-linecap="round">
        <polygon points="19 20 9 12 19 4 19 20"/><line x1="5" y1="4" x2="5" y2="20"/>
      </svg>
    </button>
    <button class="ctrl-btn" onclick="sendAction('next')" title="Next">
      <svg width="16" height="16" viewBox="0 0 24 24" fill="currentColor" stroke="currentColor" stroke-width="2" stroke-linejoin="round" stroke-linecap="round">
        <polygon points="5 4 15 12 5 20 5 4"/><line x1="19" y1="4" x2="19" y2="20"/>
      </svg>
    </button>
    <button class="ctrl-btn mode" id="btn-shuffle" onclick="toggleShuffle()" title="Shuffle" style="display:none">
      <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
        <polyline points="16 3 21 3 21 8"/><line x1="4" y1="20" x2="21" y2="3"/><polyline points="21 16 21 21 16 21"/><line x1="15" y1="15" x2="21" y2="21"/><line x1="4" y1="4" x2="9" y2="9"/>
      </svg>
    </button>
    <button class="ctrl-btn mode" id="btn-repeat" onclick="cycleRepeat()" title="Repeat" style="display:none">
      <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
        <polyline points="17 1 21 5 17 9"/><path d="M3 11V9a4 4 0 0 1 4-4h14"/><polyline points="7 23 3 19 7 15"/><path d="M21 13v2a4 4 0 0 1-4 4H3"/>
        <text id="repeat-one" x="12" y="15" text-anchor="middle" font-size="8" font-weight="700" fill="currentColor" stroke="none" style="display:none">1</text>
      </svg>
    </button>
  </div>

  <div id="progress-wrap">
    <span id="time-pos">0:00</span>
    <div id="progress-bar-bg">
      <div id="progress-line"><div id="progress-bar-fill"></div></div>
      <div id="wf-base"></div>
      <div id="wf-played"></div>
    </div>
    <span id="time-dur">—</span>
  </div>

  <div id="bar-right">
    <button class="ctrl-btn" id="btn-like" onclick="toggleLike()" title="Like">
      <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path id="heart-path" d="M20.8 4.6a5.5 5.5 0 0 0-7.8 0L12 5.7l-1-1.1a5.5 5.5 0 0 0-7.8 7.8l1 1.1L12 21l7.8-7.5 1-1.1a5.5 5.5 0 0 0 0-7.8z"/></svg>
    </button>
  <div id="vol-row">
    <button class="ctrl-btn" id="vol-mute" onclick="toggleMute()" title="Mute">
      <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
        <polygon points="11 5 6 9 2 9 2 15 6 15 11 19 11 5"/><path d="M19.07 4.93a10 10 0 0 1 0 14.14M15.54 8.46a5 5 0 0 1 0 7.07"/>
      </svg>
    </button>
    <input type="range" id="vol-slider" min="0" max="100" value="50">
    <span id="vol-val">—</span>
  </div>

    <button class="ctrl-btn" id="btn-multi" onclick="togglePanel('multi', event)" title="Multiroom">
      <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polygon points="12 2 2 7 12 12 22 7 12 2"/><polyline points="2 17 12 22 22 17"/><polyline points="2 12 12 17 22 12"/></svg>
    </button>
    <button class="ctrl-btn" id="btn-queue" onclick="togglePanel('queue', event)" title="Up next">
      <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="8" y1="6" x2="21" y2="6"/><line x1="8" y1="12" x2="21" y2="12"/><line x1="8" y1="18" x2="21" y2="18"/><circle cx="3.5" cy="6" r="1" fill="currentColor"/><circle cx="3.5" cy="12" r="1" fill="currentColor"/><circle cx="3.5" cy="18" r="1" fill="currentColor"/></svg>
    </button>
    <button class="ctrl-btn" id="btn-lyrics" onclick="toggleLyricsView()" title="Lyrics">
      <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="3" y1="6" x2="21" y2="6"/><line x1="3" y1="12" x2="17" y2="12"/><line x1="3" y1="18" x2="19" y2="18"/></svg>
    </button>
    <button class="ctrl-btn" id="btn-devices" onclick="togglePanel('devices', event)" title="Change player">
      <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="2" y="3" width="20" height="14" rx="2"/><path d="M8 21h8M12 17v4"/></svg>
    </button>
  </div>
</div>

<script>
const isChromecast = /CrKey/i.test(navigator.userAgent);
const hasTouch = 'ontouchstart' in window || navigator.maxTouchPoints > 0;
const isTV = /TV|SmartTV|Tizen|WebOS|HbbTV|BRAVIA/i.test(navigator.userAgent);
const showControls = !isChromecast;

let deviceId = null;
let pollTimer = null;
let currentProgress = 0, currentDuration = 0, progressLastPollTime = 0, isCurrentlyPlaying = false;
let lastTrackId = null;
let activeArt = 'a', currentArtUrl = '';
let allDevices = [];
let currentVolume = 50, volumeDebounce = null;
let currentMuted = false, deviceCaps = [];
let lastPollAt = 0, pushPollDebounce = null;
let ambientTimer = null;
let currentLyricsData = null;
let currentLyricLineIdx = -2;
let lastLyricsKey = null;
let lyricsViewActive = false;
let ambientSwitchedToLyrics = false;
let lyricFadeTimer = null;

const params = new URLSearchParams(window.location.search);
const paramDeviceId = params.get('device') ? parseInt(params.get('device')) : null;

async function init() {
  try {
    const r = await fetch('/api/devices');
    if (!r.ok) throw new Error(`HTTP ${r.status}`);
    const json = await r.json();
    const devices = json.data != null ? json.data : json;
    allDevices = devices;

    if (!devices.length) {
      document.getElementById('picker-status').textContent = 'No devices found';
      return;
    }

    if (paramDeviceId) {
      const match = devices.find(d => d.id === paramDeviceId);
      if (match) { startReceiver(match); return; }
    }

    if (devices.length === 1) {
      startReceiver(devices[0]);
      return;
    }

    document.getElementById('picker-status').textContent = 'Select a device';
    const container = document.getElementById('picker-devices');
    devices.forEach(d => {
      const btn = document.createElement('button');
      btn.className = 'picker-device';
      btn.innerHTML = `<span class="device-label">${d.device_name}</span><span class="device-sub">${d.device_product_type || ''}</span>`;
      btn.onclick = () => startReceiver(d);
      container.appendChild(btn);
    });
  } catch(e) {
    document.getElementById('picker-status').textContent = `Cannot reach API: ${e.message}`;
  }
}

function startReceiver(device) {
  if (pollTimer) clearInterval(pollTimer);
  deviceId = device.id;
  lastTrackId = null;
  currentArtUrl = '';
  activeArt = 'a';
  document.getElementById('picker-panel').style.display = 'none';
  const label = [device.device_name, device.device_product_type].filter(Boolean).join(' · ');
  document.getElementById('device-name').textContent = label;
  if (showControls) {
    document.getElementById('bar').classList.add('has-controls');
    fetchVolume();
  }
    deviceCaps = device.capabilities || [];
  document.getElementById('btn-shuffle').style.display = deviceCaps.includes('shuffle') ? 'flex' : 'none';
  document.getElementById('btn-repeat').style.display = deviceCaps.includes('repeat') ? 'flex' : 'none';
  const showBtn = (id, on) => { document.getElementById(id).style.display = on ? 'flex' : 'none'; };
  showBtn('btn-queue', deviceCaps.includes('queue'));
  showBtn('btn-like', deviceCaps.includes('like'));
  showBtn('btn-multi', deviceCaps.includes('multi_room'));
  showBtn('btn-devices', allDevices.length > 1);
  togglePanel(null, null, false);
  document.getElementById('progress-wrap').classList.toggle('seekable', deviceCaps.includes('seek'));
  if (showControls && deviceCaps.includes('volume_control')) fetchMute();
  resetAmbient();
  pollNow();
  // With MQTT push connected, polling is only a safety net; otherwise poll every 3s.
  pollTimer = setInterval(() => {
    const interval = (window.RemomentLive && RemomentLive.connected) ? 15000 : 3000;
    if (Date.now() - lastPollAt >= interval) pollNow();
  }, 1000);
}

// remoment-live.js is deferred, so it's only defined once the DOM has parsed.
document.addEventListener('DOMContentLoaded', () => {
  if (!window.RemomentLive) return;
  RemomentLive.on(msg => {
    if (msg.deviceId !== deviceId || msg.retained) return;
    if (msg.kind === 'volume' && msg.data && msg.data.volume != null) {
      currentVolume = msg.data.volume;
      updateVolumeUI();
    } else if (msg.kind === 'state' || msg.kind === 'data') {
      clearTimeout(pushPollDebounce);
      pushPollDebounce = setTimeout(pollNow, 150);
    } else if (msg.kind === 'progress' && currentDuration > 0) {
      // Progress is pushed as a percentage; re-fetch only if local interpolation drifted (e.g. after a seek).
      const localPct = (currentPosition() / currentDuration) * 100;
      if (Math.abs(parseInt(msg.data, 10) - localPct) > 3) pollNow();
    }
  });
});

async function pollNow() {
  lastPollAt = Date.now();
  try {
    const r = await fetch(`/api/devices/${deviceId}`);
    if (!r.ok) return;
    const json = await r.json();
    updateUI(json.data != null ? json.data : json);
  } catch(e) {}
}

function parseLrc(lrc) {
  const lines = [];
  for (const line of lrc.split('\n')) {
    const m = line.match(/\[(\d+):(\d+(?:\.\d+)?)\](.*)/);
    if (m) {
      const time = parseInt(m[1]) * 60 + parseFloat(m[2]);
      const text = m[3].trim();
      if (text) lines.push({ time, text });
    }
  }
  return lines.sort((a, b) => a.time - b.time);
}

function setLyrics(lyrics) {
  const btn = document.getElementById('btn-lyrics');
  currentLyricLineIdx = -2;
  if (lyrics && lyrics.synced) {
    const lines = parseLrc(lyrics.synced);
    if (lines.length) {
      currentLyricsData = { type: 'synced', lines };
      btn.style.display = 'flex';
      syncLyricsLine();
      return;
    }
  }
  if (lyrics && lyrics.plain) {
    const lines = lyrics.plain.split('\n').filter(t => t.trim()).map(t => ({ time: null, text: t }));
    if (lines.length) {
      currentLyricsData = { type: 'plain', lines };
      btn.style.display = 'flex';
      syncLyricsLine();
      return;
    }
  }
  currentLyricsData = null;
  btn.style.display = 'none';
  if (lyricsViewActive) toggleLyricsView();
  document.getElementById('lyric-prev').textContent = '';
  document.getElementById('lyric-curr-inner').textContent = '';
  document.getElementById('lyric-next').textContent = '';
}

function updateLyricDisplay(idx) {
  const lines = currentLyricsData.lines;
  const view = document.getElementById('lyrics-view');
  if (lyricFadeTimer) clearTimeout(lyricFadeTimer);
  view.style.opacity = '0';
  lyricFadeTimer = setTimeout(() => {
    document.getElementById('lyric-prev').textContent = idx > 0 ? lines[idx - 1].text : '';
    const inner = document.getElementById('lyric-curr-inner');
    inner.classList.remove('marquee');
    inner.style.removeProperty('--marquee-dist');
    inner.style.removeProperty('animation-duration');
    inner.textContent = idx >= 0 ? lines[idx].text : ((lines[0] && lines[0].text) || '');
    document.getElementById('lyric-next').textContent = (lines[idx + 1] && lines[idx + 1].text) || '';
    view.style.opacity = '1';
    const container = document.getElementById('lyric-curr');
    document.fonts.ready.then(() => requestAnimationFrame(() => {
      const overflow = inner.scrollWidth - container.clientWidth;
      if (overflow > 10) {
        const duration = Math.max(6, overflow / 50);
        inner.style.setProperty('--marquee-dist', `-${overflow + 40}px`);
        inner.style.animationDuration = `${duration}s`;
        inner.classList.add('marquee');
      }
    }));
  }, 180);
}

function syncLyricsLine() {
  if (!currentLyricsData) return;
  const lines = currentLyricsData.lines;
  let pos = currentProgress;
  if (isCurrentlyPlaying && progressLastPollTime) {
    pos = Math.min(currentDuration || Infinity, currentProgress + (Date.now() - progressLastPollTime) / 1000);
  }
  let idx;
  if (currentLyricsData.type === 'synced') {
    idx = -1;
    for (let i = 0; i < lines.length; i++) {
      if (lines[i].time <= pos + 0.3) idx = i;
      else break;
    }
  } else {
    idx = currentDuration > 0
      ? Math.min(lines.length - 1, Math.floor((pos / currentDuration) * lines.length))
      : 0;
  }
  if (idx === currentLyricLineIdx) return;
  currentLyricLineIdx = idx;
  updateLyricDisplay(idx);
}

function toggleLyricsView() {
  lyricsViewActive = !lyricsViewActive;
  const meta = document.getElementById('meta');
  const view = document.getElementById('lyrics-view');
  const btn  = document.getElementById('btn-lyrics');
  meta.style.display = lyricsViewActive ? 'none' : '';
  view.style.display = lyricsViewActive ? 'flex' : 'none';
  btn.classList.toggle('active', lyricsViewActive);
  if (lyricsViewActive) { currentLyricLineIdx = -2; syncLyricsLine(); }
}

function fixHost(url) {
  if (!url) return '';
  return url.replace(/https?:\/\/localhost(:\d+)?/, window.location.origin);
}

function updateUI(d) {
  const np = d.now_playing;
  const dot = document.getElementById('state-dot');
  const label = document.getElementById('state-label');

  const isIdle = !np || d.state === 'Unreachable' || d.state === 'standby';
  showIdle(isIdle);

  if (!np || d.state === 'Unreachable') {
    dot.className = '';
    label.textContent = 'unreachable';
    return;
  }

  const state = np.state || d.state || 'idle';
  dot.className = state === 'playing' ? 'playing' : '';
  label.textContent = state;
  if (showControls) updatePlayPauseIcon(state);

  if (!isCurrentlyPlaying) {
    document.body.classList.remove('ambient');
    clearTimeout(ambientTimer);
  }

  document.getElementById('platform').textContent = np.platform || (np.source && np.source.name) || '—';

  const isTrack = np.track && (np.type === 'track' || np.type === 'music');
  let trackName = '—', artistName = '', albumName = '', artUrl = '', trackId = '';

  if (isTrack) {
    trackName = np.track.name || '—';
    artistName = (np.track.artist && np.track.artist.name) || '';
    albumName = (np.album && np.album.name) || '';
    trackId = np.track.id || trackName;
  } else if (np.radio) {
    trackName = np.radio.name || '—';
    artistName = np.radio.genre || '';
    trackId = np.radio.name;
  }

  if (np.artwork && np.artwork.proxy_512) artUrl = fixHost(np.artwork.proxy_512);
  else if (np.artwork && np.artwork.proxy_320) artUrl = fixHost(np.artwork.proxy_320);

  if (trackId !== lastTrackId) {
    lastTrackId = trackId;
    lastLyricsKey = null;
    if (openPanel === 'queue') setTimeout(loadQueue, 600);
    const meta = document.getElementById('meta');
    meta.style.opacity = '0';
    setTimeout(() => {
      setMarquee(trackName);
      document.getElementById('artist-name').textContent = artistName;
      document.getElementById('album-name').textContent = albumName;
      document.getElementById('lyrics-title').textContent = artistName ? trackName + ' · ' + artistName : trackName;
      meta.style.opacity = '1';
    }, 250);
    setArtwork(artUrl, np.artwork || null);
  } else if (artUrl && artUrl !== currentArtUrl) {
    setArtwork(artUrl, np.artwork || null);
  }

  const newLyricsKey = trackId + ':' + ((np.lyrics && np.lyrics.synced) ? 'synced' : (np.lyrics && np.lyrics.plain) ? 'plain' : 'none');
  if (newLyricsKey !== lastLyricsKey) {
    lastLyricsKey = newLyricsKey;
    setLyrics(np.lyrics || null);
  }

  setWaveform(np.waveform || null);
  setModes(np.modes || null);

  currentDuration = (np.track && np.track.duration) || 0;
  if (np.position !== undefined) currentProgress = np.position;
  progressLastPollTime = Date.now();
  isCurrentlyPlaying = (state === 'playing');
  document.body.classList.toggle('is-playing', isCurrentlyPlaying);

  updateProgress();
}

function hexToRgba(hex, alpha) {
  const r = parseInt(hex.slice(1,3),16), g = parseInt(hex.slice(3,5),16), b = parseInt(hex.slice(5,7),16);
  return `rgba(${r},${g},${b},${alpha})`;
}

// Lift a hex color to a minimum HSL lightness, preserving hue + saturation.
function ensureL(hex, minL) {
  const r = parseInt(hex.slice(1,3),16)/255;
  const g = parseInt(hex.slice(3,5),16)/255;
  const b = parseInt(hex.slice(5,7),16)/255;
  const max = Math.max(r,g,b), min = Math.min(r,g,b), d = max - min;
  let h = 0, s = 0, l = (max + min) / 2;
  if (d > 0) {
    s = d / (1 - Math.abs(2 * l - 1));
    if (max === r)      h = (((g - b) / d) % 6) / 6;
    else if (max === g) h = ((b - r) / d + 2) / 6;
    else                h = ((r - g) / d + 4) / 6;
    if (h < 0) h += 1;
  }
  if (l >= minL) return hex;
  l = minL;
  const c = (1 - Math.abs(2 * l - 1)) * s;
  const x = c * (1 - Math.abs((h * 6) % 2 - 1));
  const m = l - c / 2;
  let ro, go, bo;
  const hi = Math.floor(h * 6) % 6;
  if      (hi === 0) { ro = c; go = x; bo = 0; }
  else if (hi === 1) { ro = x; go = c; bo = 0; }
  else if (hi === 2) { ro = 0; go = c; bo = x; }
  else if (hi === 3) { ro = 0; go = x; bo = c; }
  else if (hi === 4) { ro = x; go = 0; bo = c; }
  else               { ro = c; go = 0; bo = x; }
  const h2 = v => Math.min(255, Math.round((v + m) * 255)).toString(16).padStart(2, '0');
  return `#${h2(ro)}${h2(go)}${h2(bo)}`;
}

function setMarquee(text) {
  const el = document.getElementById('track-name-inner');
  const container = document.getElementById('track-name');
  el.classList.remove('marquee');
  el.style.removeProperty('--marquee-dist');
  el.style.removeProperty('animation-duration');
  el.textContent = text;
  document.fonts.ready.then(() => requestAnimationFrame(() => {
    const overflow = el.scrollWidth - container.clientWidth;
    if (overflow > 10) {
      const duration = Math.max(8, overflow / 40);
      el.style.setProperty('--marquee-dist', `-${overflow + 60}px`);
      el.style.animationDuration = `${duration}s`;
      el.classList.add('marquee');
    }
  }));
}

function setArtwork(url, artwork) {
  const colors = artwork && artwork.colors;
  if (url && url !== currentArtUrl) {
    const next = activeArt === 'a' ? 'b' : 'a';
    const awNext = document.getElementById('aw-' + next);
    const bgNext = document.getElementById('bg-' + next);
    const awCurr = document.getElementById('aw-' + activeArt);
    const bgCurr = document.getElementById('bg-' + activeArt);
    awNext.onload = () => {
      bgNext.style.backgroundImage = `url('${url}')`;
      awNext.style.opacity = '1';
      bgNext.style.opacity = '1';
      awCurr.style.opacity = '0';
      bgCurr.style.opacity = '0';
      activeArt = next;
      currentArtUrl = url;
    };
    awNext.onerror = () => {};
    awNext.src = url;
  } else if (!url) {
    ['a', 'b'].forEach(s => {
      document.getElementById('aw-' + s).style.opacity = '0';
      document.getElementById('bg-' + s).style.opacity = '0';
    });
    currentArtUrl = '';
  }
  if (colors && colors.length >= 2) {
    const src    = (artwork && artwork.safe_colors) || null;
    const accent = src ? src[1]            : ensureL(colors[1], 0.58);
    const muted  = src ? (src[2] || src[1]): ensureL(colors[2] || colors[1], 0.68);
    setAccent(accentFor(colors, src || []), colors);
    document.getElementById('artwork-wrap').style.setProperty('--vinyl-label', accent);
    document.getElementById('state-dot').style.background = accent;
    document.getElementById('artist-name').style.color = hexToRgba(muted, 0.9);
    document.getElementById('album-name').style.color  = hexToRgba(muted, 0.55);
    document.getElementById('lyric-curr').style.color  = hexToRgba(accent, 0.92);
    document.getElementById('lyric-prev').style.color  = hexToRgba(muted, 0.28);
    document.getElementById('lyric-next').style.color  = hexToRgba(muted, 0.28);
  } else {
    setAccent(null, null);
    document.getElementById('state-dot').style.background = 'rgba(255,255,255,0.2)';
    document.getElementById('artist-name').style.color = 'rgba(255,255,255,0.6)';
    document.getElementById('album-name').style.color  = 'rgba(255,255,255,0.35)';
    document.getElementById('lyric-curr').style.color  = 'rgba(255,255,255,0.92)';
    document.getElementById('lyric-prev').style.color  = 'rgba(255,255,255,0.22)';
    document.getElementById('lyric-next').style.color  = 'rgba(255,255,255,0.22)';
  }
}

function currentPosition() {
  if (isCurrentlyPlaying && progressLastPollTime) {
    return Math.min(currentDuration, currentProgress + (Date.now() - progressLastPollTime) / 1000);
  }
  return currentProgress;
}

function updateProgress() {
  if (currentDuration > 0) {
    const pos = currentPosition();
    const pct = Math.min(100, (pos / currentDuration) * 100);
    document.getElementById('progress-bar-fill').style.width = pct + '%';
    setPlayed(pct);
    document.getElementById('time-pos').textContent = fmtTime(pos);
    document.getElementById('time-dur').textContent = fmtTime(currentDuration);
  } else {
    document.getElementById('progress-bar-fill').style.width = '0%';
    setPlayed(0);
    document.getElementById('time-pos').textContent = '—';
    document.getElementById('time-dur').textContent = '—';
  }
}

// ── Bar: accent, waveform, shuffle/repeat (same look as the sticky player bar) ──
// Port of App\Domain\Artwork\AccentColor (the sticky bar's accent): the most vivid light safe colour whose hue
// is not the background's own, else the background's complementary hue.
function hexToHsl(hex) {
  const m = /^#?([0-9a-f]{6})$/i.exec((hex || '').trim());
  if (!m) return null;
  const r = parseInt(m[1].slice(0,2),16)/255, g = parseInt(m[1].slice(2,4),16)/255, b = parseInt(m[1].slice(4,6),16)/255;
  const max = Math.max(r,g,b), min = Math.min(r,g,b), l = (max + min) / 2;
  if (max === min) return [0, 0, l];
  const d = max - min, s = l > 0.5 ? d / (2 - max - min) : d / (max + min);
  const h = max === r ? (g - b) / d + (g < b ? 6 : 0) : (max === g ? (b - r) / d + 2 : (r - g) / d + 4);
  return [h * 60, s, l];
}

function hslToHex(h, s, l) {
  const c = (1 - Math.abs(2 * l - 1)) * s, x = c * (1 - Math.abs((h / 60) % 2 - 1)), m = l - c / 2;
  const rgb = h < 60 ? [c,x,0] : h < 120 ? [x,c,0] : h < 180 ? [0,c,x] : h < 240 ? [0,x,c] : h < 300 ? [x,0,c] : [c,0,x];
  return '#' + rgb.map(v => Math.round((v + m) * 255).toString(16).padStart(2, '0')).join('');
}

function hueDistance(a, b) { const d = Math.abs(a - b); return d > 180 ? 360 - d : d; }

function accentFor(colors, safeColors) {
  const base = colors && colors[0] ? hexToHsl(colors[0]) : null;
  if (!base) return '#ffffff';
  let best = null;
  (safeColors || []).forEach(hex => {
    const hsl = hexToHsl(hex);
    if (!hsl || hsl[1] < 0.35 || hueDistance(hsl[0], base[0]) < 40) return;
    if (!best || hsl[1] > best[1]) best = hsl;
  });
  if (best) return hslToHex(best[0], best[1], Math.max(best[2], 0.6));
  if (base[1] < 0.15) return '#ffffff';
  return hslToHex((base[0] + 180) % 360, Math.max(base[1], 0.6), 0.62);
}

function setAccent(accent, colors) {
  const root = document.documentElement.style;
  root.setProperty('--accent', accent || '#e8a46b');
  if (accent) root.setProperty('--title', accent); else root.removeProperty('--title');
  if (colors && colors.length >= 2) root.setProperty('--bar-bg', `linear-gradient(100deg, ${colors[0]} 0%, ${colors[1]} 100%)`);
  else root.removeProperty('--bar-bg');
}

let currentWaveKey = null;
function waveSvg(points) {
  const h = 32, mid = h / 2;
  let d = '';
  points.forEach((peak, i) => {
    const half = Math.max(0.5, peak / 100 * mid);
    d += `M${i + 0.5} ${(mid - half).toFixed(1)}V${(mid + half).toFixed(1)}`;
  });
  return `<svg viewBox="0 0 ${points.length} ${h}" preserveAspectRatio="none"><path d="${d}" stroke="currentColor" stroke-width="0.7" stroke-linecap="round" fill="none"/></svg>`;
}

function setWaveform(points) {
  const key = points ? points.length + ':' + points[0] + ':' + points[points.length >> 1] : null;
  if (key === currentWaveKey) return;
  currentWaveKey = key;
  const bg = document.getElementById('progress-bar-bg');
  bg.classList.toggle('has-wave', !!points);
  const svg = points ? waveSvg(points) : '';
  document.getElementById('wf-base').innerHTML = svg;
  document.getElementById('wf-played').innerHTML = svg;
}

function setPlayed(pct) {
  const clip = 'inset(0 ' + (100 - pct) + '% 0 0)';
  const el = document.getElementById('wf-played');
  el.style.webkitClipPath = clip;
  el.style.clipPath = clip;
}

let currentShuffle = false, currentRepeat = 'off', currentLiked = false;
function setModes(modes) {
  currentShuffle = !!(modes && modes.shuffle);
  currentRepeat = (modes && modes.repeat) || 'off';
  currentLiked = !!(modes && modes.liked);
  updateModesUI();
}

function updateModesUI() {
  document.getElementById('btn-like').classList.toggle('active', currentLiked);
  document.getElementById('btn-like').title = currentLiked ? 'Remove from liked' : 'Like';
  document.getElementById('heart-path').setAttribute('fill', currentLiked ? 'currentColor' : 'none');
  document.getElementById('btn-shuffle').classList.toggle('on', currentShuffle);
  document.getElementById('btn-repeat').classList.toggle('on', currentRepeat !== 'off');
  document.getElementById('repeat-one').style.display = currentRepeat === 'one' ? 'block' : 'none';
  document.getElementById('btn-repeat').title = 'Repeat: ' + currentRepeat;
}

async function putMode(path, body) {
  try {
    await fetch(`/api/devices/${deviceId}/${path}`, {
      method: 'PUT',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify(body),
    });
  } catch(e) {}
  setTimeout(pollNow, 400);
}

function toggleShuffle() {
  resetAmbient();
  currentShuffle = !currentShuffle;
  updateModesUI();
  putMode('shuffle', { shuffle: currentShuffle });
}

function toggleLike() {
  resetAmbient();
  currentLiked = !currentLiked;
  updateModesUI();
  putMode('like', { liked: currentLiked });
}

function cycleRepeat() {
  resetAmbient();
  currentRepeat = currentRepeat === 'off' ? 'all' : (currentRepeat === 'all' ? 'one' : 'off');
  updateModesUI();
  putMode('repeat', { repeat: currentRepeat });
}

function fmtTime(s) {
  s = Math.max(0, Math.round(s));
  return `${Math.floor(s / 60)}:${(s % 60).toString().padStart(2, '0')}`;
}

function updatePlayPauseIcon(state) {
  const playing = state === 'playing';
  document.getElementById('icon-play').style.display  = playing ? 'none'  : 'block';
  document.getElementById('icon-pause').style.display = playing ? 'block' : 'none';
}

async function sendAction(action) {
  try {
    await fetch(`/api/devices/${deviceId}/${action}`, { method: 'POST' });
    setTimeout(pollNow, 400);
  } catch(e) {}
}

function togglePlayPause() {
  const label = document.getElementById('state-label').textContent;
  sendAction(label === 'playing' ? 'pause' : 'play');
}

function toggleFullscreen() {
  if (!document.fullscreenElement) {
    document.documentElement.requestFullscreen().catch(() => {});
  } else {
    document.exitFullscreen().catch(() => {});
  }
}

document.addEventListener('fullscreenchange', () => {
  const fs = !!document.fullscreenElement;
  document.getElementById('icon-expand').style.display   = fs ? 'none'  : 'block';
  document.getElementById('icon-compress').style.display = fs ? 'block' : 'none';
});

// ── Idle clock ──────────────────────────────────────────────
const DAYS   = ['Sunday','Monday','Tuesday','Wednesday','Thursday','Friday','Saturday'];
const MONTHS = ['January','February','March','April','May','June','July','August','September','October','November','December'];

function updateClock() {
  const now = new Date();
  const hh = now.getHours().toString().padStart(2, '0');
  const mm = now.getMinutes().toString().padStart(2, '0');
  document.getElementById('idle-time').textContent = `${hh}:${mm}`;
  document.getElementById('idle-date').textContent =
    `${DAYS[now.getDay()]}, ${MONTHS[now.getMonth()]} ${now.getDate()}`;
}

function showIdle(on) {
  document.getElementById('idle-screen').classList.toggle('visible', on);
}

setInterval(updateClock, 1000);
updateClock();

// ── Ambient mode ─────────────────────────────────────────────
function resetAmbient() {
  const wasAmbient = document.body.classList.contains('ambient');
  document.body.classList.remove('ambient', 'ambient-plain');
  clearTimeout(ambientTimer);
  if (wasAmbient && ambientSwitchedToLyrics && lyricsViewActive) {
    ambientSwitchedToLyrics = false;
    toggleLyricsView();
  }
  if (isCurrentlyPlaying) {
    ambientTimer = setTimeout(() => {
      document.body.classList.add('ambient');
      if (!currentLyricsData) document.body.classList.add('ambient-plain');
      if (currentLyricsData && !lyricsViewActive) {
        ambientSwitchedToLyrics = true;
        toggleLyricsView();
      }
    }, 30000);
  }
}

document.addEventListener('mousemove', resetAmbient);
document.addEventListener('touchstart', resetAmbient, { passive: true });
document.addEventListener('keydown', resetAmbient);

// ── Volume ───────────────────────────────────────────────────
async function fetchVolume() {
  try {
    const r = await fetch(`/api/devices/${deviceId}/volume`);
    if (!r.ok) return;
    const j = await r.json();
    currentVolume = j.volume != null ? j.volume : 50;
    updateVolumeUI();
  } catch(e) {}
}

function updateVolumeUI() {
  document.getElementById('vol-slider').value = currentVolume;
  document.getElementById('vol-val').textContent = currentVolume;
}

async function commitVolume(v) {
  try {
    await fetch(`/api/devices/${deviceId}/volume`, {
      method: 'PUT',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ volume: v }),
    });
  } catch(e) {}
}

function setVolume(v) {
  v = Math.max(0, Math.min(100, Math.round(v)));
  currentVolume = v;
  updateVolumeUI();
  clearTimeout(volumeDebounce);
  volumeDebounce = setTimeout(() => commitVolume(v), 200);
}

document.getElementById('vol-slider').addEventListener('input', e => {
  setVolume(parseInt(e.target.value));
});

document.addEventListener('wheel', e => {
  if (!deviceId || !showControls) return;
  e.preventDefault();
  resetAmbient();
  setVolume(currentVolume + (e.deltaY < 0 ? 5 : -5));
}, { passive: false });

document.getElementById('progress-bar-bg').addEventListener('click', async e => {
  if (!deviceId || !deviceCaps.includes('seek') || currentDuration <= 0) return;
  e.stopPropagation();
  const rect = e.currentTarget.getBoundingClientRect();
  const position = Math.round(Math.min(1, Math.max(0, (e.clientX - rect.left) / rect.width)) * currentDuration);
  currentProgress = position;
  progressLastPollTime = Date.now();
  updateProgress();
  try {
    await fetch(`/api/devices/${deviceId}/seek`, {
      method: 'PUT',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ position }),
    });
  } catch(e) {}
});

// ── Mute ─────────────────────────────────────────────────────
async function fetchMute() {
  try {
    const r = await fetch(`/api/devices/${deviceId}/mute`);
    if (!r.ok) return;
    currentMuted = !!(await r.json()).muted;
    updateMuteUI();
  } catch(e) {}
}

function updateMuteUI() {
  document.getElementById('vol-row').classList.toggle('muted', currentMuted);
  document.getElementById('vol-mute').title = currentMuted ? 'Unmute' : 'Mute';
}

async function toggleMute() {
  resetAmbient();
  currentMuted = !currentMuted;
  updateMuteUI();
  try {
    await fetch(`/api/devices/${deviceId}/mute`, {
      method: 'PUT',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ muted: currentMuted }),
    });
  } catch(e) {}
}

// ── Up next (queue, queue_jump) ──────────────────────────────
let openPanel = null;
const PANELS = {
  queue:   { el: 'queue-panel',  btn: 'btn-queue',   load: () => loadQueue() },
  multi:   { el: 'multi-panel',  btn: 'btn-multi',   load: () => loadMulti() },
  devices: { el: 'switch-panel', btn: 'btn-devices', load: () => buildSwitcher() },
};

// One panel at a time floats above the bar; `togglePanel(null, null, false)` closes them all.
function togglePanel(name, e, force) {
  if (e) { e.stopPropagation(); resetAmbient(); }
  const open = force === false ? null : (force === true || openPanel !== name ? name : null);
  openPanel = open;
  document.getElementById('bar').classList.toggle('panel-open', !!open);
  Object.keys(PANELS).forEach(k => {
    const on = k === open;
    document.getElementById(PANELS[k].el).classList.toggle('open', on);
    document.getElementById(PANELS[k].btn).classList.toggle('active', on);
  });
  if (open) {
    const panel = document.getElementById(PANELS[open].el);
    const bar = document.getElementById('bar').getBoundingClientRect();
    panel.style.right = Math.max(0, window.innerWidth - bar.right) + 'px';
    // the same slice of the artwork gradient as the bar's right end, so panel and bar read as one
    panel.style.backgroundSize = bar.width + 'px 100%';
    panel.style.backgroundPosition = 'right top';
    panel.style.backgroundRepeat = 'no-repeat';
    panel.style.bottom = (window.innerHeight - bar.top - 16) + 'px'; // 16px behind the bar
    PANELS[open].load();
  }
}

const NOTE_ICON = '<svg viewBox="0 0 24 24" width="40" height="40" fill="none" stroke="rgba(255,255,255,.4)" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"><path d="M9 18V6l10-2v12"/><circle cx="6.5" cy="18" r="2.5"/><circle cx="16.5" cy="16" r="2.5"/></svg>';

function qEsc(t) {
  return String(t == null ? '' : t).replace(/[&<>"]/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' }[c]));
}

function qArt(a) {
  const u = a && (a.proxy_120 || a.proxy_320);
  return '<span class="q-art">' + (u ? '<img src="' + qEsc(fixHost(u)) + '" alt="" loading="lazy">' : NOTE_ICON) + '</span>';
}

async function loadQueue() {
  if (!deviceId || openPanel !== 'queue') return;
  const canJump = deviceCaps.includes('queue_jump');
  const now = document.getElementById('track-name-inner').textContent;
  document.getElementById('queue-now').innerHTML = (currentArtUrl || now !== '—')
    ? '<div class="q-row now"><span class="eq"><i></i><i></i><i></i></span><span class="q-art">' + (currentArtUrl ? '<img src="' + qEsc(currentArtUrl) + '" alt="">' : '') + '</span><span class="q-txt"><span class="q-name">' + qEsc(now) + '</span><span class="q-sub">' + qEsc(document.getElementById('artist-name').textContent) + '</span></span></div>'
    : '';
  const msg = document.getElementById('queue-msg'), list = document.getElementById('queue-list');
  try {
    const r = await fetch(`/api/devices/${deviceId}/queue?limit=50`);
    if (!r.ok) throw new Error('HTTP ' + r.status);
    const items = (await r.json()).up_next || [];
    msg.textContent = items.length ? '' : 'Nothing queued after the current track.';
    list.innerHTML = items.map((it, i) => {
      const sub = [it.artist, it.album].filter(Boolean).join(' · ');
      return '<li><' + (canJump ? 'button type="button" data-pos="' + (i + 1) + '" title="Play from here"' : 'div') + ' class="q-row">'
        + '<span class="q-n">' + (i + 1) + '</span>' + qArt(it.artwork)
        + '<span class="q-txt"><span class="q-name">' + qEsc(it.name) + '</span>' + (sub ? '<span class="q-sub">' + qEsc(sub) + '</span>' : '') + '</span>'
        + (it.duration ? '<span class="q-dur">' + fmtTime(it.duration) + '</span>' : '')
        + '</' + (canJump ? 'button' : 'div') + '></li>';
    }).join('');
  } catch(e) {
    list.innerHTML = '';
    msg.textContent = 'Could not read the queue from the device.';
  }
}

document.getElementById('queue-list').addEventListener('click', async e => {
  const row = e.target.closest('[data-pos]');
  if (!row) return;
  try {
    await fetch(`/api/devices/${deviceId}/queue/jump`, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ position: parseInt(row.getAttribute('data-pos')) }),
    });
  } catch(err) {}
  setTimeout(pollNow, 400);
});

// ── Device switcher ──────────────────────────────────────────
const STATE_COLORS = { playing: '#4ade80', paused: '#fbbf24', standby: '#ef4444', unreachable: '#6b7280' };

function buildSwitcher() {
  const body = document.getElementById('switch-body');
  body.innerHTML = '';
  allDevices.forEach(d => {
    const btn = document.createElement('button');
    btn.className = 'q-row' + (d.id === deviceId ? ' active' : '');
    const color = STATE_COLORS[d.state] || STATE_COLORS.unreachable;
    btn.innerHTML = '<span class="switch-dot" style="background:' + color + '"></span><span class="q-txt"><span class="q-name">' + qEsc(d.device_name) + '</span><span class="q-sub">' + qEsc(d.device_product_type || '') + '</span></span>';
    if (d.id !== deviceId) {
      btn.onclick = () => { togglePanel(null, null, false); startReceiver(d); };
    }
    body.appendChild(btn);
  });
}

// ── Multiroom ────────────────────────────────────────────────
function multiRow(d, action) {
  const color = STATE_COLORS[d.state] || STATE_COLORS.unreachable;
  return '<' + (action ? 'button type="button" data-host="' + d.id + '"' : 'div') + ' class="q-row"><span class="switch-dot" style="background:' + color + '"></span><span class="q-txt"><span class="q-name">' + qEsc(d.device_name) + '</span><span class="q-sub">' + (action ? 'Join' : 'Listening here') + '</span></span></' + (action ? 'button' : 'div') + '>';
}

async function loadMulti() {
  if (!deviceId || openPanel !== 'multi') return;
  const msg = document.getElementById('multi-msg'), body = document.getElementById('multi-body');
  try {
    const r = await fetch(`/api/devices/${deviceId}/multiroom`);
    if (!r.ok) throw new Error('HTTP ' + r.status);
    const j = await r.json();
    const joinable = (j.joinable || []).filter(d => d.id !== deviceId);
    const listeners = j.listeners || [];
    msg.textContent = (joinable.length || listeners.length) ? '' : 'No other player is playing to join.';
    body.innerHTML = (joinable.length ? '<div class="q-section">Join a player</div>' + joinable.map(d => multiRow(d, true)).join('') : '')
      + (listeners.length ? '<div class="q-section">In this group</div>' + listeners.map(d => multiRow(d, false)).join('') : '')
      + '<button type="button" class="q-row" data-leave="1"><span class="q-txt"><span class="q-name">Leave group</span></span></button>';
  } catch(e) {
    body.innerHTML = '';
    msg.textContent = 'Could not read multiroom from the device.';
  }
}

document.getElementById('multi-body').addEventListener('click', async e => {
  const host = e.target.closest('[data-host]'), leave = e.target.closest('[data-leave]');
  if (!host && !leave) return;
  try {
    await fetch(`/api/devices/${deviceId}/multiroom/` + (host ? 'join' : 'leave'), host
      ? { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ host_device_id: parseInt(host.getAttribute('data-host')) }) }
      : { method: 'DELETE' });
  } catch(err) {}
  setTimeout(() => { loadMulti(); pollNow(); }, 500);
});

// Outside click / Escape close the open panel
document.addEventListener('click', () => togglePanel(null, null, false));
document.addEventListener('keydown', e => { if (e.key === 'Escape') togglePanel(null, null, false); });

// Opened from a page of this site (the sticky bar): offer the way back
try {
  if (document.referrer && new URL(document.referrer).origin === location.origin && history.length > 1) {
    document.getElementById('btn-back').style.display = 'block';
  }
} catch(e) {}

// Easter egg: ↑↑↓↓←→←→BA toggles vinyl mode.
const KONAMI = ['ArrowUp', 'ArrowUp', 'ArrowDown', 'ArrowDown', 'ArrowLeft', 'ArrowRight', 'ArrowLeft', 'ArrowRight', 'b', 'a'];
let konamiIdx = 0;
document.addEventListener('keydown', e => {
  const key = e.key.length === 1 ? e.key.toLowerCase() : e.key;
  konamiIdx = key === KONAMI[konamiIdx] ? konamiIdx + 1 : (key === KONAMI[0] ? 1 : 0);
  if (konamiIdx === KONAMI.length) {
    konamiIdx = 0;
    document.body.classList.toggle('vinyl');
  }
});

console.log('%c♪ ReMoment%c\nLooking under the hood? The REST API lives at /api, no auth needed.\nTry ↑↑↓↓←→←→BA here.',
  'font: 600 20px monospace; color: #e879f9', 'font: 12px monospace; color: #9ca3af');

setInterval(() => { updateProgress(); syncLyricsLine(); }, 1000);
init();
</script>
</body>
</html>
