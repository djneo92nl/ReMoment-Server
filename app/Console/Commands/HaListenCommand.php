<?php

namespace App\Console\Commands;

use App\Domain\HomeAssistant\CommandSubscriber;
use App\Domain\HomeAssistant\HomeAssistant;
use App\Domain\Profile\Profile;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use PhpMqtt\Client\ConnectionSettings;
use PhpMqtt\Client\MqttClient;

/**
 * The Home Assistant command subscriber (docs/architecture/home-assistant.md, "Commands from Home Assistant"). Supervised
 * as program `ha-commands`, started where the profile has the `home_assistant` feature; it stays idle, without a
 * broker connection, until the admin switches discovery and commands on (`php artisan ha:commands on`), and follows
 * both switches every few seconds. Reconnects with a growing wait when the broker is away.
 */
class HaListenCommand extends Command
{
    protected $signature = 'ha:listen';

    protected $description = 'Listen on MQTT for Home Assistant commands (scene buttons and allow-listed operations) while the opt-in is on.';

    private bool $stopping = false;

    public function handle(CommandSubscriber $subscriber): int
    {
        if (function_exists('pcntl_async_signals')) {
            pcntl_async_signals(true);
            pcntl_signal(SIGTERM, fn () => $this->stopping = true);
            pcntl_signal(SIGINT, fn () => $this->stopping = true);
        }

        $wait = 2;
        while (!$this->stopping) {
            if (!Profile::allows('home_assistant') || !HomeAssistant::commandsActive()) {
                $this->idle(5);

                continue;
            }

            try {
                $this->session($subscriber);
                $wait = 2;
            } catch (\Throwable $e) {
                Log::warning("Home Assistant command listener: {$e->getMessage()}; retrying in {$wait}s.");
                $this->idle($wait);
                $wait = min(60, $wait * 2);
            }
        }

        return self::SUCCESS;
    }

    private function idle(int $seconds): void
    {
        for ($i = 0; $i < $seconds && !$this->stopping; $i++) {
            sleep(1);
        }
    }

    /** One connection, until the broker drops us, we are told to stop, or the opt-in is switched off. */
    private function session(CommandSubscriber $subscriber): void
    {
        $subscriber->reset();
        $client = new MqttClient(config('mqtt.host'), config('mqtt.port'), config('mqtt.client_id').'-ha-'.getmypid());
        $client->connect((new ConnectionSettings)->setKeepAliveInterval(60), true);
        $this->info('Connected to the broker.');
        $subscriber->sync($client);

        $lastCheck = microtime(true);
        $client->registerLoopEventHandler(function (MqttClient $client) use (&$lastCheck) {
            if ($this->stopping || (microtime(true) - $lastCheck >= 5 && !$this->stillActive($lastCheck))) {
                $client->interrupt();
            }
        });

        try {
            $client->loop(true);
        } finally {
            try {
                $client->disconnect();
            } catch (\Throwable) {
                // Already gone.
            }
        }
    }

    private function stillActive(float &$lastCheck): bool
    {
        $lastCheck = microtime(true);

        return HomeAssistant::commandsActive();
    }
}
