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
    REMOMENT_COMPOSE_FILE: 'docker-compose.prod.yml', REMOMENT_COMPOSE_PROJECT: 'remoment', REMOMENT_THERMAL_FILE: thermal,
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
  assert.deepEqual(Object.keys(r.json()).sort(), ['name', 'status', 'uptime_seconds', 'version']);
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
  assert.deepEqual(s.services.map((x) => x.service), ['app', 'redis', 'mariadb', 'mosquitto']);
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
  assert.deepEqual(call, ['compose', '-f', path.join(tmp, 'docker-compose.prod.yml'), '-p', 'remoment', 'restart', 'redis']);

  docker.clear();
  r = await req('POST', '/restart-all', { headers: change });
  assert.equal(r.status, 200);
  assert.deepEqual(docker.calls().find((c) => c.includes('restart')), ['compose', '-f', path.join(tmp, 'docker-compose.prod.yml'), '-p', 'remoment', 'restart']);

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
