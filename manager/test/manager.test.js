'use strict';
const test = require('node:test');
const assert = require('node:assert/strict');
const fs = require('fs');
const os = require('os');
const path = require('path');
const http = require('http');
const net = require('net');
const { execFileSync, execFile } = require('child_process');
const { createManager, loadConfig } = require('../server');
const fake = require('./fake-docker');

const tmp = fs.mkdtempSync(path.join(os.tmpdir(), 'rm-manager-'));
const fakeDir = path.join(tmp, 'fake');
fs.mkdirSync(fakeDir);
const docker = fake.install(fakeDir);
process.env.PATH = `${docker.bin}${path.delimiter}${process.env.PATH}`;

const ID = (n) => String(n).repeat(12);
docker.set('services.txt', 'app\nredis\nmariadb\nmosquitto\n');
docker.set('ps.ndjson', [
  { ID: ID(1), Name: 'remoment-app-1', Service: 'app', State: 'running', Health: 'healthy', ExitCode: 0 },
  { ID: ID(2), Name: 'remoment-redis-1', Service: 'redis', State: 'restarting', Health: '', ExitCode: 1 },
  { ID: ID(3), Name: 'remoment-mariadb-1', Service: 'mariadb', State: 'exited', Health: '', ExitCode: 2 },
  { ID: ID(4), Name: 'remoment-mosquitto-1', Service: 'mosquitto', State: 'exited', Health: '', ExitCode: 0 },
  { ID: ID(9), Name: 'other-1', Service: 'not-in-list', State: 'running', ExitCode: 0 },
].map((r) => JSON.stringify(r)).join('\n'));
const started = new Date(Date.now() - 3600_000).toISOString();
docker.set('inspect.json', JSON.stringify([
  { Id: ID(1) + 'ffff', RestartCount: 0, State: { Status: 'running', StartedAt: started, ExitCode: 0 } },
  { Id: ID(2) + 'ffff', RestartCount: 7, State: { Status: 'restarting', StartedAt: started, ExitCode: 1 } },
  { Id: ID(3) + 'ffff', RestartCount: 0, State: { Status: 'exited', StartedAt: started, ExitCode: 2 } },
  { Id: ID(4) + 'ffff', RestartCount: 0, State: { Status: 'exited', StartedAt: started, ExitCode: 0 } },
]));
docker.set('stats.ndjson', [
  { ID: ID(1), CPUPerc: '1.50%', MemUsage: '128MiB / 1GiB' },
].map((r) => JSON.stringify(r)).join('\n'));

function freePort() {
  return new Promise((resolve) => {
    const s = net.createServer().listen(0, '127.0.0.1', () => { const p = s.address().port; s.close(() => resolve(p)); });
  });
}

let ctx;
test.before(async () => {
  const port = await freePort();
  const home = path.join(tmp, 'home');
  const thermal = path.join(tmp, 'temp');
  fs.writeFileSync(thermal, '48500\n');
  const cfg = loadConfig({
    REMOMENT_MANAGER_HOME: home, REMOMENT_MANAGER_PORT: String(port), REMOMENT_PROJECT_DIR: tmp,
    REMOMENT_COMPOSE_FILE: 'docker-compose.prod.yml', REMOMENT_COMPOSE_PROJECT: 'remoment', REMOMENT_THERMAL_FILE: thermal, REMOMENT_SAMPLE_SECONDS: '0', REMOMENT_OPTIMIZE_RETRY_MS: '1',
  });
  const m = createManager(cfg);
  await m.listen();
  ctx = { cfg, port, m, token: m.token };
});
test.after(() => { ctx.m.server.close(); });

function req(method, p, { token = ctx.token, headers = {}, host } = {}) {
  return new Promise((resolve, reject) => {
    const h = { host: host || `127.0.0.1:${ctx.port}`, ...headers };
    if (token) h.authorization = `Bearer ${token}`;
    const r = http.request({ host: '127.0.0.1', port: ctx.port, method, path: p, headers: h }, (res) => {
      let body = '';
      res.on('data', (d) => (body += d));
      res.on('end', () => resolve({ status: res.statusCode, body, json: () => JSON.parse(body) }));
    });
    r.on('error', reject);
    r.end();
  });
}
const change = { 'x-remoment-manager': '1' };

