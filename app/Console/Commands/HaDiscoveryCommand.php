<?php

namespace App\Console\Commands;

use App\Domain\HomeAssistant\DiscoveryPublisher;
use Illuminate\Console\Command;

class HaDiscoveryCommand extends Command
{
    protected $signature = 'ha:discovery {--remove : Clear every retained discovery config and mark the entities offline instead of publishing}';

    protected $description = 'Publish the Home Assistant MQTT discovery configs and states (retained), or clear them with --remove; works whether or not the setting is on';

    public function handle(DiscoveryPublisher $publisher): int
    {
        if ($this->option('remove')) {
            $this->components->info('Cleared '.$publisher->remove().' discovery configs.');

            return self::SUCCESS;
        }

        $this->components->info('Published '.$publisher->publishAll(force: true).' discovery configs and their states.');

        return self::SUCCESS;
    }
}
