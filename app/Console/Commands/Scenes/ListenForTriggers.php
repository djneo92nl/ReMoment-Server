<?php

namespace App\Console\Commands\Scenes;

use App\Domain\Profile\Profile;
use App\Domain\Scenes\TriggerSubscriber;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use PhpMqtt\Client\ConnectionSettings;
use PhpMqtt\Client\MqttClient;

/**
 * Subscribes to the MQTT topic filters of the scenes' triggers and starts the matching scenes (docs/api/scenes.md,
 * "Triggers"). Supervised like `device:listen` (program `scene-triggers`, started only where the profile has the
 * `scenes` feature). Reconnects with a growing wait when the broker is away, and follows changes to the triggers
 * every few seconds without a restart.
 */
class ListenForTriggers extends Command
{
    protected $signature = 'scenes:listen';

    protected $description = 'Listen on MQTT for the topics of scene triggers and start the scenes they match.';

    private bool $stopping = false;

    public function handle(TriggerSubscriber $subscriber): int
    {
        if (!Profile::allows('scenes')) {
            $this->info('Scenes are not part of this profile; nothing to listen for.');
            while (!$this->stopping) {
                sleep(3600); // supervisor expects a long-running program
            }

            return self::SUCCESS;
        }

        if (function_exists('pcntl_async_signals')) {
            pcntl_async_signals(true);
            pcntl_signal(SIGTERM, fn () => $this->stopping = true);
            pcntl_signal(SIGINT, fn () => $this->stopping = true);
        }

        $wait = 2;
        while (!$this->stopping) {
            try {
                $this->session($subscriber);
                $wait = 2;
            } catch (\Throwable $e) {
                Log::warning("Scene trigger listener: {$e->getMessage()}; retrying in {$wait}s.");
            }

            for ($i = 0; $i < $wait && !$this->stopping; $i++) {
                sleep(1);
            }
            $wait = min(60, $wait * 2);
        }

        return self::SUCCESS;
    }

    /** One connection: subscribe, follow the triggers, until the broker drops us or we are told to stop. */
    private function session(TriggerSubscriber $subscriber): void
    {
        $subscriber->reset();
        $client = new MqttClient(config('mqtt.host'), config('mqtt.port'), config('mqtt.client_id').'-scenes-'.getmypid());
        $client->connect((new ConnectionSettings)->setKeepAliveInterval(60), true);
        $this->info('Connected to the broker.');

        $subscriber->sync($client);
        $every = max(1, (int) config('scenes.triggers.refresh_seconds'));
        $lastCheck = microtime(true);

        $client->registerLoopEventHandler(function (MqttClient $client) use ($subscriber, &$lastCheck, $every) {
            if ($this->stopping) {
                $client->interrupt();

                return;
            }
            if (microtime(true) - $lastCheck >= $every) {
                $lastCheck = microtime(true);
                $subscriber->sync($client);
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
}
