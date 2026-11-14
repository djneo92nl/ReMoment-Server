'use strict';
// SMB shares of the ReMoment Manager (workstream 10, "SMB serve"): a Samba config fragment with three fixed shares
// (library, exports, inbox), one share user created only by an explicit call, enable/disable per share, status, and a
// dry run that renders everything without touching the host. Design: docs/architecture/manager.md ("SMB shares").
// Nothing here is verified against a real Samba host. Every command is run through the manager's one `run()` (execFile,
// argument arrays, no shell); nothing from a request reaches a command or the config except a share name from the fixed
// list and booleans. Paths and the user name come from the manager's own environment and are validated.

const fs = require('fs');
const path = require('path');
const crypto = require('crypto');

const USER_RE = /^[a-z_][a-z0-9_-]{0,30}$/;
const PATH_RE = /^\/[A-Za-z0-9_.\/ -]*$/;
const FORBIDDEN_PATHS = new Set(['/', '/bin', '/boot', '/dev', '/etc', '/home', '/lib', '/proc', '/root', '/run', '/sbin', '/sys', '/tmp', '/usr', '/var']);
const PASSWORD_LENGTH = 24;
const PASSWORD_CHARS = 'ABCDEFGHJKLMNPQRSTUVWXYZabcdefghijkmnopqrstuvwxyz23456789';

// The fixed shares. `writable`: null = never, 'option' = the admin may switch it, 'always'.
const SHARES = {
  library: { label: 'Music library', comment: 'ReMoment music library', writable: 'option', dir: 'library', note: 'Read-only unless you switch writing on; files written here are found by the next scan.' },
  exports: { label: 'Exports', comment: 'ReMoment exports', writable: null, dir: 'exports', note: 'Finished library exports appear here as zip files and go with the retention.' },
  inbox: { label: 'Inbox (drop directory)', comment: 'ReMoment inbox', writable: 'always', dir: 'inbox', note: 'Copy albums here; Import inbox moves them into the library.' },
};

// What smb.js reads from the environment (loadConfig in server.js calls this so the variables live in one place).
function smbConfig(env, home) {
  return {
    confFile: env.REMOMENT_SMB_CONF || '/etc/samba/remoment.conf',
    mainConf: env.REMOMENT_SMB_MAIN_CONF || '/etc/samba/smb.conf',
    service: env.REMOMENT_SMB_SERVICE || 'smbd',
    user: env.REMOMENT_SMB_USER || 'remoment-smb',
    // Optional: files created on the writable shares belong to this unix user (the app's), so the app can read and move them.
    forceUser: env.REMOMENT_SMB_FORCE_USER || '',
    dryRun: ['1', 'true', 'yes'].includes(String(env.REMOMENT_SMB_DRY_RUN || '').toLowerCase()),
    stateFile: path.join(home, 'smb.json'),
    dirs: {
      library: env.REMOMENT_SMB_LIBRARY_DIR || env.LIBRARY_DIR || '/srv/library',
      exports: env.REMOMENT_SMB_EXPORTS_DIR || env.EXPORT_SHARE_DIR || '/srv/exports',
      inbox: env.REMOMENT_SMB_INBOX_DIR || '/srv/inbox',
    },
  };
}

function pathError(p) {
  if (typeof p !== 'string' || !PATH_RE.test(p)) return 'The path has characters a share path may not have.';
  if (p.split('/').includes('..')) return 'The path may not contain "..".';
  const clean = p.replace(/\/+$/, '') || '/';
  if (FORBIDDEN_PATHS.has(clean)) return 'This path is a system directory and cannot be shared.';
  return null;
}

