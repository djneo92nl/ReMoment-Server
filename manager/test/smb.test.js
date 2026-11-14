'use strict';
const test = require('node:test');
const assert = require('node:assert/strict');
const fs = require('fs');
const os = require('os');
const path = require('path');
const http = require('http');
const net = require('net');
const { execFile } = require('child_process');
const { promisify } = require('util');
const execFileAsync = promisify(execFile);
const { createManager, loadConfig } = require('../server');
const { pathError } = require('../smb');
const fake = require('./fake-samba');

const tmp = fs.mkdtempSync(path.join(os.tmpdir(), 'rm-smb-'));
const fakeDir = path.join(tmp, 'fake');
fs.mkdirSync(fakeDir);
const host = fake.install(fakeDir);
process.env.PATH = `${host.bin}${path.delimiter}${process.env.PATH}`;

const conf = path.join(tmp, 'etc', 'remoment.conf');
const mainConf = path.join(tmp, 'etc', 'smb.conf');
fs.mkdirSync(path.dirname(conf));
fs.writeFileSync(mainConf, `[global]\n   workgroup = HOME\n   include = ${conf}\n`);
for (const d of ['library', 'exports', 'inbox']) fs.mkdirSync(path.join(tmp, d));

function freePort() {
  return new Promise((resolve) => { const s = net.createServer().listen(0, '127.0.0.1', () => { const p = s.address().port; s.close(() => resolve(p)); }); });
}
function start(extraEnv = {}, name = 'home') {
  return freePort().then(async (port) => {
    const home = path.join(tmp, name);
    const cfg = loadConfig({
      REMOMENT_MANAGER_HOME: home, REMOMENT_MANAGER_PORT: String(port), REMOMENT_PROJECT_DIR: tmp, REMOMENT_SAMPLE_SECONDS: '0',
      REMOMENT_SMB_CONF: conf, REMOMENT_SMB_MAIN_CONF: mainConf, REMOMENT_SMB_LIBRARY_DIR: path.join(tmp, 'library'),
      REMOMENT_SMB_EXPORTS_DIR: path.join(tmp, 'exports'), REMOMENT_SMB_INBOX_DIR: path.join(tmp, 'inbox'), ...extraEnv,
    });
    const m = createManager(cfg);
    await m.listen();
    return { cfg, port, m, token: m.token };
  });
}

let ctx;
test.before(async () => { ctx = await start(); });
test.after(() => { ctx.m.server.close(); });

function req(method, p, { body, headers = { 'x-remoment-manager': '1' }, token = ctx.token, c = ctx } = {}) {
  return new Promise((resolve, reject) => {
    const h = { host: `127.0.0.1:${c.port}`, ...headers };
    if (token) h.authorization = `Bearer ${token}`;
    if (body !== undefined) h['content-type'] = 'application/json';
    const r = http.request({ host: '127.0.0.1', port: c.port, method, path: p, headers: h }, (res) => {
      let text = '';
      res.on('data', (d) => (text += d));
      res.on('end', () => resolve({ status: res.statusCode, body: text, json: () => JSON.parse(text) }));
    });
    r.on('error', reject);
    r.end(body === undefined ? undefined : JSON.stringify(body));
  });
}
const audit = (c = ctx) => fs.readFileSync(c.cfg.auditFile, 'utf8');

test('status needs the token and shows nothing but the facts', async () => {
  assert.equal((await req('GET', '/smb', { token: null })).status, 401);
  const r = await req('GET', '/smb');
  assert.equal(r.status, 200);
  const s = r.json();
  assert.equal(s.running, false);
  assert.equal(s.include_present, true);
  assert.equal(s.user, null);
  assert.deepEqual(s.shares.map((x) => [x.name, x.enabled, x.writable, x.can_set_writable]), [['library', false, false, true], ['exports', false, false, false], ['inbox', false, true, false]]);
  assert.ok(s.shares.every((x) => x.path_exists && x.path_error === null));
});

test('a share cannot be enabled before the share user exists, and nothing is run or written', async () => {
  host.clear();
  const r = await req('POST', '/smb/shares/library', { body: { enabled: true } });
  assert.equal(r.status, 422);
  assert.equal(r.json().error, 'no_user');
  assert.ok(!fs.existsSync(conf));
  assert.ok(!host.calls().some((c) => ['testparm', 'smbcontrol'].includes(c.cmd)));
});

