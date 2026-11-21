'use strict';
// The installers in manager/install/: rendered into a scratch root (DESTDIR) only. In that mode the scripts never call
// systemctl, chown, useradd, a package manager or the macOS service tool; this test puts guard commands first on PATH
// that record any call, and asserts there was none. Nothing on this machine is installed, started or changed.
const test = require('node:test');
const assert = require('node:assert/strict');
const fs = require('fs');
const os = require('os');
const path = require('path');
const { spawnSync, execFileSync } = require('child_process');
const { loadConfig } = require('../server');

const installDir = path.join(__dirname, '..', 'install');
const tmp = fs.mkdtempSync(path.join(os.tmpdir(), 'rm-install-'));
const guardDir = path.join(tmp, 'guards');
fs.mkdirSync(guardDir);
for (const cmd of ['systemctl', 'chown', 'useradd', 'apt-get', 'dnf', 'launchctl', 'sudo']) {
  fs.writeFileSync(path.join(guardDir, cmd), `#!/bin/sh\necho "${cmd} $*" >> "${path.join(tmp, 'guard-calls.log')}"\nexit 1\n`, { mode: 0o755 });
}
const guardCalls = () => { try { return fs.readFileSync(path.join(tmp, 'guard-calls.log'), 'utf8').trim(); } catch { return ''; } };

let n = 0;
function scratch() { const d = path.join(tmp, `root${++n}`); fs.mkdirSync(d); return d; }
function sh(script, args, env) {
  const r = spawnSync('bash', [path.join(installDir, script), ...args], {
    encoding: 'utf8', env: { PATH: `${guardDir}${path.delimiter}${process.env.PATH}`, TMPDIR: tmp, ...env },
  });
  return { code: r.status, out: r.stdout, err: r.stderr, all: r.stdout + r.stderr };
}
const linux = (root, args = [], env = {}) => sh('install-linux.sh', ['--node', process.execPath, ...args], { DESTDIR: root, ...env });
const read = (root, p) => fs.readFileSync(path.join(root, p), 'utf8');
const exists = (root, p) => fs.existsSync(path.join(root, p)) || (() => { try { fs.lstatSync(path.join(root, p)); return true; } catch { return false; } })();
const mode = (root, p) => fs.statSync(path.join(root, p)).mode & 0o777;
const parseEnv = (text) => Object.fromEntries(text.split('\n').filter((l) => l && !l.startsWith('#')).map((l) => [l.slice(0, l.indexOf('=')), l.slice(l.indexOf('=') + 1)]));

test('the scripts are valid shell and the service template has what a unit needs', () => {
  for (const f of ['install-linux.sh', 'uninstall-linux.sh', 'install-macos.sh', 'uninstall-macos.sh']) {
    const r = spawnSync('bash', ['-n', path.join(installDir, f)], { encoding: 'utf8' });
    assert.equal(r.status, 0, `${f}: ${r.stderr}`);
  }
  for (const f of ['install-linux.sh', 'uninstall-linux.sh']) assert.ok(fs.statSync(path.join(installDir, f)).mode & 0o100, `${f} is executable`);
  const unit = fs.readFileSync(path.join(installDir, 'remoment-manager.service'), 'utf8');
  for (const s of ['[Unit]', '[Service]', '[Install]']) assert.ok(unit.includes(s), s);
  assert.match(unit, /^EnvironmentFile=\/etc\/remoment-manager\/manager\.env$/m);
  assert.match(unit, /^EnvironmentFile=-\/etc\/remoment-manager\/local\.env$/m);
  assert.match(unit, /^Restart=always$/m);
  assert.match(unit, /^WantedBy=multi-user\.target$/m);
});

test('dry run shows the steps and creates nothing, not even a token', () => {
  const root = scratch();
  const r = linux(root, ['--dry-run']);
  assert.equal(r.code, 0, r.all);
  assert.match(r.out, /would write: \/etc\/systemd\/system\/remoment-manager\.service/);
  assert.match(r.out, /would create the token/);
  assert.deepEqual(fs.readdirSync(root), []);
  assert.equal(guardCalls(), '');
});

