<?php

namespace App\Domain\Health;

use Closure;

/**
 * How many `queue:work` processes serve each queue, read from the process table (the supervised workers run in the app
 * container, next to the web process). Null where the table can't be read (macOS, another container): worker presence is
 * then unknown, not absent.
 */
class QueueWorkers
{
    /** @param Closure():?list<string>|null $commandLines the command lines of the running processes, null when unreadable */
    public function __construct(private ?Closure $commandLines = null)
    {
        $this->commandLines ??= function (): ?array {
            $files = glob('/proc/[0-9]*/cmdline');
            if (!$files) {
                return null;
            }

            return array_map(fn ($file) => str_replace("\0", ' ', (string) @file_get_contents($file)), $files);
        };
    }

    /** @return array<string, int>|null queue name => worker processes listening on it */
    public function counts(): ?array
    {
        $lines = ($this->commandLines)();
        if ($lines === null) {
            return null;
        }

        $counts = [];
        foreach ($lines as $line) {
            if (str_contains($line, 'artisan queue:work') && preg_match('/--queue=(\S+)/', $line, $m)) {
                foreach (explode(',', $m[1]) as $queue) {
                    $counts[$queue] = ($counts[$queue] ?? 0) + 1;
                }
            }
        }

        return $counts;
    }
}
