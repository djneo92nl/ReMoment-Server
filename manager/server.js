#!/usr/bin/env node
'use strict';
// ReMoment Manager: a small host service that shows and restarts the containers of one fixed
// Docker Compose project, and keeps working when the app is down. Dependency-free.
// Design: docs/architecture/manager.md. Shape borrowed from DevDash (execFile with argument
// arrays, localhost only, header + Host checks on every change, the CLI is only an API client).

const http = require('http');
const fs = require('fs');
const os = require('os');
const path = require('path');
const crypto = require('crypto');
const { execFile } = require('child_process');

const VERSION = '0.1.0';
const MAX_BODY = 1024; // bytes; no endpoint takes a body
const MAX_TAIL = 2000;
const DEFAULT_TAIL = 200;
const EXEC_TIMEOUT_MS = 120_000;
const START_TIMEOUT_MS = 900_000; // `up -d` may build an image first
const HISTORY_POINTS = 240;
const CHANGE_HEADER = 'x-remoment-manager';
const SESSION_COOKIE = 'rm_session';
const SESSION_SECONDS = 12 * 3600;
const LOGIN_WINDOW_MS = 10 * 60_000;
const LOGIN_MAX_FAILURES = 10;
const isLoopback = (bind) => bind === '127.0.0.1' || bind === 'localhost' || bind === '::1';

function loadConfig(env = process.env) {
  const home = env.REMOMENT_MANAGER_HOME || path.join(os.homedir(), '.remoment-manager');
  const projectDir = path.resolve(env.REMOMENT_PROJECT_DIR || path.join(__dirname, '..'));
  return {
    home,
    tokenFile: path.join(home, 'token'),
    auditFile: path.join(home, 'audit.log'),
    port: Number(env.REMOMENT_MANAGER_PORT || 8099),
    // 127.0.0.1 unless the admin opts in to the LAN (0.0.0.0 or one address): then the web page is reachable from other machines.
    bind: env.REMOMENT_MANAGER_BIND || '127.0.0.1',
    // Extra host names the Host check accepts in LAN mode (IP addresses, `*.local` and this machine's name always are).
    allowedHosts: String(env.REMOMENT_MANAGER_ALLOWED_HOSTS || '').split(',').map((h) => h.trim().toLowerCase()).filter(Boolean),
    projectDir,
    composeFile: path.resolve(projectDir, env.REMOMENT_COMPOSE_FILE || 'docker-compose.prod.yml'),
    project: env.REMOMENT_COMPOSE_PROJECT || 'remoment',
    thermalFile: env.REMOMENT_THERMAL_FILE || '/sys/class/thermal/thermal_zone0/temp',
    // No sign-in for the web page and the browser API: whoever reaches the port may use it (the bearer token still works
    // for the CLI and the app). Only sensible on a trusted LAN.
    open: ['1', 'true', 'yes'].includes(String(env.REMOMENT_MANAGER_OPEN || '').toLowerCase()),
    // CPU history: one `docker stats` sample every N seconds (0 = none), kept for an hour.
    sampleSeconds: Number(env.REMOMENT_SAMPLE_SECONDS === undefined ? 15 : env.REMOMENT_SAMPLE_SECONDS),
  };
}

// The token lives in a file only the owner can read; created on first start, never logged.
function ensureToken(cfg) {
  fs.mkdirSync(cfg.home, { recursive: true, mode: 0o700 });
  try { fs.chmodSync(cfg.home, 0o700); } catch { /* not ours to change */ }
  if (!fs.existsSync(cfg.tokenFile)) {
    fs.writeFileSync(cfg.tokenFile, crypto.randomBytes(32).toString('hex') + '\n', { mode: 0o600, flag: 'wx' });
  }
  fs.chmodSync(cfg.tokenFile, 0o600);
  return fs.readFileSync(cfg.tokenFile, 'utf8').trim();
}

function tokenMatches(expected, given) {
  const a = crypto.createHash('sha256').update(String(expected)).digest();
  const b = crypto.createHash('sha256').update(String(given)).digest();
  return crypto.timingSafeEqual(a, b);
}

function run(cmd, args, opts = {}) {
  return new Promise((resolve) => {
    execFile(cmd, args, { timeout: EXEC_TIMEOUT_MS, maxBuffer: 16 * 1024 * 1024, ...opts }, (err, stdout, stderr) => {
      resolve({ ok: !err, code: err ? (typeof err.code === 'number' ? err.code : -1) : 0, stdout: String(stdout || ''), stderr: String(stderr || '') });
    });
  });
}

