<?php

namespace App\Integrations\Wiim\Console;

use App\Integrations\Wiim\WiimClient;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;

/**
 * Asks a WiiM the read-only commands of its HTTP API and records the raw answers, so an owner can send the output to
 * confirm (or correct) the driver's fixtures. GET only, and only commands that change nothing: no setPlayerCmd,
 * no EQLoad, no shutdown.
 */
class WiimProbe extends Command
{
    protected $signature = 'wiim:probe {host : IP address of the WiiM} {--out= : Capture file (default: a .json in storage/app/wiim-probes)}';

    protected $description = 'Ask a WiiM the read-only commands of its HTTP API (getStatusEx, getPlayerStatus, getMetaInfo, getPresetInfo, EQGetList ...) and record the answers';

    /** Never anything that changes state. */
    public const COMMANDS = ['getStatusEx', 'getPlayerStatus', 'getMetaInfo', 'getPresetInfo', 'EQGetStat', 'EQGetList', 'getShutdown', 'getNewAudioOutputHardwareMode'];

    public function handle(): int
    {
        $host = (string) $this->argument('host');
        $client = new WiimClient('https://'.$host);
        $results = [];
        $answered = 0;

        $this->info("Probing {$host}: read-only, GET only, nothing is sent that changes the device.");

        foreach (self::COMMANDS as $command) {
            try {
                $body = $client->raw($command);
                $results[$command] = ['ok' => true, 'body' => $body, 'json' => WiimClient::decode($body)];
                $answered++;
                $this->line("  <info>ok</info>    {$command} (".strlen($body).' bytes)');
            } catch (\Throwable $e) {
                $results[$command] = ['ok' => false, 'error' => $e->getMessage()];
                $this->line("  <comment>fail</comment>  {$command}: ".$e->getMessage());
            }
        }

        $file = $this->option('out') ?: storage_path('app/wiim-probes/'.$host.'-'.date('Ymd-His').'.json');
        File::ensureDirectoryExists(dirname($file));
        File::put($file, json_encode(['host' => $host, 'at' => date('c'), 'results' => $results], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

        $this->info('Saved '.$file.' (it holds the device name, MAC and network details: look it over before you send it).');

        return $answered > 0 ? self::SUCCESS : self::FAILURE;
    }
}