test('token file is created owner-only and never returned', async () => {
  assert.equal(fs.statSync(ctx.cfg.tokenFile).mode & 0o777, 0o600);
  assert.equal(fs.statSync(ctx.cfg.home).mode & 0o777, 0o700);
  assert.match(ctx.token, /^[0-9a-f]{64}$/);
  for (const p of ['/health', '/status', '/audit']) {
    assert.ok(!(await req('GET', p)).body.includes(ctx.token));
  }
});

test('/health works without a token and shows nothing sensitive', async () => {
  const r = await req('GET', '/health', { token: null });
  assert.equal(r.status, 200);
  assert.deepEqual(Object.keys(r.json()).sort(), ['lan', 'name', 'open', 'status', 'uptime_seconds', 'version']);
  assert.equal(r.json().lan, false);
});

test('missing or wrong token gives 401', async () => {
  assert.equal((await req('GET', '/status', { token: null })).status, 401);
  assert.equal((await req('GET', '/status', { token: 'nope' })).status, 401);
  assert.equal((await req('POST', '/restart-all', { token: 'nope', headers: change })).status, 401);
});

test('wrong Host gives 403, also for /health', async () => {
  assert.equal((await req('GET', '/status', { host: 'evil.example' })).status, 403);
  assert.equal((await req('GET', '/health', { host: 'evil.example', token: null })).status, 403);
  assert.equal((await req('GET', '/status', { host: '127.0.0.1:1' })).status, 403);
  assert.equal((await req('GET', '/health', { host: `localhost:${ctx.port}`, token: null })).status, 200);
});

test('status shape, crashed and restarting detection, only compose services', async () => {
  const r = await req('GET', '/status');
  assert.equal(r.status, 200);
  const s = r.json();
  assert.equal(s.project, 'remoment');
  assert.deepEqual(s.services.map((x) => x.service), ['app', 'mariadb', 'mosquitto', 'redis']);
  const by = Object.fromEntries(s.services.map((x) => [x.service, x]));
  assert.equal(by.app.state, 'running');
  assert.equal(by.app.health, 'healthy');
  assert.equal(by.app.problem, null);
  assert.ok(Math.abs(by.app.up_seconds - 3600) < 30);
  assert.equal(by.app.memory_bytes, 128 * 1024 ** 2);
  assert.equal(by.app.cpu_percent, 1.5);
  assert.equal(by.redis.problem, 'restarting');
  assert.equal(by.redis.restart_count, 7);
  assert.equal(by.mariadb.problem, 'crashed');
  assert.equal(by.mariadb.exit_code, 2);
  assert.equal(by.mosquitto.problem, null);
  const h = s.host;
  assert.ok(h.disk.free_bytes > 0);
  assert.ok(h.memory.total_bytes > 0);
  assert.equal(h.load.length, 3);
  assert.ok(h.uptime_seconds > 0);
  assert.equal(h.cpu_temperature_c, 48.5);
});

test('logs: plain text, default and capped tail', async () => {
  docker.clear();
  let r = await req('GET', '/logs/app');
  assert.equal(r.status, 200);
  assert.equal(r.body.trim().split('\n').length, 200);
  r = await req('GET', '/logs/app?tail=999999');
  assert.equal(r.body.trim().split('\n').length, 2000);
  const logCall = docker.calls().filter((c) => c.includes('logs')).pop();
  assert.deepEqual(logCall.slice(-4), ['--no-color', '--tail', '2000', 'app'].slice(0, 4));
  assert.equal((await req('GET', '/logs/app?tail=abc')).status, 400);
});

test('a service outside the list is refused and no command for it runs', async () => {
  docker.clear();
  for (const [m, p, h] of [['GET', '/logs/evil', {}], ['POST', '/restart/evil', change], ['POST', '/restart/not-in-list', change],
    ['POST', '/restart/..%2F..%2Fetc', change], ['GET', '/logs/app;id', {}], ['POST', '/restart/%00', change]]) {
    assert.equal((await req(m, p, { headers: h })).status, 404, p);
  }
  const bad = docker.calls().filter((c) => c.includes('restart') || c.includes('logs'));
  assert.deepEqual(bad, []);
});

