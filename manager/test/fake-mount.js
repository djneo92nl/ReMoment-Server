'use strict';
// Fake host commands for the NAS mount tests: systemctl and systemd-analyze. Scripts put first on PATH that record their
// arguments in calls.jsonl and answer from flag files in the same directory. Nothing real is touched: no systemd, no
// mount. `ls` is the real one, run against temp directories; "mounted" is a fixture mountinfo file the tests write.
const fs = require('fs');
const path = require('path');

const COMMANDS = ['systemctl', 'systemd-analyze', 'ls'];

function install(dir) {
  const bin = path.join(dir, 'bin');
  fs.mkdirSync(bin, { recursive: true });
  for (const name of COMMANDS) {
    const script = `#!${process.execPath}
const fs = require('fs');
const path = require('path');
const dir = ${JSON.stringify(dir)};
const name = ${JSON.stringify(name)};
const args = process.argv.slice(2);
const read = (f) => { try { return fs.readFileSync(path.join(dir, f), 'utf8'); } catch { return ''; } };
const entry = { cmd: name, args };
if (name === 'systemd-analyze') entry.files = args.slice(1).map((f) => { try { return fs.readFileSync(f, 'utf8'); } catch { return null; } });
if (name !== 'ls') fs.appendFileSync(path.join(dir, 'calls.jsonl'), JSON.stringify(entry) + '\\n');
if (read(name + '-fail')) { process.stderr.write(name + ' failed: ' + read(name + '-fail')); process.exit(1); }
if (name === 'ls') {
  // The real ls, unless a test makes the share hang (a stalled NAS): then it sleeps past the probe timeout.
  if (read('ls-hang')) { setTimeout(() => process.exit(0), 3000); return; }
  const r = require('child_process').spawnSync('/bin/ls', args, { stdio: 'inherit' });
  process.exit(r.status === null ? 1 : r.status);
}
if (name === 'systemctl') {
  const units = () => read('active-units').split('\\n').filter(Boolean);
  const save = (u) => fs.writeFileSync(path.join(dir, 'active-units'), u.join('\\n') + '\\n');
  const targets = args.filter((a) => /\\.(mount|automount)$/.test(a));
  if (args[0] === 'is-active') { const on = units().includes(args[1]); process.stdout.write(on ? 'active\\n' : 'inactive\\n'); process.exit(on ? 0 : 3); }
  if (args[0] === 'enable' || args[0] === 'start') save([...new Set([...units(), ...targets])]);
  if (args[0] === 'disable') save(units().filter((u) => !targets.includes(u)));
  if (args[0] === 'stop') {
    if (read('stop-busy') && targets.some((t) => t.endsWith('.mount'))) { process.stderr.write('Failed to stop: target is busy'); process.exit(1); }
    save(units().filter((u) => !targets.includes(u)));
  }
}
`;
    fs.writeFileSync(path.join(bin, name), script, { mode: 0o755 });
  }
  return {
    bin,
    set(file, content = '1') { fs.writeFileSync(path.join(dir, file), content); },
    unset(file) { fs.rmSync(path.join(dir, file), { force: true }); },
    calls() { try { return fs.readFileSync(path.join(dir, 'calls.jsonl'), 'utf8').split('\n').filter(Boolean).map((l) => JSON.parse(l)); } catch { return []; } },
    clear() { fs.rmSync(path.join(dir, 'calls.jsonl'), { force: true }); },
  };
}

module.exports = { install };
