<?php

namespace App\Console\Commands;

use App\Domain\HomeAssistant\DiscoveryPublisher;
use App\Domain\HomeAssistant\HomeAssistant;
use Illuminate\Console\Command;

class HaCommandsCommand extends Command
{
    protected $signature = 'ha:commands {state? : on or off; without it the state is shown}';

    protected $description = 'Switch the Home Assistant command path (scene buttons, allow-listed operations over MQTT) on or off';

    public function handle(DiscoveryPublisher $publisher): int
    {
        $state = $this->argument('state');

        if ($state !== null) {
            if (!in_array($state, ['on', 'off'], true)) {
                $this->components->error('Use on or off.');

                return self::FAILURE;
            }
            HomeAssistant::setCommandsEnabled($state === 'on');

            // The scene buttons appear or go with the switch.
            if (HomeAssistant::enabled()) {
                $publisher->publishConfigs();
            }
        }

        $this->components->info('Commands: '.(HomeAssistant::commandsEnabled() ? 'on' : 'off')
            .'; discovery: '.(HomeAssistant::enabled() ? 'on' : 'off')
            .(HomeAssistant::commandsEnabled() && !HomeAssistant::enabled() ? ' (commands are only served while discovery is on)' : ''));

        return self::SUCCESS;
    }
}