// The manager's own web page: one inline document, no libraries, no external requests (the CSP allows none). It signs in
// with the manager token (POST /login sets a session cookie) and then reads the same API as the CLI. Text goes in with
// textContent only, so nothing from a container's logs or names can run as markup.
function renderPage(nonce, lan, open) {
  return `<!doctype html>
<html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
<title>ReMoment Manager</title>
<style nonce="${nonce}">
:root{--bg:#f6f6f4;--card:#fff;--text:#1c1b19;--muted:#6b6a66;--line:#e4e3de;--ok:#1f9d55;--bad:#d64545;--warn:#b7791f;--accent:#2d2d2a}
@media (prefers-color-scheme:dark){:root{--bg:#161615;--card:#1f1f1d;--text:#ecebe7;--muted:#9a9993;--line:#2e2e2b;--ok:#4cc38a;--bad:#f06a6a;--warn:#e0a64a;--accent:#ecebe7}}
*{box-sizing:border-box}body{margin:0;background:var(--bg);color:var(--text);font:15px/1.45 system-ui,sans-serif}
main{max-width:56rem;margin:0 auto;padding:1.25rem 1rem 3rem}h1{font-size:1.25rem;margin:0}h2{font-size:1rem;margin:0}
header{display:flex;align-items:center;justify-content:space-between;gap:1rem;margin-bottom:1rem}
.card{background:var(--card);border:1px solid var(--line);border-radius:1rem;margin-bottom:1rem;overflow:hidden}
.card>h2{padding:.9rem 1.1rem;border-bottom:1px solid var(--line)}.pad{padding:1rem 1.1rem}
.row{display:flex;flex-wrap:wrap;align-items:center;gap:.4rem 1rem;padding:.65rem 1.1rem;border-bottom:1px solid var(--line)}.row:last-child{border:0}
.name{width:9rem;font-weight:600;display:flex;align-items:center;gap:.5rem}.dot{width:.5rem;height:.5rem;border-radius:50%;background:var(--muted)}
.grow{flex:1;min-width:10rem;color:var(--muted);font-size:.8rem}.state{width:11rem;font-size:.85rem}.bad{color:var(--bad)}.ok{color:var(--ok)}
button{font:inherit;font-size:.85rem;padding:.35rem .8rem;border-radius:.6rem;border:1px solid var(--line);background:var(--card);color:var(--text);cursor:pointer}
button:hover{border-color:var(--muted)}button.primary{background:var(--accent);color:var(--bg);border-color:var(--accent)}
input{font:inherit;width:100%;padding:.6rem .8rem;border-radius:.6rem;border:1px solid var(--line);background:var(--bg);color:var(--text)}
pre{margin:0;padding:1rem 1.1rem;max-height:24rem;overflow:auto;font:12px/1.5 ui-monospace,monospace;white-space:pre-wrap;word-break:break-all}
dl{display:grid;grid-template-columns:repeat(auto-fit,minmax(10rem,1fr));gap:.8rem 1.5rem;margin:0}dt{font-size:.75rem;color:var(--muted)}dd{margin:0}
.banner{border:1px solid var(--warn);color:var(--warn);border-radius:.8rem;padding:.6rem .9rem;font-size:.85rem;margin-bottom:1rem}
.msg{margin-bottom:1rem;padding:.6rem .9rem;border-radius:.8rem;font-size:.9rem;border:1px solid var(--line)}.msg.bad{border-color:var(--bad)}.hidden{display:none}
.muted{color:var(--muted);font-size:.8rem}.bar{margin-bottom:.6rem}
.legend{display:flex;flex-wrap:wrap;gap:.3rem 1rem;margin-top:.6rem;font-size:.8rem}.legend i{display:inline-block;width:.7rem;height:.7rem;border-radius:2px;margin-right:.35rem;vertical-align:-1px}
</style></head><body><main>
<header><h1>ReMoment Manager</h1><div><span id="meta" class="muted"></span> <button id="signout" class="hidden">Sign out</button></div></header>
${lan && !open ? '<div class="banner">LAN mode: this page is reachable from your network over plain HTTP, and the manager can restart every container. Use it only on a network you trust.</div>' : ''}
<div id="msg" class="msg hidden"></div>
<section id="login" class="card hidden"><h2>Sign in</h2><form class="pad" id="loginform">
<p class="muted">The manager token is in <code>token</code> in the manager's home on the server (<code>remoment-manager token</code> prints the path). It is not the admin password.</p>
<input id="token" type="password" autocomplete="off" placeholder="Manager token" required> <p><button class="primary" type="submit">Sign in</button></p></form></section>
<section id="dash" class="hidden">
<div class="muted bar"><button id="restartall">Restart all</button> &nbsp;<span id="project"></span></div>
<div class="card"><h2>Containers</h2><div id="services"></div></div>
<div class="card hidden" id="logcard"><h2 id="logtitle"></h2><pre id="logs"></pre><div class="pad"><button id="logclose">Close</button></div></div>
<div class="card"><h2>CPU per container <span class="muted" id="cpuspan"></span></h2><div class="pad"><svg id="cpu" viewBox="0 0 600 160" width="100%" role="img" aria-label="CPU load per container"></svg><div id="cpulegend" class="legend"></div></div></div>
<div class="card"><h2>Host</h2><div class="pad"><dl id="host"></dl></div></div>
<div class="card"><h2>Recent changes</h2><div id="audit"></div></div>
</section></main>
<script nonce="${nonce}">
(function(){
var H={'x-remoment-manager':'1'},logService=null,timer=null,$=function(i){return document.getElementById(i)};
function el(tag,cls,text){var e=document.createElement(tag);if(cls)e.className=cls;if(text!==undefined)e.textContent=text;return e}
function show(id,on){$(id).classList.toggle('hidden',!on)}
function say(text,bad){var m=$('msg');m.textContent=text||'';m.className='msg'+(bad?' bad':'')+(text?'':' hidden')}
function api(method,path,body){var o={method:method,credentials:'same-origin',headers:Object.assign({},H)};
 if(body){o.body=JSON.stringify(body);o.headers['content-type']='application/json'}
 return fetch(path,o).then(function(r){return r.text().then(function(t){var j=null;try{j=JSON.parse(t)}catch(e){}return{status:r.status,text:t,json:j}})})}
function bytes(n){if(n==null)return'—';return n>=1073741824?(n/1073741824).toFixed(1)+' GB':Math.round(n/1048576)+' MB'}
function dur(s){if(s==null)return'—';if(s>=86400)return Math.floor(s/86400)+'d '+Math.floor(s%86400/3600)+'h';if(s>=3600)return Math.floor(s/3600)+'h '+Math.floor(s%3600/60)+'m';return Math.max(1,Math.floor(s/60))+'m'}
function signedOut(){clearInterval(timer);show('dash',false);show('login',true);show('signout',false);$('meta').textContent=''}
function renderServices(st){
 $('project').textContent='Project '+st.project+' · refreshes every 10 s';
 var box=$('services');box.textContent='';
 st.services.forEach(function(s){
  var bad=s.problem||s.state==='missing'||s.health==='unhealthy',row=el('div','row'),n=el('div','name'),d=el('span','dot');
  d.style.background=bad?'var(--bad)':(s.state==='running'?'var(--ok)':'var(--muted)');n.appendChild(d);n.appendChild(el('span','',s.service));row.appendChild(n);
  row.appendChild(el('div','state'+(bad?' bad':''),(s.problem||s.state)+(s.health?' · '+s.health:'')+(s.state==='exited'&&s.exit_code!=null?' (exit '+s.exit_code+')':'')));
  row.appendChild(el('div','grow','up '+dur(s.up_seconds)+' · '+(s.restart_count||0)+' restarts · '+(s.memory_usage||bytes(s.memory_bytes))+' · cpu '+(s.cpu_percent!=null?s.cpu_percent.toFixed(1)+'%':'—')));
  var lb=el('button','','Logs');lb.onclick=function(){logService=logService===s.service?null:s.service;loadLogs()};
  var up=s.state==='running'||s.state==='restarting';
  row.appendChild(lb);
  if(up){var rb=el('button','','Restart');rb.onclick=function(){restart(s.service)};row.appendChild(rb)}
  var tb=el('button',up?'':'primary',up?'Stop':'Start');tb.onclick=function(){toggle(s.service,up)};row.appendChild(tb);
  box.appendChild(row)});
 var h=st.host,dl=$('host');dl.textContent='';
 function item(k,v){var w=el('div');w.appendChild(el('dt','',k));w.appendChild(el('dd','',v));dl.appendChild(w)}
 item('Disk free',h.disk?bytes(h.disk.free_bytes)+' of '+bytes(h.disk.total_bytes):'—');
 item('Memory free',bytes(h.memory.free_bytes)+' of '+bytes(h.memory.total_bytes));
 item('Load (1, 5, 15 min)',(h.load||[]).map(function(l){return l.toFixed(2)}).join('  ')||'—');
 item('Uptime',dur(h.uptime_seconds));
 if(h.cpu_temperature_c!=null)item('CPU temperature',Math.round(h.cpu_temperature_c)+' °C')}
function renderAudit(list){var box=$('audit');box.textContent='';
 if(!list.length){box.appendChild(el('div','pad muted','No changes made through the manager yet.'));return}
 list.forEach(function(a){var r=el('div','row');r.appendChild(el('span','muted',new Date(a.time).toLocaleString()));
  r.appendChild(el('span','',a.action+(a.service?' · '+a.service:'')));r.appendChild(el('span',/^failed/.test(a.result)?'bad':'muted',a.result));box.appendChild(r)})}
function loadLogs(){
 if(!logService){show('logcard',false);return}
 api('GET','/logs/'+encodeURIComponent(logService)+'?tail=200').then(function(r){
  $('logtitle').textContent='Logs · '+logService+' · last 200 lines';
  $('logs').textContent=r.status===200?(r.text.trim()||'No output.'):((r.json&&r.json.message)||'Could not read the logs.');show('logcard',true)})}
function refresh(){
 return Promise.all([api('GET','/status'),api('GET','/audit?limit=15')]).then(function(rs){
  if(rs[0].status===401){signedOut();return}
  show('login',false);show('dash',true);show('signout',true);
  if(rs[0].status!==200){say((rs[0].json&&rs[0].json.message)||'The manager could not read Docker.',true);return}
  renderServices(rs[0].json);renderAudit(rs[1].json?rs[1].json.data:[]);loadLogs();loadCpu()})}
function restart(service){
 var all=service===null;
 if(!confirm(all?'Restart every container?':'Restart '+service+'?'))return;
 say((all?'Restarting everything':'Restarting '+service)+'…');
 api('POST',all?'/restart-all':'/restart/'+encodeURIComponent(service)).then(function(r){
  if(r.status===200)say(all?'Restarted every container.':'Restarted '+service+'.');
  else say((r.json&&(r.json.message||r.json.error))||'The restart failed.',true);refresh()})}
function toggle(service,up){
 if(up&&!confirm('Stop '+service+'?'))return;
 say((up?'Stopping ':'Starting ')+service+(up?'':' (this can take minutes if the image has to be built)')+'…');
 api('POST',(up?'/stop/':'/start/')+encodeURIComponent(service)).then(function(r){
  if(r.status===200)say((up?'Stopped ':'Started ')+service+'.');
  else say((r.json&&(r.json.message||r.json.error))||'That failed.',true);refresh()})}
var COLORS=['#2f80ed','#e5484d','#30a46c','#f5a524','#8e4ec6','#12a594','#e93d82','#7c8a97','#d9781a','#5b8def'];
function renderCpu(h){
 var svg=$('cpu'),leg=$('cpulegend'),NS='http://www.w3.org/2000/svg';svg.textContent='';leg.textContent='';
 var names=Object.keys(h.data).filter(function(n){return h.data[n].some(function(p){return p.cpu!=null})});
 var all=[],tmin=Infinity,tmax=0,top=10;
 names.forEach(function(n){h.data[n].forEach(function(p){if(p.cpu!=null){top=Math.max(top,p.cpu)}tmin=Math.min(tmin,p.t);tmax=Math.max(tmax,p.t)})});
 if(!names.length||tmax<=tmin){$('cpuspan').textContent='collecting…';return}
 top=Math.ceil(top/10)*10;$('cpuspan').textContent='last '+Math.max(1,Math.round((tmax-tmin)/60000))+' min · 100% = one core';
 var L=34,R=596,T=8,B=148;
 function X(t){return L+(t-tmin)/(tmax-tmin)*(R-L)}function Y(v){return B-Math.min(v,top)/top*(B-T)}
 function mk(tag,a){var e=document.createElementNS(NS,tag);for(var k in a)e.setAttribute(k,a[k]);return e}
 [0,.5,1].forEach(function(f){var v=top*f,y=Y(v);svg.appendChild(mk('line',{x1:L,x2:R,y1:y,y2:y,stroke:'currentColor','stroke-opacity':'.15'}));
  var t=mk('text',{x:L-4,y:y+3,'text-anchor':'end','font-size':'9',fill:'currentColor','fill-opacity':'.6'});t.textContent=Math.round(v)+'%';svg.appendChild(t)});
 names.forEach(function(n,i){var c=COLORS[i%COLORS.length],pts=h.data[n].filter(function(p){return p.cpu!=null}).map(function(p){return X(p.t).toFixed(1)+','+Y(p.cpu).toFixed(1)}).join(' ');
  if(pts)svg.appendChild(mk('polyline',{points:pts,fill:'none',stroke:c,'stroke-width':'1.6','stroke-linejoin':'round'}));
  var last=h.data[n][h.data[n].length-1],item=el('span','');var sw=el('i');sw.style.background=c;item.appendChild(sw);
  item.appendChild(document.createTextNode(n+(last&&last.cpu!=null?' '+last.cpu.toFixed(1)+'%':'')));leg.appendChild(item)})}
function loadCpu(){api('GET','/history').then(function(r){if(r.status===200&&r.json)renderCpu(r.json)})}
$('restartall').onclick=function(){restart(null)};
$('logclose').onclick=function(){logService=null;loadLogs()};
$('loginform').onsubmit=function(e){e.preventDefault();say('');
 api('POST','/login',{token:$('token').value}).then(function(r){
  if(r.status===200){$('token').value='';start()}else say((r.json&&r.json.message)||'Sign in failed.',true)})};
$('signout').onclick=function(){api('POST','/logout').then(function(){say('');signedOut()})};
function start(){refresh();clearInterval(timer);timer=setInterval(function(){if(!document.hidden)refresh()},10000)}
fetch('/health').then(function(r){return r.json()}).then(function(h){$('meta').textContent='v'+h.version}).catch(function(){});
start();
})();
</script></body></html>`;
}