function createSmb({ cfg, run, audit, isBusy, setBusy }) {
  const smb = cfg.smb;
  const userOk = USER_RE.test(smb.user);

  function readState() {
    let s = {};
    try { s = JSON.parse(fs.readFileSync(smb.stateFile, 'utf8')); } catch { /* none yet */ }
    const shares = {};
    for (const name of Object.keys(SHARES)) {
      const v = (s.shares && s.shares[name]) || {};
      shares[name] = { enabled: v.enabled === true, writable: SHARES[name].writable === 'always' ? true : (SHARES[name].writable === 'option' && v.writable === true) };
    }
    return { shares, user: s.user && typeof s.user === 'object' ? s.user : null };
  }
  function writeState(s) {
    fs.writeFileSync(smb.stateFile, JSON.stringify(s, null, 2) + '\n', { mode: 0o600 });
  }

  function render(shares) {
    const out = [
      '# Managed by the ReMoment Manager: changes made here are overwritten.',
      `# Add this line to ${smb.mainConf} once, in the [global] section:  include = ${smb.confFile}`,
      '',
    ];
    for (const [name, def] of Object.entries(SHARES)) {
      if (!shares[name].enabled) continue;
      const writable = shares[name].writable;
      out.push(
        `[${name}]`,
        `   comment = ${def.comment}`,
        `   path = ${smb.dirs[def.dir].replace(/\/+$/, '') || '/'}`,
        '   browseable = yes',
        '   guest ok = no',
        `   valid users = ${smb.user}`,
        `   read only = ${writable ? 'no' : 'yes'}`,
        '   follow symlinks = no',
        '   wide links = no',
        '   hide dot files = yes',
      );
      if (writable) {
        out.push('   create mask = 0664', '   directory mask = 0775', '   force create mode = 0664', '   force directory mode = 0775');
        if (smb.forceUser) out.push(`   force user = ${smb.forceUser}`);
      }
      out.push('');
    }
    return out.join('\n');
  }

  function setupProblems() {
    const p = [];
    if (!userOk) p.push('REMOMENT_SMB_USER is not a valid unix user name.');
    if (smb.forceUser && !USER_RE.test(smb.forceUser)) p.push('REMOMENT_SMB_FORCE_USER is not a valid unix user name.');
    return p;
  }

  async function active() {
    const r = await run('systemctl', ['is-active', smb.service]);
    return r.stdout.trim() === 'active';
  }

  async function sessions() {
    const r = await run('smbstatus', ['--json']);
    if (!r.ok) return null;
    try {
      const o = JSON.parse(r.stdout);
      const list = Object.values(o.sessions || {}).slice(0, 20).map((x) => ({ user: String(x.username || ''), machine: String(x.remote_machine || '') }));
      const by = {};
      for (const t of Object.values(o.tcons || {})) by[t.service] = (by[t.service] || 0) + 1;
      return { count: Object.keys(o.sessions || {}).length, by_share: by, data: list };
    } catch { return null; }
  }

  async function userExists() {
    const r = await run('id', ['-u', '--', smb.user]);
    return r.ok;
  }

  // The status: nothing secret, no password, no hash.
  async function status() {
    const st = readState();
    let include = false;
    try { include = new RegExp(`^\\s*include\\s*=\\s*${smb.confFile.replace(/[.*+?^${}()|[\]\\]/g, '\\$&')}\\s*$`, 'm').test(fs.readFileSync(smb.mainConf, 'utf8')); } catch { /* no smb.conf */ }
    const [running, conn] = await Promise.all([active(), sessions()]);
    return {
      dry_run: smb.dryRun,
      running,
      service: smb.service,
      include_present: include,
      conf_file: smb.confFile,
      problems: setupProblems(),
      user: st.user ? { name: smb.user, created_at: st.user.created_at || null, password_set_at: st.user.password_set_at || null } : null,
      sessions: conn,
      shares: Object.entries(SHARES).map(([name, def]) => {
        const dir = smb.dirs[def.dir];
        return {
          name, label: def.label, note: def.note, enabled: st.shares[name].enabled, writable: st.shares[name].writable,
          can_set_writable: def.writable === 'option', path: dir, path_error: pathError(dir), path_exists: (() => { try { return fs.statSync(dir).isDirectory(); } catch { return false; } })(),
        };
      }),
    };
  }

  function currentConfig() { return render(readState().shares); }

  const stripSecrets = (text, ...secrets) => secrets.filter(Boolean).reduce((t, s) => t.split(s).join('***'), String(text || '')).trim().slice(-300);

  // Enable or disable one share (and for the library, writing). `dry` renders and plans only.
  async function setShare(name, body, dry) {
    if (!Object.hasOwn(SHARES, name)) return { code: 404, body: { error: 'unknown_share' } };
    const def = SHARES[name];
    const enabled = body && body.enabled;
    if (typeof enabled !== 'boolean') return { code: 422, body: { error: 'invalid', message: 'Send {"enabled": true|false}, for the library also "writable".' } };
    if (body.writable !== undefined && typeof body.writable !== 'boolean') return { code: 422, body: { error: 'invalid', message: '"writable" must be true or false.' } };
    if (body.writable !== undefined && def.writable !== 'option') return { code: 422, body: { error: 'invalid', message: `The ${name} share's writing cannot be changed.` } };
    const problems = setupProblems();
    if (problems.length) return { code: 422, body: { error: 'bad_setup', message: problems[0] } };

    const st = readState();
    if (enabled) {
      const bad = pathError(smb.dirs[def.dir]);
      if (bad) return { code: 422, body: { error: 'bad_path', message: bad } };
      if (!st.user) return { code: 422, body: { error: 'no_user', message: 'Create the share user first: the shares are never open without a password.' } };
    }
    st.shares[name].enabled = enabled;
    if (def.writable === 'option' && body.writable !== undefined) st.shares[name].writable = body.writable;
    const config = render(st.shares);
    const actions = [`write ${smb.confFile}`, `testparm the new file first`, `reload ${smb.service}`];
    if (dry || smb.dryRun) return { code: 200, body: { dry_run: true, share: name, enabled, writable: st.shares[name].writable, config, actions } };

    if (isBusy()) return { code: 409, body: { error: 'busy', message: `Another change is running: ${isBusy()}` } };
    setBusy(`smb ${name}`);
    try {
      const applied = await apply(config);
      if (!applied.ok) { audit('smb share', name, `failed (${applied.reason})`); return { code: 502, body: { error: applied.reason, message: applied.message } }; }
      writeState({ shares: st.shares, user: st.user });
      const reload = await reloadSamba(Object.values(st.shares).some((s) => s.enabled));
      audit('smb share', name, reload.ok ? (enabled ? 'enabled' : 'disabled') : 'failed (reload)');
      if (!reload.ok) return { code: 502, body: { error: 'reload_failed', message: reload.message } };
      return { code: 200, body: { status: 'ok', share: name, enabled, writable: st.shares[name].writable } };
    } finally { setBusy(null); }
  }

  // Writes the fragment through a temp file that `testparm` accepted; the old fragment stays while it does not.
  async function apply(config) {
    const tmp = `${smb.confFile}.new`;
    try {
      fs.writeFileSync(tmp, config, { mode: 0o644 });
    } catch { return { ok: false, reason: 'write_failed', message: `Cannot write ${smb.confFile}: does the manager run as root?` }; }
    const t = await run('testparm', ['-s', '--suppress-prompt', tmp]);
    if (!t.ok) { try { fs.unlinkSync(tmp); } catch { /* gone */ } return { ok: false, reason: 'config_invalid', message: `Samba did not accept the new configuration: ${stripSecrets(t.stderr || t.stdout)}` }; }
    try { fs.renameSync(tmp, smb.confFile); } catch { return { ok: false, reason: 'write_failed', message: `Cannot replace ${smb.confFile}.` }; }
    return { ok: true };
  }

  async function reloadSamba(anyEnabled) {
    if (!(await active())) {
      if (!anyEnabled) return { ok: true };
      const s = await run('systemctl', ['start', smb.service]);
      return s.ok ? { ok: true } : { ok: false, message: `${smb.service} would not start: ${stripSecrets(s.stderr || s.stdout)}` };
    }
    const r = await run('smbcontrol', ['smbd', 'reload-config']);
    return r.ok ? { ok: true } : { ok: false, message: `smbcontrol reload-config failed: ${stripSecrets(r.stderr || r.stdout)}` };
  }

  function generatePassword() {
    let out = '';
    while (out.length < PASSWORD_LENGTH) {
      const b = crypto.randomBytes(1)[0];
      if (b < 256 - (256 % PASSWORD_CHARS.length)) out += PASSWORD_CHARS[b % PASSWORD_CHARS.length];
    }
    return out;
  }

  // Creates the share user, or sets a new password for it. The password is generated here, returned once, never logged
  // or stored (only Samba keeps its hash). Needs the explicit call: nothing creates the user by itself.
  async function createUser(dry) {
    const problems = setupProblems();
    if (problems.length) return { code: 422, body: { error: 'bad_setup', message: problems[0] } };
    const st = readState();
    const exists = await userExists();
    if (exists && !(st.user && st.user.created_by_manager)) return { code: 409, body: { error: 'user_exists', message: `A system account named ${smb.user} exists that this manager did not create; choose another REMOMENT_SMB_USER.` } };
    const actions = [...(exists ? [] : [`create the system user ${smb.user} (no login, no home)`]), `${exists ? 'set a new' : 'set the'} Samba password of ${smb.user}`];
    if (dry || smb.dryRun) return { code: 200, body: { dry_run: true, user: smb.user, actions } };

    if (isBusy()) return { code: 409, body: { error: 'busy', message: `Another change is running: ${isBusy()}` } };
    setBusy('smb user');
    try {
      const created = !exists;
      if (created) {
        const u = await run('useradd', ['--system', '--no-create-home', '--shell', '/usr/sbin/nologin', '--user-group', '--', smb.user]);
        if (!u.ok) { audit('smb user', null, 'failed (useradd)'); return { code: 502, body: { error: 'useradd_failed', message: `useradd failed: ${stripSecrets(u.stderr || u.stdout)}` } }; }
        st.user = { created_by_manager: true, created_at: new Date().toISOString(), password_set_at: null };
        writeState(st);
      }
      const password = generatePassword();
      const p = await run('smbpasswd', ['-a', '-s', smb.user], { input: `${password}\n${password}\n` });
      if (!p.ok) { audit('smb user', null, 'failed (smbpasswd)'); return { code: 502, body: { error: 'smbpasswd_failed', message: `smbpasswd failed: ${stripSecrets(p.stderr || p.stdout, password)}` } }; }
      st.user = { ...(st.user || { created_by_manager: true, created_at: new Date().toISOString() }), password_set_at: new Date().toISOString() };
      writeState(st);
      audit('smb user', null, created ? 'created' : 'password reset');
      return { code: 200, body: { status: 'ok', user: smb.user, created, password, note: 'Shown once; it is not stored anywhere readable.' } };
    } finally { setBusy(null); }
  }

  async function removeUser(dry) {
    const st = readState();
    if (!st.user) return { code: 404, body: { error: 'no_user', message: 'There is no share user.' } };
    const enabled = Object.entries(st.shares).filter(([, s]) => s.enabled).map(([n]) => n);
    if (enabled.length) return { code: 409, body: { error: 'shares_enabled', message: `Disable the shares first (${enabled.join(', ')}).` } };
    const actions = [`remove ${smb.user} from Samba`, ...(st.user.created_by_manager ? [`delete the system user ${smb.user}`] : [])];
    if (dry || smb.dryRun) return { code: 200, body: { dry_run: true, user: smb.user, actions } };

    if (isBusy()) return { code: 409, body: { error: 'busy', message: `Another change is running: ${isBusy()}` } };
    setBusy('smb user');
    try {
      const r = await run('smbpasswd', ['-x', smb.user]);
      // A user that was never added to Samba's database answers non-zero: the rest still goes.
      if (st.user.created_by_manager) {
        const d = await run('userdel', ['--', smb.user]);
        if (!d.ok) { audit('smb user', null, 'failed (userdel)'); return { code: 502, body: { error: 'userdel_failed', message: `userdel failed: ${stripSecrets(d.stderr || d.stdout)}` } }; }
      }
      writeState({ shares: st.shares, user: null });
      audit('smb user', null, r.ok ? 'removed' : 'removed (samba entry was not there)');
      return { code: 200, body: { status: 'ok', user: smb.user, removed: true } };
    } finally { setBusy(null); }
  }

  return { status, currentConfig, setShare, createUser, removeUser, SHARES };
}

module.exports = { createSmb, smbConfig, pathError, SHARES };