test('install into a scratch root: unit, settings, token root-only and never printed, no system command', () => {
  const root = scratch();
  const r = linux(root);
  assert.equal(r.code, 0, r.all);

  const token = read(root, 'var/lib/remoment-manager/token').trim();
  assert.match(token, /^[0-9a-f]{64}$/);
  assert.equal(mode(root, 'var/lib/remoment-manager/token'), 0o600);
  assert.equal(mode(root, 'var/lib/remoment-manager'), 0o700);
  assert.ok(!r.all.includes(token), 'the token is never printed');

  const unit = read(root, 'etc/systemd/system/remoment-manager.service');
  assert.ok(!/@[A-Z_]+@/.test(unit), 'every placeholder is filled');
  assert.match(unit, /^User=root$/m);
  assert.match(unit, new RegExp(`^ExecStart=${process.execPath.replace(/[.*+?^${}()|[\]\\]/g, '\\$&')} ${path.resolve(installDir, '../..').replace(/[.*+?^${}()|[\]\\]/g, '\\$&')}/manager/server\\.js$`, 'm'));
  assert.equal(mode(root, 'etc/systemd/system/remoment-manager.service'), 0o644);

  const env = parseEnv(read(root, 'etc/remoment-manager/manager.env'));
  assert.equal(env.REMOMENT_MANAGER_PORT, '8099');
  assert.equal(env.REMOMENT_MANAGER_BIND, '127.0.0.1');
  assert.equal(env.REMOMENT_MANAGER_HOME, '/var/lib/remoment-manager');
  assert.equal(env.REMOMENT_COMPOSE_FILE, 'docker-compose.prod.yml');
  assert.ok(!Object.values(env).some((v) => v.includes(token)), 'the settings file has no secret');
  assert.ok(exists(root, 'etc/remoment-manager/local.env'));
  assert.match(read(root, 'etc/profile.d/remoment-manager.sh'), /export REMOMENT_MANAGER_PORT=8099/);
  assert.equal(fs.readlinkSync(path.join(root, 'usr/local/bin/remoment-manager')), path.resolve(installDir, '../bin/remoment-manager'));

  // The manager itself accepts these settings.
  const cfg = loadConfig(env);
  assert.equal(cfg.port, 8099);
  assert.equal(cfg.bind, '127.0.0.1');
  assert.equal(cfg.tokenUid, null);
  assert.equal(guardCalls(), '', 'no systemctl, chown, package manager or service command was run');
});

test('running it again changes nothing; the token and your own settings survive; a changed option updates only what it must', () => {
  const root = scratch();
  assert.equal(linux(root).code, 0);
  const token = read(root, 'var/lib/remoment-manager/token');
  fs.writeFileSync(path.join(root, 'etc/remoment-manager/local.env'), 'REMOMENT_SMB_INBOX_DIR=/srv/inbox\n');

  const again = linux(root);
  assert.equal(again.code, 0, again.all);
  assert.ok(!/wrote:/.test(again.out), again.out);
  assert.match(again.out, /token exists, kept/);
  assert.equal(read(root, 'var/lib/remoment-manager/token'), token);
  assert.equal(read(root, 'etc/remoment-manager/local.env'), 'REMOMENT_SMB_INBOX_DIR=/srv/inbox\n');

  const lan = linux(root, ['--lan', '--port', '9100']);
  assert.equal(lan.code, 0, lan.all);
  assert.match(lan.out, /wrote: \/etc\/remoment-manager\/manager\.env/);
  assert.match(lan.out, /unchanged: \/etc\/systemd\/system\/remoment-manager\.service/);
  assert.match(lan.out, /trusted LAN/);
  const env = parseEnv(read(root, 'etc/remoment-manager/manager.env'));
  assert.equal(env.REMOMENT_MANAGER_BIND, '0.0.0.0');
  assert.equal(env.REMOMENT_MANAGER_PORT, '9100');
  assert.equal(read(root, 'var/lib/remoment-manager/token'), token);
  assert.equal(guardCalls(), '');
});

test('a root service with --app-uid hands the token to the app user and says so in its settings', () => {
  const root = scratch();
  const r = linux(root, ['--app-uid', '1000']);
  assert.equal(r.code, 0, r.all);
  const env = parseEnv(read(root, 'etc/remoment-manager/manager.env'));
  assert.equal(env.REMOMENT_MANAGER_TOKEN_UID, '1000');
  assert.equal(env.REMOMENT_MOUNT_UID, '1000');
  assert.equal(env.REMOMENT_MOUNT_GID, '1000');
  assert.equal(mode(root, 'var/lib/remoment-manager'), 0o755, 'the home must be traversable for the app');
  assert.equal(mode(root, 'var/lib/remoment-manager/token'), 0o600);
  assert.match(r.out, /readable by root and uid 1000 only/);
  const cfg = loadConfig(env);
  assert.equal(cfg.tokenUid, 1000);
  assert.equal(cfg.mount.uid, '1000');
  assert.equal(guardCalls(), '', 'the chown happens only on a real install');
});

