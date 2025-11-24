<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;

class GetCurrentPlayingMedia extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'app:get-current-playing-media';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Command description';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $devices = [
            'livingroom' => 'http://192.168.1.25:8080/BeoNotify/Notifications',
            // add more devices here
        ];

        foreach ($devices as $id => $url) {
            $cacheKey = "listener_running_{$id}";

            if (cache()->get($cacheKey)) {
                $this->info("Skipping {$id}, already running.");

                continue;
            }

            $this->info("Spawning listener for {$id}...");

            // fire-and-forget child process
            $cmd = 'php '.base_path('artisan')." device:listen-single {$id} > /dev/null 2>&1 &";
            shell_exec($cmd);
        }
    }
}