test('changes need the header; unknown shares, methods and bodies are refused', async () => {
  assert.equal((await req('POST', '/smb/shares/library', { body: { enabled: true }, headers: {} })).status, 403);
  assert.equal((await req('POST', '/smb/user', { headers: {} })).status, 403);
  assert.equal((await req('POST', '/smb/shares/nope', { body: { enabled: true } })).status, 404);
  assert.equal((await req('POST', '/smb/shares/..%2Fetc', { body: { enabled: true } })).status, 404);
  assert.equal((await req('GET', '/smb/shares/library')).status, 405);
  assert.equal((await req('PUT', '/smb/user')).status, 405);
  assert.equal((await req('POST', '/smb/shares/library', { body: { enabled: 'yes' } })).status, 422);
  assert.equal((await req('POST', '/smb/shares/exports', { body: { enabled: true, writable: true } })).status, 422);
  assert.equal((await req('POST', '/smb/shares/library', { body: { enabled: true, writable: 'x' } })).status, 422);
  assert.equal((await req('POST', '/smb/shares/library', { headers: { 'x-remoment-manager': '1' }, body: undefined })).status, 422);
});

test('wrong Host is refused', async () => {
  const r = await new Promise((resolve) => {
    http.get({ host: '127.0.0.1', port: ctx.port, path: '/smb', headers: { host: 'evil.example', authorization: `Bearer ${ctx.token}` } }, (res) => { res.resume(); resolve(res.statusCode); });
  });
  assert.equal(r, 403);
});

test('creating the share user: account, Samba password on stdin, shown once, never logged or stored', async () => {
  host.clear();
  const r = await req('POST', '/smb/user');
  assert.equal(r.status, 200);
  const j = r.json();
  assert.equal(j.user, 'remoment-smb');
  assert.equal(j.created, true);
  assert.match(j.password, /^[A-Za-z0-9]{24}$/);

  const calls = host.calls();
  const add = calls.find((c) => c.cmd === 'useradd');
  assert.deepEqual(add.args, ['--system', '--no-create-home', '--shell', '/usr/sbin/nologin', '--user-group', '--', 'remoment-smb']);
  const pw = calls.find((c) => c.cmd === 'smbpasswd');
  assert.deepEqual(pw.args, ['-a', '-s', 'remoment-smb']);
  assert.equal(pw.stdin, `${j.password}\n${j.password}\n`);
  // The password is not an argument anywhere and is in no file the manager keeps.
  assert.ok(!JSON.stringify(calls.map((c) => c.args)).includes(j.password));
  assert.ok(!audit().includes(j.password));
  assert.ok(!fs.readFileSync(ctx.cfg.smb.stateFile, 'utf8').includes(j.password));
  assert.match(audit(), /"action":"smb user".*"result":"created"/);
  // Status knows the user but not the password.
  const s = await req('GET', '/smb');
  assert.equal(s.json().user.name, 'remoment-smb');
  assert.ok(!s.body.includes(j.password));
});

test('a second call resets the password of the user the manager made', async () => {
  const before = host.calls().length;
  const r = await req('POST', '/smb/user');
  const j = r.json();
  assert.equal(j.created, false);
  assert.ok(!host.calls().slice(before).some((c) => c.cmd === 'useradd'));
  assert.match(audit(), /password reset/);
});

test('enabling a share renders the fragment, tests it first, then reloads', async () => {
  host.clear();
  const r = await req('POST', '/smb/shares/library', { body: { enabled: true } });
  assert.equal(r.status, 200);
  const text = fs.readFileSync(conf, 'utf8');
  assert.match(text, /\[library\]/);
  assert.match(text, /path = .*library/);
  assert.match(text, /guest ok = no/);
  assert.match(text, /valid users = remoment-smb/);
  assert.match(text, /read only = yes/);
  assert.ok(!/\[inbox\]|\[exports\]/.test(text));
  assert.equal(fs.statSync(conf).mode & 0o777, 0o644);
  const calls = host.calls();
  const tp = calls.find((c) => c.cmd === 'testparm');
  assert.deepEqual(tp.args.slice(0, 2), ['-s', '--suppress-prompt']);
  assert.match(tp.file, /\[library\]/); // the candidate was tested, not the live file
  // Samba was not running: it is started, not reloaded.
  assert.deepEqual(calls.filter((c) => c.cmd === 'systemctl').map((c) => c.args), [['is-active', 'smbd'], ['start', 'smbd']]);
  assert.match(audit(), /"action":"smb share","service":"library","result":"enabled"/);
});

