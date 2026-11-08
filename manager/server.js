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
const CHANGE_HEADER = 'x-remoment-manager';

function loadConfig(env = process.env) {
  const home = env.REMOMENT_MANAGER_HOME || path.join(os.homedir(), '.remoment-manager');
  const projectDir = path.resolve(env.REMOMENT_PROJECT_DIR || path.join(__dirname, '..'));
  return {
    home,
    tokenFile: path.join(home, 'token'),
    auditFile: path.join(home, 'audit.log'),
    port: Number(env.REMOMENT_MANAGER_PORT || 8099),
    projectDir,
    composeFile: path.resolve(projectDir, env.REMOMENT_COMPOSE_FILE || 'docker-compose.prod.yml'),
    project: env.REMOMENT_COMPOSE_PROJECT || 'remoment',
    thermalFile: env.REMOMENT_THERMAL_FILE || '/sys/class/thermal/thermal_zone0/temp',
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

function createManager(cfg) {
  const token = ensureToken(cfg);
  const startedAt = Date.now();
  let busy = null; // description of the change in progress

  const compose = (...rest) => run('docker', ['compose', '-f', cfg.composeFile, '-p', cfg.project, ...rest], { cwd: cfg.projectDir });

  async function services() {
    const r = await compose('config', '--services');
    if (!r.ok) throw new Error('docker compose config failed');
    return r.stdout.split('\n').map((s) => s.trim()).filter(Boolean);
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

  async function status() {
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

  const send = (res, code, body, type = 'application/json') => {
    const out = typeof body === 'string' ? body : JSON.stringify(body);
    res.writeHead(code, { 'content-type': type + '; charset=utf-8', 'cache-control': 'no-store' });
    res.end(out);
  };

  async function change(res, action, service, args) {
    if (busy) return send(res, 409, { error: 'busy', message: `Another change is running: ${busy}` });
    busy = service ? `${action} ${service}` : action;
    try {
      const r = await compose(...args);
      const result = r.ok ? 'ok' : `failed (exit ${r.code})`;
      audit(action, service, result);
      if (!r.ok) return send(res, 502, { error: 'docker_failed', message: (r.stderr || r.stdout).trim().slice(-500) });
      send(res, 200, { status: 'ok', action, service: service || null });
    } finally {
      busy = null;
    }
  }

  async function handle(req, res) {
    const allowed = [`127.0.0.1:${cfg.port}`, `localhost:${cfg.port}`];
    if (!allowed.includes(String(req.headers.host || '').toLowerCase())) return send(res, 403, { error: 'bad_host' });

    const url = new URL(req.url, 'http://x');
    const p = url.pathname;
    const method = req.method;

    if (p === '/health') {
      if (method !== 'GET') return send(res, 405, { error: 'method_not_allowed' });
      return send(res, 200, { status: 'ok', name: 'remoment-manager', version: VERSION, uptime_seconds: Math.round((Date.now() - startedAt) / 1000) });
    }

    const auth = String(req.headers.authorization || '');
    if (!auth.startsWith('Bearer ') || !tokenMatches(token, auth.slice(7))) return send(res, 401, { error: 'unauthorized' });

    const len = Number(req.headers['content-length'] || 0);
    if (len > MAX_BODY) return send(res, 413, { error: 'too_large' });
    let size = 0;
    req.on('data', (c) => { size += c.length; if (size > MAX_BODY) req.destroy(); });

    const seg = p.split('/').filter(Boolean);
    const route = seg[0];
    const GET_ROUTES = { status: 1, logs: 2, audit: 1 };
    const POST_ROUTES = { restart: 2, 'restart-all': 1 };
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
      if (route === 'restart-all') return await change(res, 'restart-all', null, ['restart']);
    } catch (e) {
      return send(res, 500, { error: 'internal', message: 'Something went wrong.' });
    }
  }

  const server = http.createServer((req, res) => { handle(req, res).catch(() => send(res, 500, { error: 'internal' })); });
  return { server, listen: () => new Promise((r) => server.listen(cfg.port, '127.0.0.1', r)), token };
}

module.exports = { createManager, loadConfig, VERSION };

if (require.main === module) {
  const cfg = loadConfig();
  const m = createManager(cfg);
  m.listen().then(() => console.log(`remoment-manager ${VERSION} on http://127.0.0.1:${cfg.port} (token file: ${cfg.tokenFile})`));
}
