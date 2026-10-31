<?php

namespace Tests\Unit;

use Tests\TestCase;

/** docs/architecture/profiles.md shows the capability table of config/profile.php: they may not drift apart. */
class ProfileDocsTest extends TestCase
{
    public function test_the_documented_capability_table_matches_config(): void
    {
        $doc = file_get_contents(base_path('docs/architecture/profiles.md'));
        preg_match('/<!-- capability-table:start -->\n(.*?)<!-- capability-table:end -->/s', $doc, $m);

        $expected = "| Gate | What | Control | Standard | Full |\n|---|---|---|---|---|\n";
        foreach (config('profile.features') as $key => $feature) {
            $cell = fn (string $p) => in_array($p, $feature['profiles'], true) ? 'yes' : '–';
            $expected .= "| `{$key}` | {$feature['label']} | {$cell('control')} | {$cell('standard')} | {$cell('full')} |\n";
        }

        $this->assertSame($expected, $m[1] ?? null, 'Regenerate the table in docs/architecture/profiles.md from config/profile.php');
    }
}