test('with Samba running a change reloads the config; inbox is read-write, exports read-only', async () => {
  host.clear();
  assert.equal((await req('POST', '/smb/shares/inbox', { body: { enabled: true } })).status, 200);
  assert.equal((await req('POST', '/smb/shares/exports', { body: { enabled: true } })).status, 200);
  const text = fs.readFileSync(conf, 'utf8');
  const section = (n) => text.split(/^\[/m).find((x) => x.startsWith(n + ']'));
  assert.match(section('inbox'), /read only = no/);
  assert.match(section('inbox'), /force create mode = 0664/);
  assert.match(section('exports'), /read only = yes/);
  assert.ok(!/force create mode/.test(section('exports')));
  assert.deepEqual(host.calls().filter((c) => c.cmd === 'smbcontrol').map((c) => c.args), [['smbd', 'reload-config'], ['smbd', 'reload-config']]);
});

test('the library can be made writable, and back', async () => {
  await req('POST', '/smb/shares/library', { body: { enabled: true, writable: true } });
  assert.match(fs.readFileSync(conf, 'utf8').split(/^\[/m).find((x) => x.startsWith('library]')), /read only = no/);
  const s = (await req('GET', '/smb')).json().shares.find((x) => x.name === 'library');
  assert.equal(s.writable, true);
  await req('POST', '/smb/shares/library', { body: { enabled: true, writable: false } });
  assert.match(fs.readFileSync(conf, 'utf8').split(/^\[/m).find((x) => x.startsWith('library]')), /read only = yes/);
});

test('disabling a share removes its section', async () => {
  assert.equal((await req('POST', '/smb/shares/inbox', { body: { enabled: false } })).status, 200);
  assert.ok(!/\[inbox\]/.test(fs.readFileSync(conf, 'utf8')));
  assert.match(audit(), /"service":"inbox","result":"disabled"/);
});

test('GET /smb/config renders the current choices without writing', async () => {
  const before = fs.readFileSync(conf, 'utf8');
  const r = await req('GET', '/smb/config');
  assert.equal(r.json().config, before);
});

test('dry run renders and plans, and changes nothing', async () => {
  const before = fs.readFileSync(conf, 'utf8');
  const state = fs.readFileSync(ctx.cfg.smb.stateFile, 'utf8');
  host.clear();
  const r = await req('POST', '/smb/shares/inbox?dry_run=1', { body: { enabled: true } });
  assert.equal(r.status, 200);
  const j = r.json();
  assert.equal(j.dry_run, true);
  assert.match(j.config, /\[inbox\]/);
  assert.ok(j.actions.length >= 2);
  assert.equal(fs.readFileSync(conf, 'utf8'), before);
  assert.equal(fs.readFileSync(ctx.cfg.smb.stateFile, 'utf8'), state);
  assert.deepEqual(host.calls(), []);
  const u = await req('POST', '/smb/user?dry_run=1');
  assert.equal(u.json().dry_run, true);
  assert.equal(u.json().password, undefined);
  assert.deepEqual(host.calls().filter((c) => c.cmd !== 'id'), []);
});

test('a configuration testparm rejects is never installed', async () => {
  const before = fs.readFileSync(conf, 'utf8');
  host.set('testparm-fail', 'bad line');
  const r = await req('POST', '/smb/shares/inbox', { body: { enabled: true } });
  host.unset('testparm-fail');
  assert.equal(r.status, 502);
  assert.equal(r.json().error, 'config_invalid');
  assert.equal(fs.readFileSync(conf, 'utf8'), before);
  assert.ok(!fs.existsSync(conf + '.new'));
  assert.match(audit(), /failed \(config_invalid\)/);
  assert.ok(!(await req('GET', '/smb')).json().shares.find((x) => x.name === 'inbox').enabled);
});

test('a failed reload is reported', async () => {
  host.set('smbcontrol-fail', 'no smbd');
  const r = await req('POST', '/smb/shares/inbox', { body: { enabled: true } });
  host.unset('smbcontrol-fail');
  assert.equal(r.status, 502);
  assert.equal(r.json().error, 'reload_failed');
});

test('status reports running Samba and sessions without addresses', async () => {
  host.set('smbstatus.json', JSON.stringify({ sessions: { 1: { username: 'remoment-smb', remote_machine: 'laptop', hostname: 'ipv4:192.168.1.5:5000' } }, tcons: { 7: { service: 'inbox' } } }));
  const s = (await req('GET', '/smb')).json();
  assert.equal(s.running, true);
  assert.deepEqual(s.sessions, { count: 1, by_share: { inbox: 1 }, data: [{ user: 'remoment-smb', machine: 'laptop' }] });
  assert.ok(!JSON.stringify(s).includes('192.168'));
});

test('removing the user is refused while a share is on, then removes it', async () => {
  const r = await req('DELETE', '/smb/user');
  assert.equal(r.status, 409);
  assert.equal(r.json().error, 'shares_enabled');
  for (const n of ['library', 'exports', 'inbox']) await req('POST', `/smb/shares/${n}`, { body: { enabled: false } });
  host.clear();
  const d = await req('DELETE', '/smb/user');
  assert.equal(d.status, 200);
  const calls = host.calls();
  assert.deepEqual(calls.find((c) => c.cmd === 'smbpasswd').args, ['-x', 'remoment-smb']);
  assert.deepEqual(calls.find((c) => c.cmd === 'userdel').args, ['--', 'remoment-smb']);
  assert.equal((await req('GET', '/smb')).json().user, null);
  assert.equal((await req('DELETE', '/smb/user')).status, 404);
});

test('an existing system account the manager did not create is never touched', async () => {
  host.set('user-exists');
  host.clear();
  const r = await req('POST', '/smb/user');
  host.unset('user-exists');
  assert.equal(r.status, 409);
  assert.equal(r.json().error, 'user_exists');
  assert.ok(!host.calls().some((c) => ['useradd', 'smbpasswd', 'userdel'].includes(c.cmd)));
});

test('a failing smbpasswd is reported without leaking the password', async () => {
  host.unset('user-exists');
  host.set('smbpasswd-fail', 'nope');
  const r = await req('POST', '/smb/user');
  host.unset('smbpasswd-fail');
  assert.equal(r.status, 502);
  assert.equal(r.json().password, undefined);
  assert.match(audit(), /failed \(smbpasswd\)/);
});

test('paths: only plain absolute non-system paths', () => {
  assert.equal(pathError('/srv/library'), null);
  assert.equal(pathError('/srv/my music'), null);
  for (const bad of ['/', '/etc', '/usr/', 'relative', '/srv/a\nb', '/srv/a;b', '/srv/../etc', '/srv/a#b', '/srv/a$b']) assert.notEqual(pathError(bad), null, bad);
});

test('a bad share path and a bad user name stop an enable', async () => {
  const bad = await start({ REMOMENT_SMB_INBOX_DIR: '/etc' }, 'home-badpath');
  try {
    host.unset('user-exists');
    assert.equal((await req('POST', '/smb/user', { c: bad, token: bad.token })).status, 200);
    const r = await req('POST', '/smb/shares/inbox', { body: { enabled: true }, c: bad, token: bad.token });
    assert.equal(r.status, 422);
    assert.equal(r.json().error, 'bad_path');
  } finally { bad.m.server.close(); }
  const evil = await start({ REMOMENT_SMB_USER: 'root; rm -rf /' }, 'home-baduser');
  try {
    host.clear();
    const r = await req('POST', '/smb/user', { c: evil, token: evil.token });
    assert.equal(r.status, 422);
    assert.equal(r.json().error, 'bad_setup');
    assert.deepEqual(host.calls(), []);
  } finally { evil.m.server.close(); }
});

test('global dry-run mode touches nothing, whatever is asked', async () => {
  const dry = await start({ REMOMENT_SMB_DRY_RUN: '1' }, 'home-dry');
  try {
    host.unset('user-exists');
    host.clear();
    const before = fs.readFileSync(conf, 'utf8');
    assert.equal((await req('GET', '/smb', { c: dry, token: dry.token })).json().dry_run, true);
    const u = await req('POST', '/smb/user', { c: dry, token: dry.token });
    assert.equal(u.json().dry_run, true);
    assert.equal(fs.readFileSync(conf, 'utf8'), before);
    assert.deepEqual(host.calls().filter((c) => c.cmd !== 'id' && c.cmd !== 'systemctl' && c.cmd !== 'smbstatus'), []);
  } finally { dry.m.server.close(); }
});

test('the CLI: status, dry-run enable and user create show the password once', async () => {
  const env = { ...process.env, REMOMENT_MANAGER_HOME: ctx.cfg.home, REMOMENT_MANAGER_PORT: String(ctx.port) };
  const cli = path.join(__dirname, '..', 'bin', 'remoment-manager');
  // Async: the manager runs in this very process, so a synchronous child would wait for a server that cannot answer.
  const out = async (...a) => (await execFileAsync(process.execPath, [cli, ...a], { env })).stdout;
  assert.match(await out('smb'), /Samba/);
  host.unset('user-exists');
  const created = await out('smb', 'user', 'create');
  assert.match(created, /Password:\s+[A-Za-z0-9]{24}/);
  const dry = await out('smb', 'enable', 'inbox', '--dry-run');
  assert.match(dry, /\[inbox\]/);
  assert.match(dry, /Dry run/);
  assert.match(await out('smb', 'config'), /Managed by the ReMoment Manager/);
  assert.ok(!(await out('smb', 'status', '--json')).includes('"password"'));
});
