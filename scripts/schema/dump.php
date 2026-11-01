<?php

// Normalised schema dump of the database named by the environment: tables, columns (type, null, default, collation),
// indexes, foreign keys, check constraints. Sorted, with no auto-increment values, no timestamps and no data, so two dumps
// of two schemas built differently can be compared with `diff`. Run by scripts/schema/schema.sh inside a throw-away container.
//
//   SCHEMA_DRIVER=mysql  DB_HOST=… DB_PORT=… DB_DATABASE=… DB_USERNAME=… DB_PASSWORD=… php dump.php
//   SCHEMA_DRIVER=sqlite DB_DATABASE=/path/file.sqlite php dump.php

$driver = getenv('SCHEMA_DRIVER') ?: 'mysql';
$db = getenv('DB_DATABASE');

$pdo = $driver === 'sqlite'
    ? new PDO('sqlite:'.$db)
    : new PDO(sprintf('mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4', getenv('DB_HOST'), getenv('DB_PORT') ?: 3306, $db), getenv('DB_USERNAME'), getenv('DB_PASSWORD'));
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);

function q(PDO $pdo, string $sql, array $args = []): array
{
    $st = $pdo->prepare($sql);
    $st->execute($args);

    return $st->fetchAll();
}

$out = [];

if ($driver === 'sqlite') {
    $out[] = '# driver: sqlite';
    $tables = q($pdo, "SELECT name, sql FROM sqlite_master WHERE type = 'table' AND name NOT LIKE 'sqlite_%' ORDER BY name");
    foreach ($tables as $t) {
        $name = $t['name'];
        $auto = stripos($t['sql'], 'autoincrement') !== false;
        $out[] = '';
        $out[] = "TABLE $name".($auto ? ' [autoincrement]' : '');
        $cols = q($pdo, "PRAGMA table_info(\"$name\")");
        usort($cols, fn ($a, $b) => strcmp($a['name'], $b['name']));
        foreach ($cols as $c) {
            $out[] = sprintf('  col %s %s %s default=%s%s', $c['name'], strtolower($c['type'] ?: 'any'), $c['notnull'] ? 'NOT NULL' : 'NULL', $c['dflt_value'] ?? '-', $c['pk'] ? ' pk='.$c['pk'] : '');
        }
        $idx = [];
        foreach (q($pdo, "PRAGMA index_list(\"$name\")") as $i) {
            $cols = array_map(fn ($r) => $r['name'], q($pdo, "PRAGMA index_info(\"{$i['name']}\")"));
            // Auto indexes (inline PRIMARY KEY / UNIQUE) carry a counter in their name: key them by their columns.
            $label = str_starts_with($i['name'], 'sqlite_autoindex_') ? 'auto('.$i['origin'].')' : $i['name'];
            $idx[] = sprintf('  idx %s %s (%s)', $label, $i['unique'] ? 'UNIQUE' : 'INDEX', implode(',', $cols));
        }
        sort($idx);
        array_push($out, ...$idx);
        // Laravel's enum() becomes varchar + CHECK on SQLite; the dump shows it so a changed value list is a diff.
        preg_match_all('/check \("(\w+)" in \(([^)]*)\)\)/i', $t['sql'], $checks, PREG_SET_ORDER);
        $lines = array_map(fn ($c) => sprintf('  check %s in (%s)', $c[1], $c[2]), $checks);
        sort($lines);
        array_push($out, ...$lines);
        $fks = [];
        foreach (q($pdo, "PRAGMA foreign_key_list(\"$name\")") as $f) {
            $fks[] = sprintf('  fk (%s) -> %s(%s) ON DELETE %s ON UPDATE %s', $f['from'], $f['table'], $f['to'], $f['on_delete'], $f['on_update']);
        }
        sort($fks);
        array_push($out, ...$fks);
    }
} else {
    $ver = q($pdo, 'SELECT VERSION() v')[0]['v'];
    $out[] = '# driver: mariadb '.preg_replace('/^(\d+\.\d+).*/', '$1', $ver);
    $out[] = '# default collation: '.q($pdo, 'SELECT @@collation_database c')[0]['c'];
    $tables = q($pdo, "SELECT TABLE_NAME n, ENGINE e, TABLE_COLLATION c FROM information_schema.TABLES WHERE TABLE_SCHEMA = ? AND TABLE_TYPE = 'BASE TABLE' ORDER BY TABLE_NAME", [$db]);
    foreach ($tables as $t) {
        $name = $t['n'];
        $out[] = '';
        $out[] = "TABLE $name [engine={$t['e']} collation={$t['c']}]";
        $cols = q($pdo, 'SELECT COLUMN_NAME n, COLUMN_TYPE t, IS_NULLABLE nu, COLUMN_DEFAULT d, EXTRA x, COLLATION_NAME c FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ? ORDER BY COLUMN_NAME', [$db, $name]);
        foreach ($cols as $c) {
            $out[] = sprintf('  col %s %s %s default=%s%s%s', $c['n'], $c['t'], $c['nu'] === 'NO' ? 'NOT NULL' : 'NULL', $c['d'] ?? '-', $c['x'] ? ' extra='.$c['x'] : '', $c['c'] ? ' collate='.$c['c'] : '');
        }
        $idx = [];
        foreach (q($pdo, 'SELECT INDEX_NAME n, NON_UNIQUE u, SEQ_IN_INDEX s, COLUMN_NAME c, SUB_PART p, INDEX_TYPE t FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ? ORDER BY INDEX_NAME, SEQ_IN_INDEX', [$db, $name]) as $r) {
            $idx[$r['n']]['u'] = $r['u'] ? 'INDEX' : 'UNIQUE';
            $idx[$r['n']]['t'] = $r['t'];
            $idx[$r['n']]['c'][] = $r['c'].($r['p'] ? '('.$r['p'].')' : '');
        }
        $lines = [];
        foreach ($idx as $n => $i) {
            $lines[] = sprintf('  idx %s %s %s (%s)', $n, $i['u'], $i['t'], implode(',', $i['c']));
        }
        sort($lines);
        array_push($out, ...$lines);
        $fks = [];
        foreach (q($pdo, 'SELECT k.CONSTRAINT_NAME n, k.COLUMN_NAME c, k.REFERENCED_TABLE_NAME rt, k.REFERENCED_COLUMN_NAME rc, r.DELETE_RULE d, r.UPDATE_RULE u FROM information_schema.KEY_COLUMN_USAGE k JOIN information_schema.REFERENTIAL_CONSTRAINTS r ON r.CONSTRAINT_SCHEMA = k.CONSTRAINT_SCHEMA AND r.CONSTRAINT_NAME = k.CONSTRAINT_NAME AND r.TABLE_NAME = k.TABLE_NAME WHERE k.TABLE_SCHEMA = ? AND k.TABLE_NAME = ? AND k.REFERENCED_TABLE_NAME IS NOT NULL ORDER BY k.CONSTRAINT_NAME, k.ORDINAL_POSITION', [$db, $name]) as $f) {
            $fks[$f['n']]['to'] = $f['rt'];
            $fks[$f['n']]['rule'] = "ON DELETE {$f['d']} ON UPDATE {$f['u']}";
            $fks[$f['n']]['from'][] = $f['c'];
            $fks[$f['n']]['refs'][] = $f['rc'];
        }
        $lines = [];
        foreach ($fks as $n => $f) {
            $lines[] = sprintf('  fk %s (%s) -> %s(%s) %s', $n, implode(',', $f['from']), $f['to'], implode(',', $f['refs']), $f['rule']);
        }
        sort($lines);
        array_push($out, ...$lines);
        $lines = [];
        foreach (q($pdo, 'SELECT c.CONSTRAINT_NAME n, c.CHECK_CLAUSE x FROM information_schema.CHECK_CONSTRAINTS c WHERE c.CONSTRAINT_SCHEMA = ? AND c.TABLE_NAME = ?', [$db, $name]) as $c) {
            $lines[] = sprintf('  check %s %s', $c['n'], $c['x']);
        }
        sort($lines);
        array_push($out, ...$lines);
    }
}

echo implode("\n", $out), "\n";
