'use strict';
// NAS mounts of the ReMoment Manager (workstream 10, "SMB mount"): an SMB share on a NAS mounted on this host so it can
// be used as a storage location. The manager writes a root-only credentials file and a systemd .mount + .automount unit
// pair (`_netdev`, `nofail`, automount: a NAS that is off at boot or later never blocks the boot or the app), shows what
// it will do first (dry run), applies only with the confirm token of that dry run, audits it, and reports whether each
// mount is mounted and readable so the app can raise an alert when one disappears.
// Design: docs/architecture/manager.md ("SMB mount"). Nothing here is verified against a real host: the tests put fake
// `systemctl` and `systemd-analyze` first on PATH and write into temp directories.
//
// Security rules (the same as smb.js): every command runs through the manager's one `run()` (execFile, argument arrays,
// no shell); the only request input that reaches a command or a file is validated field by field; the password travels
// in the request body once, is written to the credentials file (0600) and nowhere else: never in an argument, the audit
// log, the state file, a unit file, a response or an error message.

const fs = require('fs');
const path = require('path');
const crypto = require('crypto');
const { pathError } = require('./smb');

const NAME_RE = /^[a-z]([a-z0-9-]{0,29}[a-z0-9])?$/;
const HOST_RE = /^(?=.{1,253}$)[A-Za-z0-9]([A-Za-z0-9-]*[A-Za-z0-9])?(\.[A-Za-z0-9]([A-Za-z0-9-]*[A-Za-z0-9])?)*$/;
const SHARE_SEGMENT_RE = /^[A-Za-z0-9_$][A-Za-z0-9_.$-]{0,59}$/;
const USER_RE = /^[A-Za-z0-9_][A-Za-z0-9_.@-]{0,63}$/;
const DOMAIN_RE = /^[A-Za-z0-9][A-Za-z0-9_.-]{0,62}$/;
const BASE_RE = /^\/[A-Za-z0-9_.\/-]*$/;
const VERSIONS = ['default', '2.0', '2.1', '3.0', '3.1.1']; // SMB1 is not offered
const PASSWORD_MAX = 128;
const OK_RECORD_MS = 60_000;
const FS_TYPES = ['cifs', 'smb3'];

// What mount.js reads from the environment (loadConfig in server.js calls this so the variables live in one place).
function mountConfig(env, home) {
  return {
    // Every mount point is `<base>/<name>`; the base is the manager's, a request never picks a path.
    base: env.REMOMENT_MOUNT_BASE || '/mnt/remoment',
    unitDir: env.REMOMENT_MOUNT_UNIT_DIR || '/etc/systemd/system',
    credDir: env.REMOMENT_MOUNT_CRED_DIR || '/etc/remoment-manager/credentials',
    // Optional numeric owner of the files on the mount (the app's unix user), so the app can read them.
    uid: env.REMOMENT_MOUNT_UID || '',
    gid: env.REMOMENT_MOUNT_GID || '',
    dryRun: ['1', 'true', 'yes'].includes(String(env.REMOMENT_MOUNT_DRY_RUN || '').toLowerCase()),
    mountInfo: env.REMOMENT_MOUNTINFO_FILE || '/proc/self/mountinfo',
    probeSeconds: Number(env.REMOMENT_MOUNT_PROBE_SECONDS || 5),
    // How long one probe result is reused (the app asks every minute, the page every 10 s).
    cacheMs: Number(env.REMOMENT_MOUNT_CACHE_SECONDS === undefined ? 10 : env.REMOMENT_MOUNT_CACHE_SECONDS) * 1000,
    stateFile: path.join(home, 'mounts.json'),
  };
}

// systemd names a mount unit after its path: `systemd-escape -p --suffix=mount /mnt/remoment/my-nas`.
function escapePath(p) {
  const trimmed = p.replace(/\/+/g, '/').replace(/^\/|\/$/g, '');
  if (!trimmed) return '-';
  let out = '';
  [...trimmed].forEach((ch, i) => {
    if (ch === '/') out += '-';
    else if (/[A-Za-z0-9:_]/.test(ch) || (ch === '.' && i > 0)) out += ch;
    else out += `\\x${ch.charCodeAt(0).toString(16).padStart(2, '0')}`;
  });
  return out;
}

const hash = (...parts) => crypto.createHash('sha256').update(JSON.stringify(parts)).digest('hex').slice(0, 16);