function createManager(cfg) {
  const token = ensureToken(cfg);
  const startedAt = Date.now();
  let busy = null; // description of the change in progress

  // `--profile '*'` makes every service of the file visible (analysis, icecast, spotify ...), enabled or not.
  const composeWith = (opts, ...rest) => run('docker', ['compose', '-f', cfg.composeFile, '-p', cfg.project, '--profile', '*', ...rest], { cwd: cfg.projectDir, ...opts });
  const compose = (...rest) => composeWith({}, ...rest);

  async function services() {
    const r = await compose('config', '--services');
    if (!r.ok) throw new Error('docker compose config failed');
    // `config --services` comes back in no fixed order: sort, so the page does not reshuffle on every refresh.
    return r.stdout.split('\n').map((s) => s.trim()).filter(Boolean).sort();
  }

  function audit(action, service, result) {
    const line = JSON.stringify({ time: new Date().toISOString(), action, service: service || null, result }) + '\n';
    try { fs.appendFileSync(cfg.auditFile, line, { mode: 0o600 }); } catch { /* never break a request over the log */ }
  }

  function readAudit(limit) {
    let lines = [];
    try { lines = fs.readFileSync(cfg.auditFile, 'utf8').split('\n').filter(Boolean); } catch { /* none yet */ }
    return lines.slice(-limit).reverse().map((l) => { try { return JSON.parse(l); } catch { return null; } }).filter(Boolean);
  }

  function parseNdjson(text) {
    const t = text.trim();
    if (!t) return [];
    if (t.startsWith('[')) { try { return JSON.parse(t); } catch { return []; } }
    return t.split('\n').map((l) => { try { return JSON.parse(l); } catch { return null; } }).filter(Boolean);
  }

  function toBytes(s) {
    const m = /^([\d.]+)\s*([KMGT]?i?B)/i.exec(String(s || '').trim());
    if (!m) return null;
    const mult = { B: 1, KB: 1e3, MB: 1e6, GB: 1e9, TB: 1e12, KIB: 1024, MIB: 1024 ** 2, GIB: 1024 ** 3, TIB: 1024 ** 4 }[m[2].toUpperCase()];
    return mult ? Math.round(parseFloat(m[1]) * mult) : null;
  }

  const history = new Map(); // service -> [{ t, cpu, mem }]
  let lastStatus = null; // { at, promise }

  // One status per 5 s at most (the page, the sampler and the CLI share it); every fresh one is a CPU history sample.
  function status() {
    if (lastStatus && Date.now() - lastStatus.at < 5000) return lastStatus.promise;
    const promise = readStatus().then((st) => { record(st); return st; });
    lastStatus = { at: Date.now(), promise };
    promise.catch(() => { if (lastStatus && lastStatus.promise === promise) lastStatus = null; });
    return promise;
  }

  function record(st) {
    const t = Date.now();
    for (const s of st.services) {
      const list = history.get(s.service) || [];
      list.push({ t, cpu: s.cpu_percent, mem: s.memory_bytes });
      while (list.length > HISTORY_POINTS) list.shift();
      history.set(s.service, list);
    }
  }

  async function readStatus() {
    const names = await services();
    const ps = await compose('ps', '-a', '--format', 'json');
    const rows = parseNdjson(ps.stdout).filter((r) => names.includes(r.Service));
    const ids = rows.map((r) => r.ID).filter((id) => /^[0-9a-f]{6,64}$/i.test(id || ''));

    const inspect = {};
    const stats = {};
    if (ids.length) {
      const [i, s] = await Promise.all([
        run('docker', ['inspect', ...ids]),
        run('docker', ['stats', '--no-stream', '--format', '{{json .}}', ...ids]),
      ]);
      for (const c of parseNdjson(i.stdout)) inspect[String(c.Id).slice(0, 12)] = c;
      for (const c of parseNdjson(s.stdout)) stats[String(c.ID || c.Container).slice(0, 12)] = c;
    }

    const list = names.map((service) => {
      const row = rows.find((r) => r.Service === service);
      if (!row) return { service, container: null, state: 'missing', health: null, problem: null, restart_count: 0, up_seconds: null, exit_code: null, memory_bytes: null, memory_usage: null, cpu_percent: null };
      const key = String(row.ID).slice(0, 12);
      const info = inspect[key];
      const st = info && info.State ? info.State : {};
      const state = String(row.State || st.Status || 'unknown').toLowerCase();
      const exit = Number.isFinite(Number(row.ExitCode)) ? Number(row.ExitCode) : (Number.isFinite(st.ExitCode) ? st.ExitCode : null);
      const started = st.StartedAt && !st.StartedAt.startsWith('0001') ? Date.parse(st.StartedAt) : NaN;
      const stat = stats[key];
      let problem = null;
      if (state === 'restarting') problem = 'restarting';
      else if (state === 'exited' && exit !== null && exit !== 0 && exit !== 143 && (exit !== 137 || st.OOMKilled)) problem = 'crashed';
      return {
        service,
        container: row.Name || null,
        state,
        health: row.Health || (st.Health && st.Health.Status) || null,
        problem,
        restart_count: info ? Number(info.RestartCount || 0) : 0,
        up_seconds: state === 'running' && Number.isFinite(started) ? Math.max(0, Math.round((Date.now() - started) / 1000)) : null,
        exit_code: state === 'exited' ? exit : null,
        memory_bytes: stat ? toBytes(String(stat.MemUsage).split('/')[0]) : null,
        memory_usage: stat ? stat.MemUsage : null,
        cpu_percent: stat ? parseFloat(stat.CPUPerc) : null,
      };
    });

    return { project: cfg.project, services: list, host: host() };
  }

  function host() {
    let disk = null;
    try {
      const s = fs.statfsSync(cfg.projectDir);
      disk = { path: cfg.projectDir, free_bytes: s.bavail * s.bsize, total_bytes: s.blocks * s.bsize };
    } catch { /* unsupported */ }
    let temp = null;
    try {
      const v = Number(fs.readFileSync(cfg.thermalFile, 'utf8').trim());
      if (Number.isFinite(v)) temp = v > 1000 ? v / 1000 : v;
    } catch { /* no sensor */ }
    return {
      disk,
      memory: { total_bytes: os.totalmem(), free_bytes: os.freemem() },
      load: os.loadavg(),
      uptime_seconds: Math.round(os.uptime()),
      cpu_temperature_c: temp,
    };
  }

  const lan = !isLoopback(cfg.bind);
  const open = cfg.open;
  const sessions = new Map(); // session id -> expiry (ms); in memory, gone on restart
  const failures = new Map(); // remote address -> [timestamps of failed logins]

  const send = (res, code, body, type = 'application/json', extra = {}) => {
    const out = typeof body === 'string' ? body : JSON.stringify(body);
    res.writeHead(code, { 'content-type': type + '; charset=utf-8', 'cache-control': 'no-store', 'x-content-type-options': 'nosniff', ...extra });
    res.end(out);
  };

  // The Host header must name this machine: against DNS rebinding (a page on another domain pointing at this address).
  function hostAllowed(header) {
    const m = /^(.+):(\d+)$/.exec(String(header || '').toLowerCase());
    if (!m || Number(m[2]) !== cfg.port) return false;
    const name = m[1];
    if (name === '127.0.0.1' || name === 'localhost') return true;
    if (!lan) return false;
    return /^\d{1,3}(\.\d{1,3}){3}$/.test(name) || name.endsWith('.local') || name === os.hostname().toLowerCase() || cfg.allowedHosts.includes(name);
  }

  function cookieSession(req) {
    const m = new RegExp(`(?:^|;\\s*)${SESSION_COOKIE}=([0-9a-f]{64})`).exec(String(req.headers.cookie || ''));
    if (!m) return null;
    const expires = sessions.get(m[1]);
    if (!expires || expires < Date.now()) { sessions.delete(m[1]); return null; }
    return m[1];
  }

  // The API takes the bearer token (CLI, the app) or the session the web page's login created.
  function authorized(req) {
    if (cfg.open) return true;
    const auth = String(req.headers.authorization || '');
    if (auth.startsWith('Bearer ')) return tokenMatches(token, auth.slice(7));
    return cookieSession(req) !== null;
  }

  function readBody(req) {
    return new Promise((resolve) => {
      let body = '';
      req.on('data', (c) => { body += c; if (body.length > MAX_BODY) { req.destroy(); resolve(null); } });
      req.on('end', () => resolve(body));
      req.on('error', () => resolve(null));
    });
  }

  async function login(req, res) {
    if (req.headers[CHANGE_HEADER] !== '1') return send(res, 403, { error: `missing ${CHANGE_HEADER} header` });
    const who = req.socket.remoteAddress || 'unknown';
    const recent = (failures.get(who) || []).filter((t) => Date.now() - t < LOGIN_WINDOW_MS);
    if (recent.length >= LOGIN_MAX_FAILURES) return send(res, 429, { error: 'too_many_attempts', message: 'Too many wrong tokens. Wait ten minutes.' });
    const body = await readBody(req);
    let given = '';
    try { given = String(JSON.parse(body || '{}').token || ''); } catch { /* not JSON */ }
    if (body === null) return send(res, 413, { error: 'too_large' });
    if (!given || !tokenMatches(token, given.trim())) {
      recent.push(Date.now());
      failures.set(who, recent);
      return send(res, 401, { error: 'unauthorized', message: 'That is not the manager token.' });
    }
    failures.delete(who);
    for (const [id, exp] of sessions) if (exp < Date.now()) sessions.delete(id);
    const id = crypto.randomBytes(32).toString('hex');
    sessions.set(id, Date.now() + SESSION_SECONDS * 1000);
    audit('login', null, 'ok');
    send(res, 200, { status: 'ok' }, 'application/json', { 'set-cookie': `${SESSION_COOKIE}=${id}; HttpOnly; SameSite=Strict; Path=/; Max-Age=${SESSION_SECONDS}` });
  }

  function logout(req, res) {
    if (req.headers[CHANGE_HEADER] !== '1') return send(res, 403, { error: `missing ${CHANGE_HEADER} header` });
    const id = cookieSession(req);
    if (id) sessions.delete(id);
    send(res, 200, { status: 'ok' }, 'application/json', { 'set-cookie': `${SESSION_COOKIE}=; HttpOnly; SameSite=Strict; Path=/; Max-Age=0` });
  }

  // The page holds nothing secret (all data comes from the API after login), so it is served without a session.
  function page(res) {
    const nonce = crypto.randomBytes(16).toString('base64');
    send(res, 200, renderPage(nonce, lan, open), 'text/html', {
      'content-security-policy': `default-src 'none'; script-src 'nonce-${nonce}'; style-src 'nonce-${nonce}'; connect-src 'self'; base-uri 'none'; form-action 'none'; frame-ancestors 'none'`,
      'x-frame-options': 'DENY',
      'referrer-policy': 'no-referrer',
    });
  }

  async function change(res, action, service, args, opts = {}) {
    if (busy) return send(res, 409, { error: 'busy', message: `Another change is running: ${busy}` });
    busy = service ? `${action} ${service}` : action;
    try {
      lastStatus = null;
      const r = await composeWith(opts, ...args);
      lastStatus = null;
      const result = r.ok ? 'ok' : `failed (exit ${r.code})`;
      audit(action, service, result);
      if (!r.ok) return send(res, 502, { error: 'docker_failed', message: (r.stderr || r.stdout).trim().slice(-500) });
      send(res, 200, { status: 'ok', action, service: service || null });
    } finally {
      busy = null;
    }
  }

  async function handle(req, res) {
    if (!hostAllowed(req.headers.host)) return send(res, 403, { error: 'bad_host' });

    const url = new URL(req.url, 'http://x');
    const p = url.pathname;
    const method = req.method;

    if (p === '/health') {
      if (method !== 'GET') return send(res, 405, { error: 'method_not_allowed' });
      return send(res, 200, { status: 'ok', name: 'remoment-manager', version: VERSION, uptime_seconds: Math.round((Date.now() - startedAt) / 1000), lan, open: cfg.open });
    }

    if (p === '/') {
      if (method !== 'GET') return send(res, 405, { error: 'method_not_allowed' });
      return page(res);
    }
    if (p === '/login' || p === '/logout') {
      if (method !== 'POST') return send(res, 405, { error: 'method_not_allowed' });
      return p === '/login' ? login(req, res) : logout(req, res);
    }

    if (!authorized(req)) return send(res, 401, { error: 'unauthorized' });

    const len = Number(req.headers['content-length'] || 0);
    if (len > MAX_BODY) return send(res, 413, { error: 'too_large' });
    let size = 0;
    req.on('data', (c) => { size += c.length; if (size > MAX_BODY) req.destroy(); });

    const seg = p.split('/').filter(Boolean);
    const route = seg[0];
    const GET_ROUTES = { status: 1, logs: 2, audit: 1, history: 1 };
    const POST_ROUTES = { restart: 2, start: 2, stop: 2, 'restart-all': 1 };
    const isGet = route in GET_ROUTES && seg.length === GET_ROUTES[route];
    const isPost = route in POST_ROUTES && seg.length === POST_ROUTES[route];
    if (!isGet && !isPost) return send(res, 404, { error: 'not_found' });
    if ((isGet && method !== 'GET') || (isPost && method !== 'POST')) return send(res, 405, { error: 'method_not_allowed' });

    if (isPost && req.headers[CHANGE_HEADER] !== '1') return send(res, 403, { error: `missing ${CHANGE_HEADER} header` });

    let service = null;
    if (seg.length === 2) {
      let name;
      try { name = decodeURIComponent(seg[1]); } catch { return send(res, 404, { error: 'unknown_service' }); }
      let known;
      try { known = await services(); } catch { return send(res, 502, { error: 'docker_failed', message: 'Cannot read the compose services.' }); }
      if (!known.includes(name)) return send(res, 404, { error: 'unknown_service' });
      service = name;
    }

    try {
      if (route === 'status') return send(res, 200, await status());
      if (route === 'history') {
        await status(); // a first call has something to show
        const data = {};
        for (const [name, list] of history) data[name] = list;
        return send(res, 200, { interval_seconds: cfg.sampleSeconds || null, data });
      }
      if (route === 'audit') {
        const limit = Math.min(Math.max(parseInt(url.searchParams.get('limit') || '50', 10) || 50, 1), 500);
        return send(res, 200, { data: readAudit(limit) });
      }
      if (route === 'logs') {
        const raw = url.searchParams.get('tail');
        let tail = DEFAULT_TAIL;
        if (raw !== null) {
          if (!/^\d+$/.test(raw)) return send(res, 400, { error: 'bad_tail' });
          tail = Math.min(Math.max(parseInt(raw, 10), 1), MAX_TAIL);
        }
        const r = await compose('logs', '--no-color', '--tail', String(tail), service);
        if (!r.ok) return send(res, 502, { error: 'docker_failed', message: r.stderr.trim().slice(-500) });
        return send(res, 200, r.stdout, 'text/plain');
      }
      if (route === 'restart') return await change(res, 'restart', service, ['restart', service]);
      if (route === 'start') return await change(res, 'start', service, ['up', '-d', service], { timeout: START_TIMEOUT_MS });
      if (route === 'stop') return await change(res, 'stop', service, ['stop', service]);
      if (route === 'restart-all') return await change(res, 'restart-all', null, ['restart']);
    } catch (e) {
      return send(res, 500, { error: 'internal', message: 'Something went wrong.' });
    }
  }

  const server = http.createServer((req, res) => { handle(req, res).catch(() => send(res, 500, { error: 'internal' })); });
  let sampler = null;
  server.on('close', () => clearInterval(sampler));
  const listen = () => new Promise((r) => server.listen(cfg.port, cfg.bind, () => {
    if (cfg.sampleSeconds > 0) {
      sampler = setInterval(() => { status().catch(() => {}); }, cfg.sampleSeconds * 1000);
      sampler.unref();
    }
    r();
  }));
  return { server, listen, token };
}

module.exports = { createManager, loadConfig, VERSION };

if (require.main === module) {
  const cfg = loadConfig();
  const m = createManager(cfg);
  m.listen().then(() => {
    console.log(`remoment-manager ${VERSION} on http://${cfg.bind}:${cfg.port} (token file: ${cfg.tokenFile})`);
    if (cfg.open) console.log('OPEN mode: no sign-in for the web page. Anyone who can reach this port can restart containers.');
    if (!isLoopback(cfg.bind)) console.log('LAN mode: the web page is reachable from the network over plain HTTP. Keep it on a trusted LAN.');
  });
}
