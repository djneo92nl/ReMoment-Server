<?php

namespace App\Console\Commands;

use App\Integrations\BangOlufsen\Ase\MusicPlayerDriver as AseMusicPlayerDriver;
use App\Integrations\Spotify\MusicPlayerDriver as SpotifyMusicPlayerDriver;
use App\Models\Device;
use App\Services\SpotifyTokenService;
use Illuminate\Console\Command;
use Symfony\Component\Process\Process;

class ListenAllDevices extends Command
{
    protected $signature = 'device:listen';

    protected $description = 'Start listeners for all configured devices.';

    private array $driverCommandMap = [
        AseMusicPlayerDriver::class => 'device-ase:listen-single',
    ];

    public function handle(): void
    {
        $processes = [];

        if (app(SpotifyTokenService::class)->isConnected()) {
            $this->info('Spotify is connected — starting Spotify listener...');
            $process = new Process(['php', 'artisan', 'device-spotify:listen']);
            $process->setTimeout(null);
            $process->start();
            $processes['spotify'] = ['process' => $process, 'label' => 'Spotify'];
        }

        $devices = Device::all();

        foreach ($devices as $device) {
            if ($device->device_driver === SpotifyMusicPlayerDriver::class) {
                continue;
            }

            $command = $this->driverCommandMap[$device->device_driver] ?? null;

            if (! $command) {
                $this->warn("No listener command for driver [{$device->device_driver}] on device [{$device->device_name}], skipping.");
                continue;
            }

            $this->info("Starting listener for {$device->device_name} (ID: {$device->id})...");

            $process = new Process(['php', 'artisan', $command, $device->id]);
            $process->setTimeout(null);
            $process->start();

            $processes[$device->id] = ['process' => $process, 'label' => $device->device_name];
        }

        if (empty($processes)) {
            $this->warn('No listeners started.');
            return;
        }

        $this->info('All listeners started. Press Ctrl+C to stop.');

        while (true) {
            foreach ($processes as $id => ['process' => $process, 'label' => $label]) {
                if ($output = $process->getIncrementalOutput()) {
                    $this->line("[{$label}] " . trim($output));
                }
                if ($error = $process->getIncrementalErrorOutput()) {
                    $this->error("[{$label}] " . trim($error));
                }

                if (! $process->isRunning()) {
                    $this->warn("[{$label}] process exited (code {$process->getExitCode()}), restarting...");

                    if ($id === 'spotify') {
                        $cmd = ['php', 'artisan', 'device-spotify:listen'];
                    } else {
                        $device = Device::find($id);
                        $cmd = ['php', 'artisan', $this->driverCommandMap[$device->device_driver], $id];
                    }

                    $newProcess = new Process($cmd);
                    $newProcess->setTimeout(null);
                    $newProcess->start();
                    $processes[$id]['process'] = $newProcess;
                }
            }

            usleep(200000);
        }
    }
}