// Validates the form of a mount. Returns { error } or { mount } with the fields normalised. `needPassword`: an apply
// needs the password when there is a user; a preview does not (the plan does not contain it).
function validate(body, { needPassword }) {
  if (!body || typeof body !== 'object' || Array.isArray(body)) return { error: 'Send a JSON object.' };
  const bad = (m) => ({ error: m });
  const { name, host, share } = body;
  if (typeof name !== 'string' || !NAME_RE.test(name)) return bad('name: lower-case letters, digits and hyphens, starting with a letter (up to 31).');
  if (typeof host !== 'string' || !HOST_RE.test(host)) return bad('host: a host name or IPv4 address without spaces.');
  if (typeof share !== 'string' || share.length > 120) return bad('share: the share name on the NAS (a sub folder may follow: music/albums).');
  const segments = share.split('/');
  if (segments.length > 4 || !segments.every((s) => SHARE_SEGMENT_RE.test(s))) return bad('share: letters, digits and _ . $ - only, no spaces, no "..", at most four parts.');
  for (const k of ['user', 'domain', 'password', 'version']) {
    if (body[k] !== undefined && body[k] !== null && typeof body[k] !== 'string') return bad(`${k} must be text.`);
  }
  const user = body.user === undefined || body.user === null || body.user === '' ? null : body.user;
  if (user !== null && !USER_RE.test(user)) return bad('user: letters, digits and _ . @ - only.');
  const domain = body.domain === undefined || body.domain === null || body.domain === '' ? null : body.domain;
  if (domain !== null && (user === null || !DOMAIN_RE.test(domain))) return bad('domain: letters, digits and _ . - only, and only with a user.');
  const password = body.password === undefined || body.password === null || body.password === '' ? null : body.password;
  if (password !== null) {
    if (user === null) return bad('A password needs a user.');
    if (password.length > PASSWORD_MAX || /[\u0000-\u001f\u007f]/.test(password) || password !== password.trim()) return bad(`password: up to ${PASSWORD_MAX} characters, no line breaks, no space at the start or end.`);
  }
  if (needPassword && user !== null && password === null) return bad('A user needs its password.');
  if (body.writable !== undefined && typeof body.writable !== 'boolean') return bad('writable must be true or false.');
  const version = body.version === undefined || body.version === null || body.version === '' ? 'default' : body.version;
  if (!VERSIONS.includes(version)) return bad(`version: one of ${VERSIONS.join(', ')}.`);
  return { mount: { name, host, share, user, domain, password, writable: body.writable === true, version } };
}

// The units, the fstab equivalent and the credentials file (password hidden) for one mount. Pure: nothing is written.
function renderMount(m, mcfg) {
  const where = `${mcfg.base.replace(/\/+$/, '')}/${m.name}`;
  const unit = escapePath(where);
  const credFile = `${mcfg.credDir.replace(/\/+$/, '')}/${m.name}.cred`;
  const what = `//${m.host}/${m.share}`;
  const opts = [
    m.user ? `credentials=${credFile}` : 'guest',
    m.writable ? 'rw' : 'ro',
    ...(m.version !== 'default' ? [`vers=${m.version}`] : []),
    ...(mcfg.uid ? [`uid=${mcfg.uid}`] : []),
    ...(mcfg.gid ? [`gid=${mcfg.gid}`] : []),
    m.writable ? 'file_mode=0664,dir_mode=0775' : 'file_mode=0644,dir_mode=0755',
    'iocharset=utf8', 'soft', 'nosuid', 'nodev', 'noexec',
  ];
  const description = `ReMoment NAS mount ${m.name} (${what})`;
  const mountUnit = [
    '# Managed by the ReMoment Manager: changes made here are overwritten.',
    '[Unit]',
    `Description=${description}`,
    'After=network-online.target',
    'Wants=network-online.target',
    '',
    '[Mount]',
    `What=${what}`,
    `Where=${where}`,
    'Type=cifs',
    `Options=${[...opts, '_netdev', 'nofail'].join(',')}`,
    'TimeoutSec=30',
    '',
  ].join('\n');
  // The automount is what is enabled: the path exists (as a trigger) from boot, before Docker starts the app, and the
  // share is only mounted on first use, so a NAS that is off never blocks anything.
  const automountUnit = [
    '# Managed by the ReMoment Manager: changes made here are overwritten.',
    '[Unit]',
    `Description=${description} (automount)`,
    'Before=remote-fs.target docker.service',
    '',
    '[Automount]',
    `Where=${where}`,
    'TimeoutIdleSec=0',
    '',
    '[Install]',
    'WantedBy=remote-fs.target',
    '',
  ].join('\n');
  return {
    unit, where, credFile, what,
    mountFile: `${unit}.mount`,
    automountFile: `${unit}.automount`,
    mountUnit, automountUnit,
    // The same thing for an admin who prefers /etc/fstab (the manager does not edit fstab itself).
    fstab: `${what} ${where} cifs ${[...opts, '_netdev', 'nofail', 'x-systemd.automount', 'x-systemd.mount-timeout=30'].join(',')} 0 0`,
    credentials: m.user ? [`username=${m.user}`, 'password=***', ...(m.domain ? [`domain=${m.domain}`] : []), ''].join('\n') : null,
    credentialsContent: (password) => [`username=${m.user}`, `password=${password}`, ...(m.domain ? [`domain=${m.domain}`] : []), ''].join('\n'),
  };
}

