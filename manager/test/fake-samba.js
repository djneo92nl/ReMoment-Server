'use strict';
// Fake host commands for the SMB tests: id, useradd, userdel, smbpasswd, testparm, systemctl, smbcontrol, smbstatus.
// Each one is a script put first on PATH that records its arguments (and stdin for smbpasswd) in calls.jsonl and
// answers from flag files in the same directory. Nothing real is touched: no Samba, no system user, no systemd.
const fs = require('fs');
const path = require('path');

const COMMANDS = ['id', 'useradd', 'userdel', 'smbpasswd', 'testparm', 'systemctl', 'smbcontrol', 'smbstatus'];

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
if (name === 'smbpasswd' && args.includes('-a')) entry.stdin = fs.readFileSync(0, 'utf8');
if (name === 'testparm') { try { entry.file = fs.readFileSync(args[args.length - 1], 'utf8'); } catch {} }
fs.appendFileSync(path.join(dir, 'calls.jsonl'), JSON.stringify(entry) + '\\n');
if (read(name + '-fail')) { process.stderr.write(name + ' failed: ' + read(name + '-fail')); process.exit(1); }
if (name === 'id') process.exit(read('user-exists') ? 0 : 1);
if (name === 'useradd') fs.writeFileSync(path.join(dir, 'user-exists'), '1');
if (name === 'userdel') fs.rmSync(path.join(dir, 'user-exists'), { force: true });
if (name === 'systemctl' && args[0] === 'is-active') { process.stdout.write(read('active') ? 'active\\n' : 'inactive\\n'); process.exit(read('active') ? 0 : 3); }
if (name === 'systemctl' && args[0] === 'start') fs.writeFileSync(path.join(dir, 'active'), '1');
if (name === 'smbstatus') process.stdout.write(read('smbstatus.json') || '{}');
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
