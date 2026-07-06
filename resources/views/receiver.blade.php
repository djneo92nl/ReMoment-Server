<!DOCTYPE html>
<html>
<head>
<meta charset="utf-8">
<title>ReMoment Receiver</title>
<link href="https://fonts.googleapis.com/css2?family=DM+Sans:wght@300;400;500&family=DM+Mono:wght@300;400&display=swap" rel="stylesheet">
<style>
  * { margin: 0; padding: 0; box-sizing: border-box; }

  body {
    background: #0a0a0a;
    color: #fff;
    font-family: 'DM Sans', sans-serif;
    height: 100vh;
    width: 100vw;
    overflow: hidden;
    display: flex;
    flex-direction: column;
  }

  #bg-art {
    position: absolute;
    inset: 0;
    background-size: cover;
    background-position: center;
    filter: blur(80px) brightness(0.18) saturate(1.4);
    transform: scale(1.1);
    transition: background-image 1.5s ease;
    z-index: 0;
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
    border-radius: 10px;
    overflow: hidden;
    background: #1a1a1a;
    position: relative;
  }

  #artwork {
    width: 100%;
    height: 100%;
    object-fit: cover;
    transition: opacity 0.6s ease;
  }

  #artwork.loading { opacity: 0; }

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
  }

  #platform {
    font-family: 'DM Mono', monospace;
    font-size: 14px;
    font-weight: 300;
    letter-spacing: 0.15em;
    text-transform: uppercase;
    color: rgba(255,255,255,0.35);
  }

  #track-name {
    font-size: 45px;
    font-weight: 500;
    line-height: 1.15;
    overflow: hidden;
    color: #fff;
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
    font-size: 25px;
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
    font-family: 'DM Mono', monospace;
    font-size: 14px;
    color: rgba(255,255,255,0.2);
    letter-spacing: 0.08em;
    margin-top: 8px;
  }

  #state-badge {
    display: inline-flex;
    align-items: center;
    gap: 6px;
    font-family: 'DM Mono', monospace;
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

  #progress-wrap {
    position: relative;
    z-index: 1;
    padding: 0 80px 50px;
    max-width: 1200px;
    margin: 0 auto;
    width: 100%;
  }

  #progress-bar-bg {
    height: 4px;
    background: rgba(255,255,255,0.08);
    border-radius: 2px;
    overflow: hidden;
  }

  #progress-bar-fill {
    height: 100%;
    background: rgba(255,255,255,0.5);
    border-radius: 2px;
    width: 0%;
    transition: width 1s linear;
  }

  #time-row {
    display: flex;
    justify-content: space-between;
    margin-top: 12px;
    font-family: 'DM Mono', monospace;
    font-size: 14px;
    font-weight: 300;
    color: rgba(255,255,255,0.25);
    letter-spacing: 0.05em;
  }

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
    font-family: 'DM Mono', monospace;
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
    font-family: 'DM Mono', monospace;
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
    font-family: 'DM Mono', monospace;
    font-size: 13px;
    color: rgba(255,255,255,0.3);
    min-height: 16px;
    text-align: center;
  }

  #controls {
    display: none;
    position: relative;
    z-index: 1;
    justify-content: center;
    align-items: center;
    gap: 32px;
    padding: 0 80px 36px;
  }

  #controls.visible { display: flex; }

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

  .ctrl-btn {
    background: none;
    border: none;
    cursor: pointer;
    padding: 12px;
    border-radius: 50%;
    display: flex;
    align-items: center;
    justify-content: center;
    transition: background 0.15s, transform 0.1s;
    -webkit-tap-highlight-color: transparent;
  }

  .ctrl-btn:hover { background: rgba(255,255,255,0.08); }
  .ctrl-btn:active { transform: scale(0.92); background: rgba(255,255,255,0.12); }
  .ctrl-btn svg { display: block; }
  .ctrl-btn.primary { padding: 18px; background: rgba(255,255,255,0.1); }
  .ctrl-btn.primary:hover { background: rgba(255,255,255,0.18); }
</style>
</head>
<body>

<div id="picker-panel">
  <h1>remoment receiver</h1>
  <div id="picker-devices"></div>
  <div id="picker-status">Loading…</div>
</div>

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

<div id="bg-art"></div>

