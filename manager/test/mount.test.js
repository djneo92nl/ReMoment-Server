'use strict';
// NAS mounts (manager/mount.js): the generators, the validation, the dry run and confirm, the status probe and the
// redaction. Everything runs against fake `systemctl` / `systemd-analyze` and temp directories; no mount is made.
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
const { escapePath, renderMount, validate, mountConfig } = require('../mount');
const fake = require('./fake-mount');

const tmp = fs.mkdtempSync(path.join(os.tmpdir(), 'rm-mount-'));
const fakeDir = path.join(tmp, 'fake');
fs.mkdirSync(fakeDir);
const host = fake.install(fakeDir);
process.env.PATH = `${host.bin}${path.delimiter}${process.env.PATH}`;

const PASSWORD = 'S3cret-Pw!9x';
const unitDir = path.join(tmp, 'systemd');
const credDir = path.join(tmp, 'creds');
const base = path.join(tmp, 'mnt');
const mountinfo = path.join(tmp, 'mountinfo');
fs.mkdirSync(unitDir);
fs.writeFileSync(mountinfo, '');

function freePort() {
  return new Promise((resolve) => { const s = net.createServer().listen(0, '127.0.0.1', () => { const p = s.address().port; s.close(() => resolve(p)); }); });
}
function start(extraEnv = {}, name = 'home') {
  return freePort().then(async (port) => {
    const home = path.join(tmp, name);
    const cfg = loadConfig({
      REMOMENT_MANAGER_HOME: home, REMOMENT_MANAGER_PORT: String(port), REMOMENT_PROJECT_DIR: tmp, REMOMENT_SAMPLE_SECONDS: '0',
      REMOMENT_MOUNT_BASE: base, REMOMENT_MOUNT_UNIT_DIR: unitDir, REMOMENT_MOUNT_CRED_DIR: credDir, REMOMENT_MOUNTINFO_FILE: mountinfo,
      REMOMENT_MOUNT_CACHE_SECONDS: '0', ...extraEnv,
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
const mountPoint = (name) => path.join(base, name);
// The tests mount under a temp directory, so the unit names are those of that path (escaped like systemd does).
const U = (name) => escapePath(mountPoint(name));
const setMounted = (name, on) => fs.writeFileSync(mountinfo, on ? `36 25 0:45 / ${mountPoint(name)} rw,relatime shared:5 - cifs //nas/music rw,vers=3.0\n` : '');
const NAS = { name: 'nas', host: '192.168.1.20', share: 'music', user: 'remko', password: PASSWORD };

// --- Generators and validation (pure) ---------------------------------------------------------------------------------

test('systemd path escaping: the unit name is the escaped mount path', () => {
  assert.equal(escapePath('/mnt/remoment/nas'), 'mnt-remoment-nas');
  assert.equal(escapePath('/mnt/remoment/my-nas'), 'mnt-remoment-my\\x2dnas'); // `systemd-escape -p`
  assert.equal(escapePath('/srv/a.b/c_d'), 'srv-a.b-c_d');
  assert.equal(escapePath('/.hidden/x'), '\\x2ehidden-x');
  assert.equal(escapePath('/'), '-');
});

test('the generated units: automount and mount, _netdev and nofail, read-only by default, credentials by file', () => {
  const mcfg = mountConfig({ REMOMENT_MOUNT_UID: '1000', REMOMENT_MOUNT_GID: '1000' }, '/h');
  const v = validate({ ...NAS, name: 'my-nas', domain: 'HOME', version: '3.0' }, { needPassword: true });
  assert.equal(v.error, undefined);
  const r = renderMount(v.mount, mcfg);
  assert.equal(r.mountFile, 'mnt-remoment-my\\x2dnas.mount');
  assert.equal(r.automountFile, 'mnt-remoment-my\\x2dnas.automount');
  assert.match(r.mountUnit, /^What=\/\/192\.168\.1\.20\/music$/m);
  assert.match(r.mountUnit, /^Where=\/mnt\/remoment\/my-nas$/m);
  assert.match(r.mountUnit, /^Type=cifs$/m);
  const opts = /^Options=(.*)$/m.exec(r.mountUnit)[1].split(',');
  for (const o of ['credentials=/etc/remoment-manager/credentials/my-nas.cred', 'ro', 'vers=3.0', 'uid=1000', 'gid=1000', '_netdev', 'nofail', 'soft', 'nosuid', 'nodev', 'noexec']) assert.ok(opts.includes(o), o);
  assert.ok(!opts.includes('rw'), 'read-only unless asked');
  assert.match(r.mountUnit, /^After=network-online\.target$/m);
  assert.match(r.automountUnit, /^\[Automount\]$/m);
  assert.match(r.automountUnit, /^Where=\/mnt\/remoment\/my-nas$/m);
  assert.match(r.automountUnit, /^Before=remote-fs\.target docker\.service$/m);
  assert.match(r.automountUnit, /^WantedBy=remote-fs\.target$/m);
  assert.match(r.fstab, /^\/\/192\.168\.1\.20\/music \/mnt\/remoment\/my-nas cifs .*_netdev,nofail,x-systemd\.automount.* 0 0$/);
  // Nothing in what is shown or written to a unit carries the password; the credentials preview hides it.
  assert.equal(r.credentials, 'username=remko\npassword=***\ndomain=HOME\n');
  for (const text of [r.mountUnit, r.automountUnit, r.fstab, r.credentials]) assert.ok(!text.includes(PASSWORD));
  assert.equal(r.credentialsContent(PASSWORD), `username=remko\npassword=${PASSWORD}\ndomain=HOME\n`);
  // Writable on request; a share without a user is a guest mount and has no credentials file.
  const w = renderMount(validate({ name: 'open', host: 'nas.local', share: 'pub', writable: true }, { needPassword: true }).mount, mountConfig({}, '/h'));
  assert.match(w.mountUnit, /^Options=guest,rw,/m);
  assert.equal(w.credentials, null);
});

test('validation refuses what could reach a unit file, a path or a command', () => {
  const bad = (patch, re) => {
    const v = validate({ ...NAS, ...patch }, { needPassword: true });
    assert.match(v.error || '', re, JSON.stringify(patch));
  };
  bad({ name: 'Nas' }, /^name/);
  bad({ name: '../etc' }, /^name/);
  bad({ name: 'a'.repeat(40) }, /^name/);
  bad({ name: 'nas-' }, /^name/);
  bad({ host: 'nas; reboot' }, /^host/);
  bad({ host: 'nas lan' }, /^host/);
  bad({ host: '-oProxy' }, /^host/);
  bad({ host: '' }, /^host/);
  bad({ share: '../../etc' }, /^share/);
  bad({ share: 'mu sic' }, /^share/);
  bad({ share: 'music\nOptions=suid' }, /^share/);
  bad({ share: 'a/b/c/d/e' }, /^share/);
  bad({ user: 're mko' }, /^user/);
  bad({ user: 'remko,uid=0' }, /^user/);
  bad({ domain: 'a b' }, /^domain/);
  bad({ password: 'line\nbreak' }, /^password/);
  bad({ password: ' padded ' }, /^password/);
  bad({ password: 'x'.repeat(200) }, /^password/);
  bad({ password: undefined }, /needs its password/);
  bad({ writable: 'yes' }, /writable/);
  bad({ version: '1.0' }, /^version/); // no SMB1
  bad({ version: '3.0,rw' }, /^version/);
  assert.match(validate({ ...NAS, user: undefined, password: PASSWORD }, { needPassword: true }).error, /needs a user/);
  assert.match(validate(null, { needPassword: true }).error, /JSON object/);
  // `$` shares (hidden admin shares) and sub folders are fine; a preview needs no password.
  assert.equal(validate({ ...NAS, share: 'music$/albums' }, { needPassword: true }).error, undefined);
  assert.equal(validate({ ...NAS, password: undefined }, { needPassword: false }).error, undefined);
});

// --- HTTP: auth, dry run, confirm, apply ------------------------------------------------------------------------------

test('every mount call needs the token; changes need the header; wrong methods and paths are refused', async () => {
  assert.equal((await req('GET', '/mounts', { token: null })).status, 401);
  assert.equal((await req('POST', '/mounts', { body: NAS, headers: {} })).status, 403);
  assert.equal((await req('DELETE', '/mounts/nas', { headers: {} })).status, 403);
  assert.equal((await req('PUT', '/mounts')).status, 405);
  assert.equal((await req('GET', '/mounts/nas')).status, 405);
  assert.equal((await req('GET', '/mounts/nas/active')).status, 405);
  assert.equal((await req('POST', '/mounts/nas/other', { body: {} })).status, 404);
  assert.equal((await req('DELETE', '/mounts/nas')).status, 404); // unknown mount
  assert.equal((await req('POST', '/mounts/nas/active', { body: { active: true } })).status, 404);
  const wrongHost = await new Promise((resolve) => {
    http.get({ host: '127.0.0.1', port: ctx.port, path: '/mounts', headers: { host: 'evil.example', authorization: `Bearer ${ctx.token}` } }, (res) => { res.resume(); resolve(res.statusCode); });
  });
  assert.equal(wrongHost, 403);
  assert.deepEqual(host.calls(), []);
});

test('an empty status lists nothing', async () => {
  const r = (await req('GET', '/mounts')).json();
  assert.deepEqual(r.data, []);
  assert.deepEqual(r.problems, []);
  assert.equal(r.method, 'systemd');
});

test('the dry run plans and shows the units, and writes, runs and saves nothing', async () => {
  host.clear();
  const r = await req('POST', '/mounts?dry_run=1', { body: { ...NAS, password: undefined } }); // a preview needs no password
  assert.equal(r.status, 200);
  const j = r.json();
  assert.equal(j.dry_run, true);
  assert.match(j.plan, /^[0-9a-f]{16}$/);
  assert.match(j.config.mount_unit, /What=\/\/192\.168\.1\.20\/music/);
  assert.match(j.config.fstab, /x-systemd\.automount/);
  assert.match(j.config.credentials, /password=\*\*\*/);
  assert.ok(j.actions.length >= 4);
  assert.deepEqual(host.calls(), []);
  assert.deepEqual(fs.readdirSync(unitDir), []);
  assert.equal(fs.existsSync(credDir), false);
  assert.equal(fs.existsSync(mountPoint('nas')), false);
  assert.equal(fs.existsSync(ctx.cfg.mount.stateFile), false);
  // The same plan again is the same token; another host is another plan.
  assert.equal((await req('POST', '/mounts?dry_run=1', { body: { ...NAS, password: undefined } })).json().plan, j.plan);
  assert.notEqual((await req('POST', '/mounts?dry_run=1', { body: { ...NAS, host: '192.168.1.21' } })).json().plan, j.plan);
  // The password does not change the plan (the token never covers it) and is not in the answer.
  assert.equal((await req('POST', '/mounts?dry_run=1', { body: NAS })).json().plan, j.plan);
  assert.ok(!r.body.includes(PASSWORD));
});

test('applying needs the confirm token of the dry run', async () => {
  host.clear();
  const plan = (await req('POST', '/mounts?dry_run=1', { body: NAS })).json().plan;
  const none = await req('POST', '/mounts', { body: NAS });
  assert.equal(none.status, 422);
  assert.equal(none.json().error, 'confirm_required');
  assert.equal(none.json().plan, plan);
  const wrong = await req('POST', '/mounts', { body: { ...NAS, confirm: 'ffffffffffffffff' } });
  assert.equal(wrong.status, 409);
  assert.equal(wrong.json().error, 'plan_changed');
  // A plan made for another host is not valid for this form.
  const other = (await req('POST', '/mounts?dry_run=1', { body: { ...NAS, host: '10.0.0.9' } })).json().plan;
  assert.equal((await req('POST', '/mounts', { body: { ...NAS, confirm: other } })).status, 409);
  // And a user without a password cannot be applied even with the right token.
  assert.equal((await req('POST', '/mounts', { body: { ...NAS, password: undefined, confirm: plan } })).status, 422);
  assert.deepEqual(host.calls(), []);
  assert.deepEqual(fs.readdirSync(unitDir), []);
});

test('applying writes the credentials root-only, installs verified units, enables the automount, audits, and keeps the password nowhere else', async () => {
  host.clear();
  const plan = (await req('POST', '/mounts?dry_run=1', { body: NAS })).json().plan;
  const r = await req('POST', '/mounts', { body: { ...NAS, confirm: plan } });
  assert.equal(r.status, 200, r.body);
  assert.deepEqual(r.json(), { status: 'ok', name: 'nas', path: mountPoint('nas'), updated: false });

  const cred = path.join(credDir, 'nas.cred');
  assert.equal(fs.readFileSync(cred, 'utf8'), `username=remko\npassword=${PASSWORD}\n`);
  assert.equal(fs.statSync(cred).mode & 0o777, 0o600);
  assert.equal(fs.statSync(credDir).mode & 0o777, 0o700);
  assert.equal(fs.statSync(mountPoint('nas')).isDirectory(), true);
  assert.deepEqual(fs.readdirSync(unitDir).sort(), [`${U("nas")}.automount`, `${U("nas")}.mount`]);
  assert.equal(fs.statSync(path.join(unitDir, `${U("nas")}.mount`)).mode & 0o777, 0o644);
  assert.match(fs.readFileSync(path.join(unitDir, `${U("nas")}.mount`), 'utf8'), new RegExp(`credentials=${cred.replace(/[.*+?^${}()|[\]\\]/g, '\\$&')}`));

  // Verified before installed (the staged files, with the same names), then reload, then enable --now.
  const calls = host.calls();
  assert.deepEqual(calls.map((c) => [c.cmd, c.args[0]]), [['systemd-analyze', 'verify'], ['systemctl', 'daemon-reload'], ['systemctl', 'enable']]);
  assert.match(calls[0].files[0], /^# Managed by the ReMoment Manager/);
  assert.ok(calls[0].args.slice(1).every((f) => f.includes('.remoment-staging')));
  assert.deepEqual(calls[2].args, ['enable', '--now', `${U("nas")}.automount`]);
  assert.equal(fs.existsSync(path.join(unitDir, '.remoment-staging')), false);

  assert.match(audit(), /"action":"mount add","service":"nas","result":"enabled"/);

  // The password is in the credentials file and nowhere else: not in any argument, the audit log, the state, a unit or an answer.
  const everything = [JSON.stringify(calls), audit(), fs.readFileSync(ctx.cfg.mount.stateFile, 'utf8'), r.body,
    ...fs.readdirSync(unitDir).map((f) => fs.readFileSync(path.join(unitDir, f), 'utf8')), JSON.stringify((await req('GET', '/mounts')).json())].join('\n');
  assert.ok(!everything.includes(PASSWORD));
  assert.equal(fs.statSync(ctx.cfg.mount.stateFile).mode & 0o777, 0o600);
  assert.deepEqual(Object.keys(JSON.parse(fs.readFileSync(ctx.cfg.mount.stateFile, 'utf8')).mounts.nas).sort(), ['created_at', 'domain', 'host', 'last_ok_at', 'name', 'share', 'user', 'version', 'writable']);
});

test('units that systemd does not accept are never installed, and a failing command cannot leak the password', async () => {
  host.clear();
  host.set('systemd-analyze-fail', `bad unit, password was ${PASSWORD}`);
  const form = { ...NAS, name: 'broken' };
  const plan = (await req('POST', '/mounts?dry_run=1', { body: form })).json().plan;
  const r = await req('POST', '/mounts', { body: { ...form, confirm: plan } });
  host.unset('systemd-analyze-fail');
  assert.equal(r.status, 502);
  assert.equal(r.json().error, 'config_invalid');
  assert.ok(!r.body.includes(PASSWORD));
  assert.ok(r.body.includes('***'));
  assert.deepEqual(fs.readdirSync(unitDir).filter((f) => f.includes('broken')), []);
  assert.equal(fs.existsSync(path.join(credDir, 'broken.cred')), false, 'the credentials of a rejected mount are removed');
  assert.deepEqual(host.calls().filter((c) => c.cmd === 'systemctl'), []);
  assert.match(audit(), /"service":"broken","result":"failed \(config_invalid\)"/);
  assert.ok(!audit().includes(PASSWORD));
  assert.equal(Object.hasOwn(JSON.parse(fs.readFileSync(ctx.cfg.mount.stateFile, 'utf8')).mounts, 'broken'), false);
});

test('a failing enable is reported without the password', async () => {
  host.clear();
  const form = { ...NAS, name: 'noenable' };
  const plan = (await req('POST', '/mounts?dry_run=1', { body: form })).json().plan;
  host.set('systemctl-fail', `Unit failed with ${PASSWORD}`);
  const r = await req('POST', '/mounts', { body: { ...form, confirm: plan } });
  host.unset('systemctl-fail');
  assert.equal(r.status, 502);
  assert.ok(['daemon_reload_failed', 'enable_failed'].includes(r.json().error));
  assert.ok(!r.body.includes(PASSWORD));
  assert.ok(!audit().includes(PASSWORD));
  await req('DELETE', `/mounts/noenable?confirm=${(await req('DELETE', '/mounts/noenable?dry_run=1')).json().plan}`); // tidy up
});

test('a guest mount (no user) has no credentials file and needs no password', async () => {
  host.clear();
  const form = { name: 'open', host: 'nas.local', share: 'pub' };
  const plan = (await req('POST', '/mounts?dry_run=1', { body: form })).json().plan;
  const r = await req('POST', '/mounts', { body: { ...form, confirm: plan } });
  assert.equal(r.status, 200, r.body);
  assert.equal(fs.existsSync(path.join(credDir, 'open.cred')), false);
  assert.match(fs.readFileSync(path.join(unitDir, `${U("open")}.mount`), 'utf8'), /^Options=guest,ro,/m);
});

test('a name is added again as an update: the mount is stopped so the new options apply', async () => {
  host.clear();
  const form = { ...NAS, version: '3.1.1', writable: true };
  const plan = (await req('POST', '/mounts?dry_run=1', { body: form })).json().plan;
  const r = await req('POST', '/mounts', { body: { ...form, confirm: plan } });
  assert.equal(r.status, 200);
  assert.equal(r.json().updated, true);
  const stop = host.calls().find((c) => c.cmd === 'systemctl' && c.args[0] === 'stop');
  assert.deepEqual(stop.args, ['stop', `${U("nas")}.automount`, `${U("nas")}.mount`]);
  assert.match(fs.readFileSync(path.join(unitDir, `${U("nas")}.mount`), 'utf8'), /^Options=credentials=.*,rw,vers=3\.1\.1,/m);
  assert.match(audit(), /"service":"nas","result":"updated"/);
});

// --- Status: mounted, readable, lost ---------------------------------------------------------------------------------

test('status: not mounted, ok, unreadable, lost and switched off', async () => {
  const get = async () => (await req('GET', '/mounts')).json().data.find((m) => m.name === 'nas');
  const dir = mountPoint('nas');

  setMounted('nas', false);
  let m = await get();
  assert.equal(m.state, 'not_mounted');
  assert.equal(m.mounted, false);
  assert.equal(m.lost, false, 'it never worked, so it is not "lost"');
  assert.match(m.error, /not mounted/);

  setMounted('nas', true);
  m = await get();
  assert.equal(m.state, 'ok');
  assert.equal(m.active, true);
  assert.equal(m.mounted && m.readable, true);
  assert.equal(m.lost, false);
  assert.ok(m.last_ok_at);
  assert.equal(m.path, dir);
  assert.equal(m.unit, `${U("nas")}.automount`);
  assert.deepEqual(Object.keys(m).filter((k) => /pass|secret/i.test(k)), []);

  // Mounted but the directory cannot be listed (a stale handle): unreadable, and lost because it worked before.
  const hidden = `${dir}.gone`;
  fs.renameSync(dir, hidden);
  m = await get();
  fs.renameSync(hidden, dir);
  assert.equal(m.state, 'unreadable');
  assert.equal(m.lost, true);

  // The NAS goes away: the mount disappears from mountinfo.
  setMounted('nas', false);
  m = await get();
  assert.equal(m.state, 'not_mounted');
  assert.equal(m.lost, true);
  assert.match(audit(), /"action":"mount lost","service":"nas","result":"unreadable"|"action":"mount lost","service":"nas","result":"not_mounted"/);

  setMounted('nas', true);
  assert.equal((await get()).state, 'ok');
  assert.match(audit(), /"action":"mount back","service":"nas","result":"ok"/);

  // Switched off on purpose: inactive, never an alert.
  host.set('active-units', '');
  m = await get();
  assert.equal(m.state, 'inactive');
  assert.equal(m.lost, false);
  host.set('active-units', `${U("nas")}.automount\n${U("open")}.automount\n`);
});

test('a share that does not answer in time is reported, not waited for', async () => {
  const slow = await start({ REMOMENT_MOUNT_PROBE_SECONDS: '0.3' }, 'home-slow');
  try {
    // The same state file as the main manager: a copy of the mounts it knows.
    fs.copyFileSync(ctx.cfg.mount.stateFile, slow.cfg.mount.stateFile);
    host.set('ls-hang');
    const t0 = Date.now();
    const r = (await req('GET', '/mounts', { c: slow, token: slow.token })).json();
    assert.ok(Date.now() - t0 < 2500, 'answered before the hanging ls finished');
    const m = r.data.find((x) => x.name === 'nas');
    assert.equal(m.state, 'unreadable');
    assert.match(m.error, /did not answer/);
  } finally { host.unset('ls-hang'); slow.m.server.close(); }
});

// --- Switch on/off and remove ----------------------------------------------------------------------------------------

test('switching off stops the automount and unmounts; a share in use stays mounted and the automount comes back', async () => {
  const plan = async (active) => (await req('POST', '/mounts/nas/active?dry_run=1', { body: { active } })).json().plan;
  assert.equal((await req('POST', '/mounts/nas/active', { body: { active: 'no' } })).status, 422);
  assert.equal((await req('POST', '/mounts/nas/active', { body: { active: false } })).status, 422); // no confirm
  host.set('active-units', `${U("nas")}.automount\n`);
  setMounted('nas', true);

  host.set('stop-busy');
  host.clear();
  let r = await req('POST', '/mounts/nas/active', { body: { active: false, confirm: await plan(false) } });
  assert.equal(r.status, 409);
  assert.equal(r.json().error, 'mount_busy');
  assert.deepEqual(host.calls().map((c) => c.args.join(' ')), [`disable --now ${U("nas")}.automount`, `stop ${U("nas")}.mount`, `enable --now ${U("nas")}.automount`]);
  assert.match(audit(), /"action":"mount off","service":"nas","result":"failed \(busy\)"/);

  host.unset('stop-busy');
  setMounted('nas', false);
  host.set('active-units', `${U("nas")}.automount\n`);
  r = await req('POST', '/mounts/nas/active', { body: { active: false, confirm: await plan(false) } });
  assert.equal(r.status, 200, r.body);
  assert.equal((await req('GET', '/mounts')).json().data.find((m) => m.name === 'nas').state, 'inactive');
  assert.match(audit(), /"action":"mount off","service":"nas","result":"ok"/);

  r = await req('POST', '/mounts/nas/active', { body: { active: true, confirm: await plan(true) } });
  assert.equal(r.status, 200);
  assert.match(audit(), /"action":"mount on","service":"nas","result":"ok"/);
  assert.equal((await req('GET', '/mounts')).json().data.find((m) => m.name === 'nas').active, true);
});

test('removing needs the plan, refuses a busy share, and then deletes units, credentials, state and the empty folder', async () => {
  const plan = async (n) => (await req('DELETE', `/mounts/${n}?dry_run=1`)).json();
  const dry = await plan('nas');
  assert.equal(dry.dry_run, true);
  assert.ok(dry.actions.some((a) => a.includes('credentials')));
  assert.equal(fs.existsSync(path.join(credDir, 'nas.cred')), true, 'a dry run deletes nothing');

  assert.equal((await req('DELETE', '/mounts/nas')).status, 422);
  assert.equal((await req('DELETE', '/mounts/nas?confirm=0000000000000000')).status, 409);

  // In use: refused, the files stay.
  setMounted('nas', true);
  host.set('stop-busy');
  let r = await req('DELETE', `/mounts/nas?confirm=${dry.plan}`);
  host.unset('stop-busy');
  assert.equal(r.status, 409);
  assert.equal(fs.existsSync(path.join(unitDir, `${U("nas")}.mount`)), true);
  assert.equal(fs.existsSync(path.join(credDir, 'nas.cred')), true);
  assert.match(audit(), /"action":"mount remove","service":"nas","result":"failed \(busy\)"/);

  setMounted('nas', false);
  host.clear();
  r = await req('DELETE', `/mounts/nas?confirm=${dry.plan}`);
  assert.equal(r.status, 200, r.body);
  assert.equal(fs.existsSync(path.join(unitDir, `${U("nas")}.mount`)), false);
  assert.equal(fs.existsSync(path.join(unitDir, `${U("nas")}.automount`)), false);
  assert.equal(fs.existsSync(path.join(credDir, 'nas.cred')), false);
  assert.equal(fs.existsSync(mountPoint('nas')), false);
  assert.equal((await req('GET', '/mounts')).json().data.some((m) => m.name === 'nas'), false);
  assert.ok(host.calls().some((c) => c.cmd === 'systemctl' && c.args[0] === 'daemon-reload'));
  assert.match(audit(), /"action":"mount remove","service":"nas","result":"removed"/);
  assert.equal((await req('DELETE', `/mounts/nas?confirm=${dry.plan}`)).status, 404);
});

test('a mount point with files in it is never emptied: removing keeps the folder', async () => {
  const dry = (await req('DELETE', '/mounts/open?dry_run=1')).json();
  fs.writeFileSync(path.join(mountPoint('open'), 'keep.txt'), 'x');
  const r = await req('DELETE', `/mounts/open?confirm=${dry.plan}`);
  assert.equal(r.status, 200);
  assert.equal(fs.readFileSync(path.join(mountPoint('open'), 'keep.txt'), 'utf8'), 'x');
});

// --- Setup problems, global dry run, one change at a time -------------------------------------------------------------

test('a bad base path or owner id stops every change', async () => {
  const bad = await start({ REMOMENT_MOUNT_BASE: '/etc', REMOMENT_MOUNT_UID: 'root' }, 'home-bad');
  try {
    const s = (await req('GET', '/mounts', { c: bad, token: bad.token })).json();
    assert.equal(s.problems.length, 2);
    const r = await req('POST', '/mounts?dry_run=1', { c: bad, token: bad.token, body: NAS });
    assert.equal(r.status, 422);
    assert.equal(r.json().error, 'bad_setup');
  } finally { bad.m.server.close(); }
  const spaced = await start({ REMOMENT_MOUNT_BASE: '/mnt/my nas' }, 'home-spaced');
  try {
    assert.equal((await req('GET', '/mounts', { c: spaced, token: spaced.token })).json().problems.length, 1);
  } finally { spaced.m.server.close(); }
});

test('global dry-run mode turns every change into a plan', async () => {
  const dry = await start({ REMOMENT_MOUNT_DRY_RUN: '1' }, 'home-dry');
  try {
    host.clear();
    const before = fs.readdirSync(unitDir).sort();
    assert.equal((await req('GET', '/mounts', { c: dry, token: dry.token })).json().dry_run, true);
    const r = await req('POST', '/mounts', { c: dry, token: dry.token, body: { ...NAS, name: 'dryone', confirm: 'whatever' } });
    assert.equal(r.json().dry_run, true);
    assert.equal((await req('DELETE', '/mounts/dryone', { c: dry, token: dry.token })).status, 404);
    assert.deepEqual(fs.readdirSync(unitDir).sort(), before);
    assert.deepEqual(host.calls(), []);
  } finally { dry.m.server.close(); }
});

test('another change running answers 409', async () => {
  // An SMB-style lock: start a change that holds the lock (a restart through the fake docker is not available here),
  // so use two applies at once: the second sees the first one's lock or runs after it; both must end in 200 or 409.
  const form = { ...NAS, name: 'race1' };
  const plan = (await req('POST', '/mounts?dry_run=1', { body: form })).json().plan;
  const [a, b] = await Promise.all([
    req('POST', '/mounts', { body: { ...form, confirm: plan } }),
    req('POST', '/mounts', { body: { ...form, confirm: plan } }),
  ]);
  assert.ok([a.status, b.status].every((s) => s === 200 || s === 409), `${a.status} ${b.status}`);
  assert.ok([a.status, b.status].includes(200));
  await req('DELETE', `/mounts/race1?confirm=${(await req('DELETE', '/mounts/race1?dry_run=1')).json().plan}`);
});

// --- CLI -------------------------------------------------------------------------------------------------------------

test('the CLI: plan first, password from stdin only, apply with --confirm, status, remove', async () => {
  const env = { ...process.env, REMOMENT_MANAGER_HOME: ctx.cfg.home, REMOMENT_MANAGER_PORT: String(ctx.port) };
  const cli = path.join(__dirname, '..', 'bin', 'remoment-manager');
  const run = (args, input) => new Promise((resolve) => {
    const child = execFile(process.execPath, [cli, ...args], { env }, (err, stdout, stderr) => resolve({ code: err ? err.code : 0, stdout, stderr }));
    child.stdin.end(input === undefined ? '' : input);
  });
  const add = ['mount', 'add', 'clinas', '--host', 'nas.local', '--share', 'music', '--user', 'remko', '--password-stdin'];

  // A user without --password-stdin is refused before anything is sent; a password is never an argument.
  const noPw = await run(['mount', 'add', 'clinas', '--host', 'nas.local', '--share', 'music', '--user', 'remko']);
  assert.notEqual(noPw.code, 0);
  assert.match(noPw.stderr, /password-stdin/);

  host.clear();
  const planned = await run(add, `${PASSWORD}\n`);
  assert.equal(planned.code, 0, planned.stderr);
  assert.match(planned.stdout, /Dry run/);
  assert.match(planned.stdout, /x-systemd\.automount/);
  assert.match(planned.stdout, /password=\*\*\*/);
  assert.ok(!planned.stdout.includes(PASSWORD));
  assert.deepEqual(host.calls(), []);
  const token = /--confirm ([0-9a-f]{16})/.exec(planned.stdout)[1];

  const applied = await run([...add, '--confirm', token], `${PASSWORD}\n`);
  assert.equal(applied.code, 0, applied.stderr);
  assert.match(applied.stdout, /clinas added/);
  assert.equal(fs.readFileSync(path.join(credDir, 'clinas.cred'), 'utf8'), `username=remko\npassword=${PASSWORD}\n`); // the trailing newline of stdin is dropped
  assert.ok(!JSON.stringify(host.calls()).includes(PASSWORD));

  setMounted('clinas', true);
  const status = await run(['mount']);
  assert.match(status.stdout, /clinas\s+.*ok/);
  assert.ok(!(await run(['mount', 'status', '--json'])).stdout.includes('"password"'));

  const rm = await run(['mount', 'remove', 'clinas']);
  assert.match(rm.stdout, /Dry run/);
  const rmToken = /--confirm ([0-9a-f]{16})/.exec(rm.stdout)[1];
  setMounted('clinas', false);
  assert.match((await run(['mount', 'remove', 'clinas', '--confirm', rmToken])).stdout, /clinas removed/);
});