test('options that could add a line to a unit or env file, or a bad port, are refused before anything is written', () => {
  const root = scratch();
  const bad = (args, re) => { const r = linux(root, args); assert.notEqual(r.code, 0, args.join(' ')); assert.match(r.err, re, args.join(' ')); };
  bad(['--port', 'abc'], /--port must be a number/);
  bad(['--port', '70000'], /1-65535/);
  bad(['--home', '/var/lib/x\nUser=root'], /--home/);
  bad(['--home', '/var/lib/my manager'], /--home/);
  bad(['--home', 'relative/dir'], /absolute/);
  bad(['--user', 'a;reboot'], /--user/);
  bad(['--compose-file', '../../etc/passwd; x'], /--compose-file/);
  bad(['--compose-project', 'a b'], /--compose-project/);
  bad(['--app-uid', 'root'], /--app-uid must be a number/);
  bad(['--bogus'], /unknown option/);
  assert.deepEqual(fs.readdirSync(root), []);
  assert.notEqual(sh('install-linux.sh', ['--node', '/nonexistent/node', '--dry-run'], { DESTDIR: root }).code, 0);
  assert.notEqual(sh('install-linux.sh', ['--project-dir', '/nonexistent', '--node', process.execPath, '--dry-run'], { DESTDIR: root }).code, 0);
});

test('--with-samba appends the include line to smb.conf once (at the end, with a backup) and creates the empty fragment; not for a non-root service', () => {
  const root = scratch();
  fs.mkdirSync(path.join(root, 'etc/samba'), { recursive: true });
  fs.writeFileSync(path.join(root, 'etc/samba/smb.conf'), '[global]\n   workgroup = HOME\n\n[printers]\n   path = /var/spool/samba\n');
  const r = linux(root, ['--with-samba']);
  assert.equal(r.code, 0, r.all);
  const conf = read(root, 'etc/samba/smb.conf');
  assert.equal(conf.match(/^include = \/etc\/samba\/remoment\.conf$/gm).length, 1);
  assert.ok(conf.indexOf('include =') > conf.indexOf('[printers]'), 'at the end, not inside smb.conf\'s own [global]');
  assert.equal(read(root, 'etc/samba/smb.conf.remoment-backup'), '[global]\n   workgroup = HOME\n\n[printers]\n   path = /var/spool/samba\n');
  assert.match(read(root, 'etc/samba/remoment.conf'), /Managed by the ReMoment Manager/);
  const again = linux(root, ['--with-samba']);
  assert.match(again.out, /already includes/);
  assert.equal(read(root, 'etc/samba/smb.conf'), conf);
  assert.equal(guardCalls(), '', 'no package was installed');

  const notRoot = linux(scratch(), ['--with-samba', '--user', 'someone']);
  assert.notEqual(notRoot.code, 0);
  assert.match(notRoot.err, /--with-samba needs --user root/);
});

test('uninstall removes the service, settings, CLI link and profile script, keeps the home and local settings, and is safe to repeat', () => {
  const root = scratch();
  assert.equal(linux(root).code, 0);
  const token = read(root, 'var/lib/remoment-manager/token');
  fs.writeFileSync(path.join(root, 'var/lib/remoment-manager/mounts.json'), '{"mounts":{"nas":{"host":"192.168.1.20"}}}');

  const un = sh('uninstall-linux.sh', [], { DESTDIR: root });
  assert.equal(un.code, 0, un.all);
  for (const p of ['etc/systemd/system/remoment-manager.service', 'etc/remoment-manager/manager.env', 'etc/profile.d/remoment-manager.sh', 'usr/local/bin/remoment-manager']) assert.equal(exists(root, p), false, p);
  assert.equal(read(root, 'var/lib/remoment-manager/token'), token, 'the token stays without --purge');
  assert.ok(exists(root, 'etc/remoment-manager/local.env'));
  assert.match(un.out, /NAS mounts configured through the manager keep working/);

  assert.equal(sh('uninstall-linux.sh', [], { DESTDIR: root }).code, 0, 'a second run is fine');

  fs.mkdirSync(path.join(root, 'etc/remoment-manager/credentials'), { recursive: true });
  fs.writeFileSync(path.join(root, 'etc/remoment-manager/credentials/nas.cred'), 'username=x\npassword=y\n');
  const purge = sh('uninstall-linux.sh', ['--purge'], { DESTDIR: root });
  assert.equal(purge.code, 0, purge.all);
  assert.equal(exists(root, 'var/lib/remoment-manager'), false);
  assert.equal(exists(root, 'etc/remoment-manager/local.env'), false);
  assert.ok(exists(root, 'etc/remoment-manager/credentials/nas.cred'), 'NAS credentials are never purged: the mount units point at them');
  assert.equal(guardCalls(), '');

  for (const home of ['/etc', '/', '/var', '/usr']) {
    const r = sh('uninstall-linux.sh', ['--purge', '--home', home], { DESTDIR: root });
    assert.notEqual(r.code, 0, home);
  }
  assert.notEqual(sh('uninstall-linux.sh', ['--home', 'relative'], { DESTDIR: root }).code, 0);
});

