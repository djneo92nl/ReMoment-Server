<?php

namespace App\Console\Commands;

use App\Models\DlnaServer;
use App\Services\Dlna\DlnaLibraryScanner;
use App\Services\Dlna\DlnaServerDiscovery;
use Illuminate\Console\Command;

class ScanDlnaLibrary extends Command
{
    protected $signature = 'library:scan {--server= : IP address of a specific server to scan}';

    protected $description = 'Discover DLNA media servers and index their music library';

    public function handle(DlnaServerDiscovery $discovery, DlnaLibraryScanner $scanner): int
    {
        if ($ip = $this->option('server')) {
            $server = DlnaServer::where('ip', $ip)->first();
            if (! $server) {
                $this->error("No known DLNA server with IP {$ip}. Run without --server to discover first.");

                return self::FAILURE;
            }
            $servers = [$server];
        } else {
            $this->info('Discovering DLNA media servers...');
            $servers = $discovery->discover();
            $this->line('Found '.count($servers).' server(s).');
        }

        if (empty($servers)) {
            $this->warn('No DLNA servers found.');

            return self::SUCCESS;
        }

        foreach ($servers as $server) {
            $this->info("Scanning: {$server->friendly_name} ({$server->ip}:{$server->port})");

            $bar = $this->output->createProgressBar();
            $bar->setFormat(' %current% tracks — %message%');
            $bar->start();

            $count = $scanner->scanServer($server, function (int $total, string $title) use ($bar) {
                $bar->setMessage(mb_strimwidth($title, 0, 50, '…'));
                $bar->setProgress($total);
            });

            $bar->finish();
            $this->newLine();
            $this->line("  → {$count} tracks indexed.");
        }

        return self::SUCCESS;
    }
}