test('restart and restart-all call docker compose with the right arguments and are audited', async () => {
  docker.clear();
  let r = await req('POST', '/restart/redis', { headers: change });
  assert.equal(r.status, 200);
  assert.deepEqual(r.json(), { status: 'ok', action: 'restart', service: 'redis' });
  const call = docker.calls().find((c) => c.includes('restart'));
  assert.deepEqual(call, ['compose', '-f', path.join(tmp, 'docker-compose.prod.yml'), '-p', 'remoment', '--profile', '*', 'restart', 'redis']);

  docker.clear();
  r = await req('POST', '/restart-all', { headers: change });
  assert.equal(r.status, 200);
  assert.deepEqual(docker.calls().find((c) => c.includes('restart')), ['compose', '-f', path.join(tmp, 'docker-compose.prod.yml'), '-p', 'remoment', '--profile', '*', 'restart']);

  const a = (await req('GET', '/audit?limit=10')).json().data;
  assert.equal(a[0].action, 'restart-all');
  assert.equal(a[1].action, 'restart');
  assert.equal(a[1].service, 'redis');
  assert.equal(a[1].result, 'ok');
  assert.ok(a[1].time);
  assert.ok(fs.readFileSync(ctx.cfg.auditFile, 'utf8').includes('"restart"'));
  assert.ok(!fs.readFileSync(ctx.cfg.auditFile, 'utf8').includes(ctx.token));
});

test('a failed restart gives 502 and is audited as failed', async () => {
  docker.set('restart-fail', '1');
  const r = await req('POST', '/restart/app', { headers: change });
  fs.rmSync(path.join(fakeDir, 'restart-fail'));
  assert.equal(r.status, 502);
  assert.equal((await req('GET', '/audit?limit=1')).json().data[0].result, 'failed (exit 1)');
});

test('a change without the header gives 403 and runs nothing', async () => {
  docker.clear();
  assert.equal((await req('POST', '/restart/app')).status, 403);
  assert.equal((await req('POST', '/restart-all', { headers: { 'x-remoment-manager': '0' } })).status, 403);
  assert.deepEqual(docker.calls().filter((c) => c.includes('restart')), []);
});

test('unknown route is 404, wrong method is 405', async () => {
  assert.equal((await req('GET', '/nope')).status, 404);
  assert.equal((await req('GET', '/exec/app')).status, 404);
  assert.equal((await req('GET', '/restart/app')).status, 405);
  assert.equal((await req('POST', '/status', { headers: change })).status, 405);
  assert.equal((await req('DELETE', '/logs/app')).status, 405);
  assert.equal((await req('PUT', '/restart-all', { headers: change })).status, 405);
});

test('a second change while one runs gives 409', async () => {
  docker.set('restart-sleep-ms', '600');
  const first = req('POST', '/restart/app', { headers: change });
  await new Promise((r) => setTimeout(r, 200));
  const second = await req('POST', '/restart/redis', { headers: change });
  assert.equal(second.status, 409);
  assert.equal((await req('GET', '/status')).status, 200); // reads stay available
  assert.equal((await first).status, 200);
  docker.set('restart-sleep-ms', '0');
  assert.equal((await req('POST', '/restart/redis', { headers: change })).status, 200); // lock released
});

test('request body is capped', async () => {
  const r = await new Promise((resolve, reject) => {
    const q = http.request({ host: '127.0.0.1', port: ctx.port, method: 'POST', path: '/restart-all',
      headers: { host: `127.0.0.1:${ctx.port}`, authorization: `Bearer ${ctx.token}`, ...change, 'content-length': 100000 } }, (res) => resolve(res.statusCode));
    q.on('error', reject);
    q.end('x'.repeat(100000));
  });
  assert.equal(r, 413);
});