<div id="main">
  <div id="artwork-wrap">
    <div id="art-placeholder">
      <svg width="48" height="48" viewBox="0 0 24 24" fill="none" stroke="white" stroke-width="1">
        <circle cx="12" cy="12" r="10"/><circle cx="12" cy="12" r="3"/>
        <line x1="12" y1="2" x2="12" y2="5"/><line x1="12" y1="19" x2="12" y2="22"/>
      </svg>
    </div>
    <img id="artwork" class="loading" src="" alt="" onerror="this.classList.add('loading')">
  </div>

  <div id="meta">
    <div id="platform">—</div>
    <div id="track-name"><span id="track-name-inner">—</span></div>
    <div id="artist-name">—</div>
    <div id="album-name"></div>
    <div id="device-name"></div>
    <div id="state-badge"><span id="state-dot"></span><span id="state-label">idle</span></div>
  </div>
</div>

<div id="progress-wrap">
  <div id="progress-bar-bg">
    <div id="progress-bar-fill"></div>
  </div>
  <div id="time-row">
    <span id="time-pos">0:00</span>
    <span id="time-dur">—</span>
  </div>
</div>

<div id="controls">
  <button class="ctrl-btn" onclick="sendAction('previous')" title="Previous">
    <svg width="28" height="28" viewBox="0 0 24 24" fill="none" stroke="white" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round">
      <polygon points="19 20 9 12 19 4 19 20"/><line x1="5" y1="4" x2="5" y2="20"/>
    </svg>
  </button>
  <button class="ctrl-btn primary" id="btn-playpause" onclick="togglePlayPause()" title="Play/Pause">
    <svg id="icon-play" width="32" height="32" viewBox="0 0 24 24" fill="white" stroke="none">
      <polygon points="5 3 19 12 5 21 5 3"/>
    </svg>
    <svg id="icon-pause" width="32" height="32" viewBox="0 0 24 24" fill="white" stroke="none" style="display:none">
      <rect x="6" y="4" width="4" height="16"/><rect x="14" y="4" width="4" height="16"/>
    </svg>
  </button>
  <button class="ctrl-btn" onclick="sendAction('next')" title="Next">
    <svg width="28" height="28" viewBox="0 0 24 24" fill="none" stroke="white" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round">
      <polygon points="5 4 15 12 5 20 5 4"/><line x1="19" y1="4" x2="19" y2="20"/>
    </svg>
  </button>
</div>

<script>
const isChromecast = /CrKey/i.test(navigator.userAgent);
const hasTouch = 'ontouchstart' in window || navigator.maxTouchPoints > 0;
const isTV = /TV|SmartTV|Tizen|WebOS|HbbTV|BRAVIA/i.test(navigator.userAgent);
const showControls = !isChromecast && (hasTouch || isTV);

let deviceId = null;
let pollTimer = null;
let currentProgress = 0, currentDuration = 0, currentEndTime = null;
let lastTrackId = null;

const params = new URLSearchParams(window.location.search);
const paramDeviceId = params.get('device') ? parseInt(params.get('device')) : null;