test('uninstall --dry-run removes nothing', () => {
  const root = scratch();
  assert.equal(linux(root).code, 0);
  const un = sh('uninstall-linux.sh', ['--dry-run', '--purge'], { DESTDIR: root });
  assert.equal(un.code, 0, un.all);
  assert.match(un.out, /would remove/);
  assert.ok(exists(root, 'etc/systemd/system/remoment-manager.service'));
  assert.ok(exists(root, 'var/lib/remoment-manager/token'));
});

// --- macOS: only rendered into a scratch home; the agent is never loaded -----------------------------------------------

test('macOS: the plist is rendered and valid, install is idempotent, uninstall removes it; the agent is never loaded', () => {
  const root = scratch();
  const home = path.join(root, 'home');
  fs.mkdirSync(home);
  const mac = (script, args = []) => sh(script, args, { DESTDIR: root, HOME: home });
  const plist = path.join(root, home, 'Library', 'LaunchAgents', 'nl.remoment.manager.plist');

  const dry = mac('install-macos.sh', ['--node', process.execPath, '--dry-run']);
  assert.equal(dry.code, 0, dry.all);
  assert.match(dry.out, /would write/);
  assert.equal(fs.existsSync(plist), false);

  const r = mac('install-macos.sh', ['--node', process.execPath, '--port', '9200']);
  assert.equal(r.code, 0, r.all);
  const text = fs.readFileSync(plist, 'utf8');
  assert.ok(!/@[A-Z_]+@/.test(text), 'every placeholder is filled');
  assert.match(text, /<key>REMOMENT_MANAGER_PORT<\/key><string>9200<\/string>/);
  assert.match(text, /<key>REMOMENT_MANAGER_BIND<\/key><string>127\.0\.0\.1<\/string>/);
  assert.ok(text.includes(`<string>${path.resolve(installDir, '../..')}/manager/server.js</string>`));
  try { execFileSync('plutil', ['-lint', plist]); } catch (e) { if (e.code !== 'ENOENT') throw e; } // only where plutil exists

  assert.match(mac('install-macos.sh', ['--node', process.execPath, '--port', '9200']).out, /unchanged/);
  assert.match(mac('install-macos.sh', ['--node', process.execPath, '--port', '9300']).out, /wrote/);

  fs.mkdirSync(path.join(root, home, '.remoment-manager'), { recursive: true });
  fs.writeFileSync(path.join(root, home, '.remoment-manager', 'token'), 'x');
  const un = mac('uninstall-macos.sh');
  assert.equal(un.code, 0, un.all);
  assert.equal(fs.existsSync(plist), false);
  assert.equal(mac('uninstall-macos.sh').code, 0);
  assert.equal(mac('install-macos.sh', ['--node', process.execPath, '--port', 'x']).code === 0, false);
  assert.equal(guardCalls(), '', 'launchctl was never called');
});

test('the token hand-over to the app user only happens for a root manager: a normal one keeps its home at 0700', { skip: typeof process.getuid === 'function' && process.getuid() === 0 }, () => {
  const { createManager } = require('../server');
  const home = path.join(scratch(), 'mgr-home');
  const cfg = loadConfig({ REMOMENT_MANAGER_HOME: home, REMOMENT_MANAGER_TOKEN_UID: '1000', REMOMENT_PROJECT_DIR: tmp });
  assert.equal(cfg.tokenUid, 1000);
  createManager(cfg);
  assert.equal(fs.statSync(home).mode & 0o777, 0o700);
  assert.equal(fs.statSync(path.join(home, 'token')).mode & 0o777, 0o600);
  assert.equal(loadConfig({ REMOMENT_MANAGER_TOKEN_UID: 'root' }).tokenUid, null);
  assert.equal(loadConfig({}).tokenUid, null);
});
