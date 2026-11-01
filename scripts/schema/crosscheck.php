<?php

// Cross-check of the Eloquent models against the schema the migrations build: columns that $fillable, $casts, $dates or
// $attributes name but the table lacks; tables without a model; models without a table. Run by `schema.sh crosscheck`
// against a fresh, migrated SQLite file inside the throw-away container (boots the app, reads no data).

require __DIR__.'/../../vendor/autoload.php';
$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use Illuminate\Support\Facades\Schema;

$tables = collect(Schema::getTables())->pluck('name')->reject(fn ($t) => str_starts_with($t, 'sqlite_'))->all();
$used = [];
$issues = [];

$files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(__DIR__.'/../../app/Models'));
foreach ($files as $f) {
    if (!str_ends_with($f, '.php')) {
        continue;
    }
    $class = 'App\\'.str_replace(['/', '.php'], ['\\', ''], substr($f->getPathname(), strlen(realpath(__DIR__.'/../../app')) + 1));
    $class = 'App\\'.str_replace(['/', '.php'], ['\\', ''], ltrim(str_replace(realpath(__DIR__.'/../../app'), '', realpath($f->getPathname())), '/'));
    if (!class_exists($class) || !is_subclass_of($class, Illuminate\Database\Eloquent\Model::class) || (new ReflectionClass($class))->isAbstract()) {
        continue;
    }
    $m = new $class;
    $t = $m->getTable();
    $used[$t][] = $class;
    if (!in_array($t, $tables, true)) {
        $issues[] = "MODEL WITHOUT TABLE  $class -> $t";
        continue;
    }
    $cols = Schema::getColumnListing($t);
    $named = array_merge($m->getFillable(), array_keys($m->getCasts()), array_keys($m->getAttributes()));
    foreach (array_unique($named) as $c) {
        if ($c === $m->getKeyName() && $m->getIncrementing()) {
            continue;
        }
        if (!in_array($c, $cols, true)) {
            $issues[] = "COLUMN NOT IN TABLE  $class::$c (table $t)";
        }
    }
    if ($m->usesTimestamps()) {
        foreach ([$m->getCreatedAtColumn(), $m->getUpdatedAtColumn()] as $c) {
            if ($c && !in_array($c, $cols, true)) {
                $issues[] = "TIMESTAMPS ON, COLUMN MISSING  $class::$c (table $t)";
            }
        }
    } else {
        foreach (['created_at', 'updated_at'] as $c) {
            if (in_array($c, $cols, true)) {
                $issues[] = "TIMESTAMPS OFF, COLUMN EXISTS  $class::$c (table $t)";
            }
        }
    }
    $guarded = $m->getFillable() === [] && $m->getGuarded() === [] ? 'unguarded' : '';
}

foreach ($tables as $t) {
    if (!isset($used[$t])) {
        $issues[] = "TABLE WITHOUT MODEL  $t";
    }
}
sort($issues);
echo implode("\n", $issues), "\n";
echo "\nmodels per table:\n";
ksort($used);
foreach ($used as $t => $c) {
    echo "  $t: ".implode(', ', array_map(fn ($x) => class_basename($x), $c))."\n";
}