async function init() {
  try {
    const r = await fetch('/api/devices');
    if (!r.ok) throw new Error(`HTTP ${r.status}`);
    const json = await r.json();
    const devices = json.data ?? json;

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
  deviceId = device.id;
  document.getElementById('picker-panel').style.display = 'none';
  const label = [device.device_name, device.device_product_type].filter(Boolean).join(' · ');
  document.getElementById('device-name').textContent = label;
  if (showControls) document.getElementById('controls').classList.add('visible');
  pollNow();
  pollTimer = setInterval(pollNow, 3000);
}

async function pollNow() {
  try {
    const r = await fetch(`/api/devices/${deviceId}`);
    if (!r.ok) return;
    const json = await r.json();
    updateUI(json.data ?? json);
  } catch(e) {}
}

function fixHost(url) {
  if (!url) return '';
  return url.replace(/https?:\/\/localhost(:\d+)?/, window.location.origin);
}

function updateUI(d) {
  const np = d.now_playing;
  const dot = document.getElementById('state-dot');
  const label = document.getElementById('state-label');

  if (!np || d.state === 'Unreachable') {
    dot.className = '';
    label.textContent = 'unreachable';
    return;
  }

  const state = np.state || d.state || 'idle';
  dot.className = state === 'playing' ? 'playing' : '';
  label.textContent = state;
  if (showControls) updatePlayPauseIcon(state);

  document.getElementById('platform').textContent = np.platform || np.source?.name || '—';

  function extractImgUrl(arr) {
    if (!arr?.length) return '';
    const item = arr[0];
    return typeof item === 'string' ? item : (item?.url || '');
  }

  const isTrack = np.track && (np.type === 'track' || np.type === 'music');
  let trackName = '—', artistName = '', albumName = '', artUrl = '', trackId = '';

  if (isTrack) {
    trackName = np.track.name || '—';
    artistName = np.track.artist?.name || '';
    albumName = np.album?.name || '';
    trackId = np.track.id || trackName;
  } else if (np.radio) {
    trackName = np.radio.name || '—';
    artistName = np.radio.genre || '';
    trackId = np.radio.name;
  }

  setMarquee(trackName);
  document.getElementById('artist-name').textContent = artistName;
  document.getElementById('album-name').textContent = albumName;

  if (np.artwork?.proxy_512) artUrl = fixHost(np.artwork.proxy_512);
  else if (np.artwork?.proxy_320) artUrl = fixHost(np.artwork.proxy_320);
  else if (isTrack && np.track?.images?.length) artUrl = extractImgUrl(np.track.images);
  else if (np.album?.images?.length) artUrl = extractImgUrl(np.album.images);
  else if (np.radio?.images?.length) artUrl = extractImgUrl(np.radio.images);

  if (trackId !== lastTrackId) {
    lastTrackId = trackId;
    setArtwork(artUrl, np.artwork?.colors);
  } else if (artUrl && document.getElementById('artwork').src !== artUrl) {
    setArtwork(artUrl, np.artwork?.colors);
  }

  currentDuration = np.track?.duration || 0;
  currentEndTime = np.endTime ? new Date(np.endTime.replace(' ', 'T')) : null;
  if (np.position !== undefined) currentProgress = np.position;

  updateProgress();
}

function hexToRgba(hex, alpha) {
  const r = parseInt(hex.slice(1,3),16), g = parseInt(hex.slice(3,5),16), b = parseInt(hex.slice(5,7),16);
  return `rgba(${r},${g},${b},${alpha})`;
}

function setMarquee(text) {
  const el = document.getElementById('track-name-inner');
  const container = document.getElementById('track-name');
  el.classList.remove('marquee');
  el.style.removeProperty('--marquee-dist');
  el.style.removeProperty('animation-duration');
  el.textContent = text;
  requestAnimationFrame(() => {
    const overflow = el.scrollWidth - container.clientWidth;
    if (overflow > 10) {
      const duration = Math.max(6, overflow / 40);
      el.style.setProperty('--marquee-dist', `-${overflow}px`);
      el.style.animationDuration = `${duration}s`;
      el.classList.add('marquee');
    }
  });
}

function setArtwork(url, colors) {
  const img = document.getElementById('artwork');
  const bg = document.getElementById('bg-art');
  if (url) {
    img.classList.add('loading');
    img.onload = () => { img.classList.remove('loading'); };
    img.src = url;
    bg.style.backgroundImage = `url('${url}')`;
  } else {
    img.classList.add('loading');
    img.src = '';
    bg.style.backgroundImage = '';
  }
  if (colors?.length >= 2) {
    const accent = colors[1];
    const muted  = colors[2] || colors[1];
    document.getElementById('progress-bar-fill').style.background = accent;
    document.getElementById('state-dot').style.background = accent;
    document.getElementById('artist-name').style.color = hexToRgba(muted, 0.9);
    document.getElementById('album-name').style.color  = hexToRgba(muted, 0.55);
    document.getElementById('time-pos').style.color    = hexToRgba(accent, 0.5);
    document.getElementById('time-dur').style.color    = hexToRgba(accent, 0.3);
  } else {
    document.getElementById('progress-bar-fill').style.background = 'rgba(255,255,255,0.5)';
    document.getElementById('state-dot').style.background = 'rgba(255,255,255,0.2)';
    document.getElementById('artist-name').style.color = 'rgba(255,255,255,0.6)';
    document.getElementById('album-name').style.color  = 'rgba(255,255,255,0.35)';
    document.getElementById('time-pos').style.color    = 'rgba(255,255,255,0.25)';
    document.getElementById('time-dur').style.color    = 'rgba(255,255,255,0.25)';
  }
}

function updateProgress() {
  if (currentDuration > 0) {
    let pos = currentProgress;
    if (currentEndTime) {
      const remaining = (currentEndTime - Date.now()) / 1000;
      pos = Math.max(0, currentDuration - remaining);
    }
    const pct = Math.min(100, (pos / currentDuration) * 100);
    document.getElementById('progress-bar-fill').style.width = pct + '%';
    document.getElementById('time-pos').textContent = fmtTime(pos);
    document.getElementById('time-dur').textContent = fmtTime(currentDuration);
  } else {
    document.getElementById('progress-bar-fill').style.width = '0%';
    document.getElementById('time-pos').textContent = '—';
    document.getElementById('time-dur').textContent = '—';
  }
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

setInterval(updateProgress, 1000);
init();
</script>
</body>
</html>
