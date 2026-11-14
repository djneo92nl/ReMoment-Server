<?php

namespace App\Console\Commands;

use App\Domain\Diagnostics\DiagnosticsBundle;
use Illuminate\Console\Command;

class DiagnosticsBundleCommand extends Command
{
    protected $signature = 'diagnostics:bundle {--path= : File (or directory) to write the zip to; default storage/app/private/diagnostics}';

    protected $description = 'Write one redacted zip (versions, health, devices without addresses, alerts, failed jobs, log tail) to attach to an issue; never includes secrets';

    public function handle(): int
    {
        $path = $this->option('path') ?: storage_path('app/private/diagnostics');
        if (is_dir($path) || !str_ends_with(strtolower($path), '.zip')) {
            $path = rtrim($path, '/').'/remoment-diagnostics-'.now()->format('Ymd-His').'.zip';
        }
        if (!is_dir(dirname($path))) {
            mkdir(dirname($path), 0775, true);
        }

        $files = DiagnosticsBundle::make()->write($path);

        $this->info("Wrote {$path}");
        foreach ($files as $name => $bytes) {
            $this->line(sprintf('  %-24s %s', $name, number_format($bytes).' bytes'));
        }
        $this->line('Secrets, IP and MAC addresses are never included. Read README.txt inside before you attach it.');

        return self::SUCCESS;
    }
}