test('the server source has no shell use', () => {
  const src = fs.readFileSync(path.join(__dirname, '..', 'server.js'), 'utf8');
  assert.ok(!/(?<![.\w])exec\(|execSync|spawnSync|shell:\s*true/.test(src));
  assert.ok(/execFile\(cmd, args/.test(src));
  assert.equal((src.match(/execFile\(/g) || []).length, 1); // one place runs commands
});

// --- web page: own login with the manager token, a session cookie, optional LAN mode -----------------------------

function raw(port, method, p, { headers = {}, body, host } = {}) {
  return new Promise((resolve, reject) => {
    const h = { host: host || `127.0.0.1:${port}`, ...headers };
    if (body !== undefined) { h['content-type'] = 'application/json'; h['content-length'] = Buffer.byteLength(body); }
    const r = http.request({ host: '127.0.0.1', port, method, path: p, headers: h }, (res) => {
      let out = '';
      res.on('data', (d) => (out += d));
      res.on('end', () => resolve({ status: res.statusCode, headers: res.headers, body: out, json: () => JSON.parse(out) }));
    });
    r.on('error', reject);
    r.end(body);
  });
}
const loginWith = (port, token) => raw(port, 'POST', '/login', { headers: change, body: JSON.stringify({ token }) });
const cookieOf = (res) => String(res.headers['set-cookie'] || '').split(';')[0];

test('the page is served without a session, with a strict policy and nothing from the API in it', async () => {
  const r = await raw(ctx.port, 'GET', '/');
  assert.equal(r.status, 200);
  assert.match(r.headers['content-type'], /text\/html/);
  assert.match(r.headers['content-security-policy'], /default-src 'none'/);
  assert.match(r.headers['content-security-policy'], /script-src 'nonce-/);
  assert.equal(r.headers['x-frame-options'], 'DENY');
  assert.ok(!r.body.includes(ctx.token));
  assert.ok(!/LAN mode/.test(r.body)); // loopback: no LAN warning
  assert.equal((await raw(ctx.port, 'POST', '/')).status, 405);
});

test('login with the token gives a session that reads the API; a wrong token does not', async () => {
  const bad = await loginWith(ctx.port, 'nope');
  assert.equal(bad.status, 401);
  assert.ok(!bad.headers['set-cookie']);

  const ok = await loginWith(ctx.port, ctx.token);
  assert.equal(ok.status, 200);
  const set = String(ok.headers['set-cookie']);
  assert.match(set, /rm_session=[0-9a-f]{64}/);
  assert.match(set, /HttpOnly/);
  assert.match(set, /SameSite=Strict/);

  const cookie = cookieOf(ok);
  assert.equal((await raw(ctx.port, 'GET', '/status', { headers: { cookie } })).status, 200);
  assert.equal((await raw(ctx.port, 'GET', '/status', { headers: { cookie: 'rm_session=' + '0'.repeat(64) } })).status, 401);
  assert.equal((await raw(ctx.port, 'GET', '/status')).status, 401);
  assert.ok(!ok.body.includes(ctx.token));
});

test('a session still needs the change header to restart, and logout ends it', async () => {
  const cookie = cookieOf(await loginWith(ctx.port, ctx.token));
  assert.equal((await raw(ctx.port, 'POST', '/restart/app', { headers: { cookie } })).status, 403);
  assert.equal((await raw(ctx.port, 'POST', '/restart/app', { headers: { cookie, ...change } })).status, 200);

  assert.equal((await raw(ctx.port, 'POST', '/logout', { headers: { cookie } })).status, 403);
  assert.equal((await raw(ctx.port, 'POST', '/logout', { headers: { cookie, ...change } })).status, 200);
  assert.equal((await raw(ctx.port, 'GET', '/status', { headers: { cookie } })).status, 401);
});

test('login needs the change header and a small body', async () => {
  assert.equal((await raw(ctx.port, 'POST', '/login', { body: JSON.stringify({ token: ctx.token }) })).status, 403);
  const big = await loginWith(ctx.port, 'x'.repeat(5000)).catch((e) => ({ status: e.code }));
  assert.ok([413, 'ECONNRESET', 'EPIPE'].includes(big.status));
});

test('repeated wrong tokens are throttled, a right one resets nothing until the window passes', async () => {
  const port = await freePort();
  const cfg = loadConfig({ REMOMENT_MANAGER_HOME: path.join(tmp, 'throttle'), REMOMENT_MANAGER_PORT: String(port), REMOMENT_PROJECT_DIR: tmp });
  const m = createManager(cfg);
  await m.listen();
  try {
    for (let i = 0; i < 10; i++) assert.equal((await loginWith(port, 'wrong' + i)).status, 401);
    assert.equal((await loginWith(port, 'wrong')).status, 429);
    assert.equal((await loginWith(port, m.token)).status, 429); // even the right one waits
  } finally { m.server.close(); }
});

test('on loopback only 127.0.0.1 and localhost are accepted as Host', async () => {
  for (const host of [`192.168.1.9:${ctx.port}`, `remoment.local:${ctx.port}`, `evil.example.com:${ctx.port}`, '127.0.0.1:1']) {
    assert.equal((await raw(ctx.port, 'GET', '/', { host })).status, 403, host);
  }
  assert.equal((await raw(ctx.port, 'GET', '/', { host: `localhost:${ctx.port}` })).status, 200);
});

test('LAN mode accepts the machine by address or .local name, still not a foreign domain, and warns on the page', async () => {
  const port = await freePort();
  const cfg = loadConfig({
    REMOMENT_MANAGER_HOME: path.join(tmp, 'lan'), REMOMENT_MANAGER_PORT: String(port), REMOMENT_PROJECT_DIR: tmp,
    REMOMENT_MANAGER_BIND: '0.0.0.0', REMOMENT_MANAGER_ALLOWED_HOSTS: 'remoment.example',
  });
  const m = createManager(cfg);
  await m.listen();
  try {
    for (const host of [`192.168.1.109:${port}`, `remoment.local:${port}`, `remoment.example:${port}`]) {
      assert.equal((await raw(port, 'GET', '/', { host })).status, 200, host);
    }
    for (const host of [`evil.example.com:${port}`, `192.168.1.109:${port + 1}`, '192.168.1.109']) {
      assert.equal((await raw(port, 'GET', '/', { host })).status, 403, host);
    }
    const page = await raw(port, 'GET', '/', { host: `192.168.1.109:${port}` });
    assert.match(page.body, /LAN mode/);
    assert.equal((await raw(port, 'GET', '/health', { host: `192.168.1.109:${port}` })).json().lan, true);
    // The API is closed to the LAN without the token, as before.
    assert.equal((await raw(port, 'GET', '/status', { host: `192.168.1.109:${port}` })).status, 401);
  } finally { m.server.close(); }
});

test('the page puts text in with textContent only', () => {
  const src = fs.readFileSync(path.join(__dirname, '..', 'server.js'), 'utf8');
  const script = src.slice(src.indexOf('function renderPage'), src.indexOf('function createManager'));
  assert.ok(!/innerHTML|outerHTML|insertAdjacentHTML|document\.write|eval\(/.test(script));
});

test('CLI talks to the API: status, logs, restart, audit, token', async () => {
  const cli = path.join(__dirname, '..', 'bin', 'remoment-manager');
  const env = { ...process.env, REMOMENT_MANAGER_HOME: ctx.cfg.home, REMOMENT_MANAGER_PORT: String(ctx.port) };
  const run = (...a) => new Promise((resolve) => execFile(process.execPath, [cli, ...a], { env }, (e, so, se) => resolve({ code: e ? e.code : 0, so, se })));
  let r = await run('status');
  assert.equal(r.code, 0);
  assert.match(r.so, /mariadb\s+exited.*CRASHED/);
  assert.match(r.so, /redis\s+restarting.*RESTARTING/);
  r = await run('status', '--json');
  assert.equal(JSON.parse(r.so).project, 'remoment');
  r = await run('logs', 'app', '--tail', '3');
  assert.equal(r.so.trim().split('\n').length, 3);
  r = await run('restart', 'app');
  assert.match(r.so, /Restarted app/);
  r = await run('restart', 'evil');
  assert.equal(r.code, 1);
  assert.match(r.se, /404/);
  r = await run('audit');
  assert.match(r.so, /restart app\s+ok/);
  r = await run('token');
  assert.ok(r.so.includes(ctx.cfg.tokenFile));
  assert.ok(!r.so.includes(ctx.token));
});

test('start runs `up -d` and stop runs `stop` for one service, with every profile visible, and both are audited', async () => {
  docker.clear();
  assert.equal((await req('POST', '/start/mosquitto')).status, 403); // header needed
  assert.equal((await req('POST', '/start/mosquitto', { headers: change })).status, 200);
  assert.equal((await req('POST', '/stop/mosquitto', { headers: change })).status, 200);
  const base = ['compose', '-f', path.join(tmp, 'docker-compose.prod.yml'), '-p', 'remoment', '--profile', '*'];
  assert.deepEqual(docker.calls().find((c) => c.includes('up')), [...base, 'up', '-d', 'mosquitto']);
  assert.deepEqual(docker.calls().find((c) => c.includes('stop')), [...base, 'stop', 'mosquitto']);
  assert.equal((await req('POST', '/start/nope', { headers: change })).status, 404);
  const audit = (await req('GET', '/audit?limit=5')).json().data;
  assert.ok(audit.some((a) => a.action === 'start' && a.service === 'mosquitto' && a.result === 'ok'));
  assert.ok(audit.some((a) => a.action === 'stop' && a.service === 'mosquitto'));
});

test('a failed start answers 502', async () => {
  docker.set('start-fail', '1');
  const r = await req('POST', '/start/app', { headers: change });
  docker.set('start-fail', '');
  assert.equal(r.status, 502);
});

test('history collects CPU samples per service', async () => {
  const r = await req('GET', '/history');
  assert.equal(r.status, 200);
  const d = r.json().data;
  assert.deepEqual(Object.keys(d).sort(), ['app', 'mariadb', 'mosquitto', 'redis']);
  assert.ok(d.app.length >= 1);
  assert.equal(d.app.at(-1).cpu, 1.5);
  assert.equal((await req('GET', '/history', { token: null })).status, 401);
});

test('open mode needs no token for the page API, but still the change header and the Host check', async () => {
  const port = await freePort();
  const m = createManager(loadConfig({
    REMOMENT_MANAGER_HOME: path.join(tmp, 'home-open'), REMOMENT_MANAGER_PORT: String(port), REMOMENT_PROJECT_DIR: tmp,
    REMOMENT_COMPOSE_FILE: 'docker-compose.prod.yml', REMOMENT_SAMPLE_SECONDS: '0', REMOMENT_MANAGER_OPEN: '1',
  }));
  await m.listen();
  const call = (method, p, headers = {}, host = `127.0.0.1:${port}`) => new Promise((resolve, reject) => {
    const r = http.request({ host: '127.0.0.1', port, method, path: p, headers: { host, ...headers } }, (res) => {
      let b = ''; res.on('data', (d) => (b += d)); res.on('end', () => resolve({ status: res.statusCode, body: b }));
    });
    r.on('error', reject); r.end();
  });
  try {
    assert.equal((await call('GET', '/status')).status, 200);
    assert.equal(JSON.parse((await call('GET', '/health')).body).open, true);
    assert.equal((await call('POST', '/restart/redis')).status, 403);
    assert.equal((await call('POST', '/restart/redis', change)).status, 200);
    assert.equal((await call('GET', '/status', {}, 'evil.example:' + port)).status, 403);
  } finally { m.server.close(); }
});

test('services are listed in a fixed (alphabetical) order', async () => {
  const names = (await req('GET', '/status')).json().services.map((s) => s.service);
  assert.deepEqual(names, [...names].sort());
});

function post(p, body) {
  return new Promise((resolve, reject) => {
    const data = JSON.stringify(body);
    const r = http.request({ host: '127.0.0.1', port: ctx.port, method: 'POST', path: p, headers: { host: `127.0.0.1:${ctx.port}`, authorization: `Bearer ${ctx.token}`, 'x-remoment-manager': '1', 'content-type': 'application/json', 'content-length': Buffer.byteLength(data) } }, (res) => {
      let b = ''; res.on('data', (d) => (b += d)); res.on('end', () => resolve({ status: res.statusCode, json: () => JSON.parse(b) }));
    });
    r.on('error', reject); r.end(data);
  });
}

test('features: list, switch on edits .env and starts the containers, switch off undoes it, a deploy re-applies the choice', async () => {
  const envFile = path.join(tmp, '.env');
  fs.writeFileSync(envFile, 'APP_NAME=ReMoment\nCOMPOSE_PROFILES=db,spotify-stream\nLIBRESPOT_ENABLED=true\nSTEMS_URL=\nANALYSIS_ENABLED=false\n');
  const list = (await req('GET', '/features')).json().data;
  assert.equal(list.find((f) => f.name === 'stems').enabled, false);
  assert.equal(list.find((f) => f.name === 'spotify-stream').enabled, true);
  assert.equal((await req('POST', '/features/stems')).status, 403);
  assert.equal((await post('/features/nope', { enabled: true })).status, 404);
  assert.equal((await post('/features/stems', { enabled: 'yes' })).status, 422);

  docker.clear();
  assert.equal((await post('/features/stems', { enabled: true })).status, 200);
  let env = fs.readFileSync(envFile, 'utf8');
  assert.match(env, /^STEMS_URL=http:\/\/127\.0\.0\.1:8092$/m);
  assert.match(env, /^COMPOSE_PROFILES=db,spotify-stream,stems$/m);
  assert.equal(env.match(/^STEMS_URL=/gm).length, 1);
  const ups = docker.calls().filter((c) => c.includes('up')).map((c) => c.slice(c.indexOf('up')));
  assert.deepEqual(ups, [['up', '-d', 'stems'], ['up', '-d', 'app']]);
  const execs = docker.calls().filter((c) => c.includes('exec')).map((c) => c.slice(c.indexOf('exec')));
  assert.deepEqual(execs, [['exec', '-T', '-u', 'sail', 'app', 'php', 'artisan', 'optimize:clear'], ['exec', '-T', '-u', 'sail', 'app', 'php', 'artisan', 'optimize']]);

  // a deploy rewrites .env from its template (without stems), then re-applies what the manager recorded
  fs.writeFileSync(envFile, 'APP_NAME=ReMoment\nCOMPOSE_PROFILES=db\n');
  require('../server').applyFeatures(ctx.cfg);
  env = fs.readFileSync(envFile, 'utf8');
  assert.match(env, /STEMS_URL=http:\/\/127\.0\.0\.1:8092/);
  assert.match(env, /COMPOSE_PROFILES=db,stems/);

  docker.clear();
  assert.equal((await post('/features/stems', { enabled: false })).status, 200);
  env = fs.readFileSync(envFile, 'utf8');
  assert.doesNotMatch(env, /STEMS_URL/);
  assert.match(env, /^COMPOSE_PROFILES=db$/m);
  assert.ok(docker.calls().some((c) => c.includes('stop') && c.includes('stems')));
  const audit = (await req('GET', '/audit?limit=5')).json().data;
  assert.ok(audit.some((a) => a.action === 'enable' && a.service === 'stems'));
  assert.ok(audit.some((a) => a.action === 'disable' && a.service === 'stems'));
});

test('features: switching off radio relay keeps Icecast while the Spotify stream needs it', async () => {
  const envFile = path.join(tmp, '.env');
  fs.writeFileSync(envFile, 'COMPOSE_PROFILES=db,spotify-stream,radio-relay\nLIBRESPOT_ENABLED=true\nRADIO_RELAY_ENABLED=true\n');
  docker.clear();
  assert.equal((await post('/features/radio-relay', { enabled: false })).status, 200);
  assert.equal(docker.calls().some((c) => c.includes('stop')), false);
  assert.match(fs.readFileSync(envFile, 'utf8'), /^RADIO_RELAY_ENABLED=false$/m);
});

test('features: a failing optimize is reported, after retries', async () => {
  fs.writeFileSync(path.join(tmp, '.env'), 'COMPOSE_PROFILES=db\n');
  docker.set('exec-fail', '1');
  docker.clear();
  const r = await post('/features/stems', { enabled: true });
  docker.set('exec-fail', '');
  assert.equal(r.status, 502);
  assert.match(r.json().message, /optimize:clear failed/);
  assert.equal(docker.calls().filter((c) => c.includes('exec')).length, 10);
});
