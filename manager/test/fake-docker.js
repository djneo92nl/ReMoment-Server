'use strict';
// Writes a fake `docker` script into a directory and returns helpers. The script answers the few
// commands the manager uses with canned files and records every call (its arguments) in calls.jsonl.
const fs = require('fs');
const path = require('path');

function install(dir) {
  const bin = path.join(dir, 'bin');
  fs.mkdirSync(bin, { recursive: true });
  const script = `#!${process.execPath}
const fs = require('fs');
const path = require('path');
const dir = ${JSON.stringify(dir)};
const args = process.argv.slice(2);
fs.appendFileSync(path.join(dir, 'calls.jsonl'), JSON.stringify(args) + '\\n');
const read = (f) => { try { return fs.readFileSync(path.join(dir, f), 'utf8'); } catch { return ''; } };
let rest = args;
if (args[0] === 'compose') { const i = args.indexOf('-p'); rest = args.slice(i + 2); if (rest[0] === '--profile') rest = rest.slice(2); }
const cmd = args[0] === 'compose' ? 'compose ' + rest[0] : args[0];
if (cmd === 'compose config') process.stdout.write(read('services.txt'));
else if (cmd === 'compose ps') process.stdout.write(read('ps.ndjson'));
else if (cmd === 'inspect') process.stdout.write(read('inspect.json'));
else if (cmd === 'stats') process.stdout.write(read('stats.ndjson'));
else if (cmd === 'compose logs') { const n = Number(rest[rest.indexOf('--tail') + 1]); for (let i = 1; i <= n; i++) console.log('line ' + i); }
else if (cmd === 'compose up' || cmd === 'compose stop') process.exit(read('start-fail') ? 1 : 0);
else if (cmd === 'compose restart') {
  const ms = Number(read('restart-sleep-ms') || 0);
  if (read('restart-fail')) { process.stderr.write('boom'); process.exit(1); }
  setTimeout(() => process.exit(0), ms);
} else { process.stderr.write('unexpected: ' + args.join(' ')); process.exit(2); }
`;
  fs.writeFileSync(path.join(bin, 'docker'), script, { mode: 0o755 });
  return {
    bin,
    set(file, content) { fs.writeFileSync(path.join(dir, file), content); },
    calls() {
      try { return fs.readFileSync(path.join(dir, 'calls.jsonl'), 'utf8').split('\n').filter(Boolean).map((l) => JSON.parse(l)); } catch { return []; }
    },
    clear() { fs.rmSync(path.join(dir, 'calls.jsonl'), { force: true }); },
  };
}

module.exports = { install };