const withTimeout = (promise, ms, fallback) => new Promise((resolve) => {
  const t = setTimeout(() => resolve(fallback), ms);
  promise.then((v) => { clearTimeout(t); resolve(v); }, () => { clearTimeout(t); resolve(fallback); });
});

function createMounts({ cfg, run, audit, isBusy, setBusy }) {
  const mcfg = cfg.mount;
  const stripSecrets = (text, ...secrets) => secrets.filter(Boolean).reduce((t, s) => t.split(s).join('***'), String(text || '')).trim().slice(-300);
  const baseError = () => (BASE_RE.test(mcfg.base) ? pathError(mcfg.base) : 'REMOMENT_MOUNT_BASE has characters a mount path may not have.');
  const setupProblems = () => {
    const p = [];
    const b = baseError();
    if (b) p.push(`REMOMENT_MOUNT_BASE: ${b}`);
    if (mcfg.uid && !/^\d{1,10}$/.test(mcfg.uid)) p.push('REMOMENT_MOUNT_UID must be a number.');
    if (mcfg.gid && !/^\d{1,10}$/.test(mcfg.gid)) p.push('REMOMENT_MOUNT_GID must be a number.');
    if (!BASE_RE.test(mcfg.credDir) || pathError(mcfg.credDir)) p.push('REMOMENT_MOUNT_CRED_DIR is not a plain absolute path.');
    if (!BASE_RE.test(mcfg.unitDir) || pathError(mcfg.unitDir)) p.push('REMOMENT_MOUNT_UNIT_DIR is not a plain absolute path.');
    return p;
  };

  // --- State (no secrets) ----------------------------------------------------------------------------------------
  function readState() {
    let s = {};
    try { s = JSON.parse(fs.readFileSync(mcfg.stateFile, 'utf8')); } catch { /* none yet */ }
    const mounts = {};
    for (const [name, v] of Object.entries(s.mounts && typeof s.mounts === 'object' ? s.mounts : {})) if (NAME_RE.test(name) && v && typeof v === 'object') mounts[name] = v;
    return { mounts };
  }
  const writeState = (s) => fs.writeFileSync(mcfg.stateFile, JSON.stringify(s, null, 2) + '\n', { mode: 0o600 });
  const publicFields = (name, v) => ({ name, host: v.host, share: v.share, user: v.user || null, domain: v.domain || null, writable: v.writable === true, version: v.version || 'default' });

  // --- Probing: is it active, mounted, readable? -----------------------------------------------------------------
  function mountedAt(where) {
    let text = '';
    try { text = fs.readFileSync(mcfg.mountInfo, 'utf8'); } catch { return false; }
    return text.split('\n').some((line) => {
      const [pre, post] = line.split(' - ');
      if (!post) return false;
      const point = (pre.split(' ')[4] || '').replace(/\\040/g, ' ');
      return point === where && FS_TYPES.includes(post.split(' ')[0]);
    });
  }

  const cache = new Map(); // name -> { at, result }
  const inflight = new Map(); // name -> Promise
  const lastState = new Map(); // name -> state, to audit a mount that is lost or back

  async function probeNow(name, v) {
    const r = renderMount({ name, ...v }, mcfg);
    const act = await run('systemctl', ['is-active', r.automountFile]);
    const active = act.stdout.trim() === 'active';
    let state; let error = null; let mounted = false; let readable = false;
    if (!active) {
      state = 'inactive';
    } else {
      // Listing the mount point is what triggers the automount; a stalled share must not stall the manager, so the
      // `ls` has its own kill timeout and the caller stops waiting after `probeSeconds`.
      const ls = await run('ls', ['-1', '-U', '--', r.where], { timeout: 30_000 });
      mounted = mountedAt(r.where);
      readable = ls.ok;
      if (mounted && readable) state = 'ok';
      else if (!mounted) { state = 'not_mounted'; error = 'The share is not mounted: is the NAS on and reachable?'; }
      else { state = 'unreadable'; error = 'The share is mounted but cannot be read.'; }
    }
    return { active, mounted, readable, state, error, checked_at: new Date().toISOString() };
  }

  async function check(name, v) {
    const hit = cache.get(name);
    if (hit && Date.now() - hit.at < mcfg.cacheMs) return hit.result;
    let p = inflight.get(name);
    if (!p) {
      p = probeNow(name, v).then((result) => { cache.set(name, { at: Date.now(), result }); return result; }).finally(() => inflight.delete(name));
      inflight.set(name, p);
    }
    const stalled = { active: true, mounted: false, readable: false, state: 'unreadable', error: `The share did not answer within ${mcfg.probeSeconds} s.`, checked_at: new Date().toISOString() };
    return withTimeout(p, mcfg.probeSeconds * 1000, stalled);
  }

  // Remember the last time it worked (a lost mount is one that did), and audit the changes.
  function note(name, v, result, st) {
    const prev = lastState.get(name);
    lastState.set(name, result.state);
    if (prev !== undefined && prev !== result.state) {
      if (prev === 'ok') audit('mount lost', name, result.state);
      else if (result.state === 'ok') audit('mount back', name, 'ok');
    }
    if (result.state === 'ok' && (!v.last_ok_at || Date.now() - Date.parse(v.last_ok_at) > OK_RECORD_MS)) {
      v.last_ok_at = new Date().toISOString();
      try { writeState(st); } catch { /* the state file is a convenience */ }
    }
  }

  async function status() {
    const st = readState();
    const data = await Promise.all(Object.entries(st.mounts).map(async ([name, v]) => {
      const result = await check(name, v);
      note(name, v, result, st);
      const r = renderMount({ name, ...v }, mcfg);
      return {
        ...publicFields(name, v), path: r.where, unit: r.automountFile,
        active: result.active, mounted: result.mounted, readable: result.readable, state: result.state, error: result.error,
        // A lost mount worked before (or is active and never has): the app raises an alert for it.
        lost: result.state !== 'ok' && result.state !== 'inactive' && !!v.last_ok_at,
        last_ok_at: v.last_ok_at || null, checked_at: result.checked_at,
      };
    }));
    return { dry_run: mcfg.dryRun, method: 'systemd', base: mcfg.base, problems: setupProblems(), data: data.sort((a, b) => a.name.localeCompare(b.name)) };
  }

  // --- Plans -----------------------------------------------------------------------------------------------------
  function addPlan(m) {
    const r = renderMount(m, mcfg);
    const actions = [
      ...(m.user ? [`write the credentials file ${r.credFile} (mode 0600, root only)`] : []),
      `create the mount point ${r.where}`,
      `write ${r.mountFile} and ${r.automountFile} to ${mcfg.unitDir} (checked with systemd-analyze verify first)`,
      'systemctl daemon-reload',
      `systemctl enable --now ${r.automountFile}`,
    ];
    // The confirm token covers everything that will be written, but never the password.
    const plan = hash('add', r.mountUnit, r.automountUnit, r.credentials, mcfg.unitDir);
    return { r, actions, plan };
  }

  const guard = (confirm, plan) => {
    if (typeof confirm !== 'string' || confirm === '') return { code: 422, body: { error: 'confirm_required', message: 'Run a dry run first, read the plan, then repeat the call with "confirm" set to its plan token.', plan } };
    if (confirm !== plan) return { code: 409, body: { error: 'plan_changed', message: 'The plan changed since the dry run. Run the dry run again.', plan } };
    return null;
  };
  const busy = () => (isBusy() ? { code: 409, body: { error: 'busy', message: `Another change is running: ${isBusy()}` } } : null);

  // --- Add or update ---------------------------------------------------------------------------------------------
  async function add(body, dry) {
    const problems = setupProblems();
    if (problems.length) return { code: 422, body: { error: 'bad_setup', message: problems[0] } };
    const v = validate(body, { needPassword: !(dry || mcfg.dryRun) });
    if (v.error) return { code: 422, body: { error: 'invalid', message: v.error } };
    const m = v.mount;
    const { r, actions, plan } = addPlan(m);
    const st = readState();
    const updating = Object.hasOwn(st.mounts, m.name);
    if (dry || mcfg.dryRun) {
      return { code: 200, body: { dry_run: true, name: m.name, updating, path: r.where, plan, config: { mount_unit: r.mountUnit, automount_unit: r.automountUnit, fstab: r.fstab, credentials: r.credentials, credentials_file: m.user ? r.credFile : null }, actions } };
    }
    const refused = guard(body.confirm, plan);
    if (refused) return refused;
    const b = busy();
    if (b) return b;

    setBusy(`mount ${m.name}`);
    const secrets = [m.password];
    const staging = path.join(mcfg.unitDir, '.remoment-staging');
    try {
      // 1. credentials (root only), 2. mount point, 3. units checked in a staging folder, 4. installed, 5. enabled.
      try {
        if (m.user) {
          fs.mkdirSync(mcfg.credDir, { recursive: true, mode: 0o700 });
          fs.chmodSync(mcfg.credDir, 0o700);
          fs.writeFileSync(`${r.credFile}.new`, r.credentialsContent(m.password), { mode: 0o600 });
          fs.chmodSync(`${r.credFile}.new`, 0o600);
          fs.renameSync(`${r.credFile}.new`, r.credFile);
        } else {
          try { fs.unlinkSync(r.credFile); } catch { /* none */ }
        }
        fs.mkdirSync(r.where, { recursive: true, mode: 0o755 });
        fs.mkdirSync(staging, { recursive: true });
        fs.writeFileSync(path.join(staging, r.mountFile), r.mountUnit, { mode: 0o644 });
        fs.writeFileSync(path.join(staging, r.automountFile), r.automountUnit, { mode: 0o644 });
      } catch (e) {
        audit('mount add', m.name, 'failed (write_failed)');
        return { code: 502, body: { error: 'write_failed', message: `Cannot write the mount files: does the manager run as root? (${stripSecrets(e.code || e.message, ...secrets)})` } };
      }
      const verify = await run('systemd-analyze', ['verify', path.join(staging, r.mountFile), path.join(staging, r.automountFile)]);
      if (!verify.ok) {
        fs.rmSync(staging, { recursive: true, force: true });
        if (!updating && m.user) { try { fs.unlinkSync(r.credFile); } catch { /* gone */ } }
        audit('mount add', m.name, 'failed (config_invalid)');
        return { code: 502, body: { error: 'config_invalid', message: `systemd did not accept the units: ${stripSecrets(verify.stderr || verify.stdout, ...secrets)}` } };
      }
      try {
        fs.renameSync(path.join(staging, r.mountFile), path.join(mcfg.unitDir, r.mountFile));
        fs.renameSync(path.join(staging, r.automountFile), path.join(mcfg.unitDir, r.automountFile));
        fs.rmSync(staging, { recursive: true, force: true });
      } catch {
        audit('mount add', m.name, 'failed (write_failed)');
        return { code: 502, body: { error: 'write_failed', message: `Cannot install the units into ${mcfg.unitDir}.` } };
      }
      st.mounts[m.name] = { ...publicFields(m.name, m), created_at: (st.mounts[m.name] && st.mounts[m.name].created_at) || new Date().toISOString(), last_ok_at: (st.mounts[m.name] && st.mounts[m.name].last_ok_at) || null };
      writeState(st);
      cache.delete(m.name);
      const reload = await run('systemctl', ['daemon-reload']);
      if (!reload.ok) { audit('mount add', m.name, 'failed (daemon_reload)'); return { code: 502, body: { error: 'daemon_reload_failed', message: `systemctl daemon-reload failed: ${stripSecrets(reload.stderr || reload.stdout, ...secrets)}` } }; }
      if (updating) await run('systemctl', ['stop', r.automountFile, r.mountFile]); // so the new options are used at the next access
      const en = await run('systemctl', ['enable', '--now', r.automountFile]);
      if (!en.ok) { audit('mount add', m.name, 'failed (enable)'); return { code: 502, body: { error: 'enable_failed', message: `Could not start the automount: ${stripSecrets(en.stderr || en.stdout, ...secrets)}` } }; }
      audit('mount add', m.name, updating ? 'updated' : 'enabled');
      return { code: 200, body: { status: 'ok', name: m.name, path: r.where, updated: updating } };
    } finally {
      fs.rmSync(staging, { recursive: true, force: true });
      setBusy(null);
    }
  }

  // Stops the automount, then the mount. A share that is in use is not unmounted: the automount is started again.
  async function deactivate(r, persist) {
    const a = await run('systemctl', ['disable', '--now', r.automountFile]);
    // `disable` of a unit that is not loaded fails: the stop below decides.
    const s = await run('systemctl', ['stop', r.mountFile]);
    if (!s.ok && mountedAt(r.where)) {
      if (persist) await run('systemctl', ['enable', '--now', r.automountFile]);
      return { ok: false, body: { error: 'mount_busy', message: `The share is in use and was not unmounted: ${stripSecrets(s.stderr || s.stdout)}` } };
    }
    return { ok: true, disabled: a.ok };
  }

  // --- Switch on or off (keeps the configuration) ----------------------------------------------------------------
  async function setActive(name, body, dry) {
    const st = readState();
    if (!Object.hasOwn(st.mounts, name)) return { code: 404, body: { error: 'unknown_mount' } };
    if (!body || typeof body.active !== 'boolean') return { code: 422, body: { error: 'invalid', message: 'Send {"active": true|false}.' } };
    const r = renderMount({ name, ...st.mounts[name] }, mcfg);
    const actions = body.active ? [`systemctl enable --now ${r.automountFile}`] : [`systemctl disable --now ${r.automountFile}`, `unmount ${r.where} (systemctl stop ${r.mountFile})`];
    const plan = hash('active', name, body.active, r.mountUnit);
    if (dry || mcfg.dryRun) return { code: 200, body: { dry_run: true, name, active: body.active, plan, actions } };
    const refused = guard(body.confirm, plan);
    if (refused) return refused;
    const b = busy();
    if (b) return b;
    setBusy(`mount ${name}`);
    try {
      cache.delete(name);
      if (body.active) {
        const en = await run('systemctl', ['enable', '--now', r.automountFile]);
        if (!en.ok) { audit('mount on', name, 'failed (enable)'); return { code: 502, body: { error: 'enable_failed', message: `Could not start the automount: ${stripSecrets(en.stderr || en.stdout)}` } }; }
        audit('mount on', name, 'ok');
      } else {
        const d = await deactivate(r, true);
        if (!d.ok) { audit('mount off', name, 'failed (busy)'); return { code: 409, body: d.body }; }
        audit('mount off', name, 'ok');
      }
      return { code: 200, body: { status: 'ok', name, active: body.active } };
    } finally { setBusy(null); }
  }

  // --- Remove ----------------------------------------------------------------------------------------------------
  async function remove(name, confirm, dry) {
    const st = readState();
    if (!Object.hasOwn(st.mounts, name)) return { code: 404, body: { error: 'unknown_mount' } };
    const v = st.mounts[name];
    const r = renderMount({ name, ...v }, mcfg);
    const actions = [`systemctl disable --now ${r.automountFile}`, `unmount ${r.where} (systemctl stop ${r.mountFile})`, `delete ${r.mountFile} and ${r.automountFile}`, ...(v.user ? [`delete the credentials file ${r.credFile}`] : []), 'systemctl daemon-reload', `remove the empty folder ${r.where}`];
    const plan = hash('remove', name, r.mountUnit);
    if (dry || mcfg.dryRun) return { code: 200, body: { dry_run: true, name, plan, actions } };
    const refused = guard(confirm, plan);
    if (refused) return refused;
    const b = busy();
    if (b) return b;
    setBusy(`mount ${name}`);
    try {
      cache.delete(name);
      const d = await deactivate(r, false);
      if (!d.ok) { audit('mount remove', name, 'failed (busy)'); return { code: 409, body: d.body }; }
      for (const f of [path.join(mcfg.unitDir, r.mountFile), path.join(mcfg.unitDir, r.automountFile), r.credFile]) { try { fs.unlinkSync(f); } catch { /* not there */ } }
      delete st.mounts[name];
      writeState(st);
      lastState.delete(name);
      const reload = await run('systemctl', ['daemon-reload']);
      try { fs.rmdirSync(r.where); } catch { /* not empty or gone: never forced */ }
      if (!reload.ok) { audit('mount remove', name, 'failed (daemon_reload)'); return { code: 502, body: { error: 'daemon_reload_failed', message: `The mount is removed but systemctl daemon-reload failed: ${stripSecrets(reload.stderr || reload.stdout)}` } }; }
      audit('mount remove', name, 'removed');
      return { code: 200, body: { status: 'ok', name, removed: true } };
    } finally { setBusy(null); }
  }

  return { status, add, setActive, remove, validate, renderMount };
}

module.exports = { createMounts, mountConfig, renderMount, validate, escapePath, VERSIONS };
