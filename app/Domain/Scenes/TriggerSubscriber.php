<?php

namespace App\Domain\Scenes;

use Illuminate\Support\Facades\Log;

/**
 * The MQTT side of scene triggers (docs/api/scenes.md): keeps a client subscribed to exactly the topic filters of the
 * scenes' MQTT triggers and hands each message to SceneTriggers. The server otherwise only publishes; this is its one
 * subscriber, run by `php artisan scenes:listen` under supervisor (program `scene-triggers`, profile feature `scenes`).
 *
 * `$client` is a php-mqtt client (`subscribe`, `unsubscribe`); kept untyped so a test can pass a recorder.
 * Retained messages are ignored: a broker replays them on every subscribe, which would start scenes at each restart.
 */
class TriggerSubscriber
{
    /** @var array<string, true> */
    private array $subscribed = [];

    public function __construct(private readonly SceneTriggers $triggers) {}

    /**
     * Subscribes to the filters that are new and unsubscribes from the ones no scene wants any more.
     *
     * @return array{added: list<string>, removed: list<string>}
     */
    public function sync(object $client): array
    {
        $wanted = array_fill_keys(SceneTriggers::mqttFilters(), true);
        $added = array_keys(array_diff_key($wanted, $this->subscribed));
        $removed = array_keys(array_diff_key($this->subscribed, $wanted));

        foreach ($removed as $filter) {
            $client->unsubscribe($filter);
        }
        foreach ($added as $filter) {
            $client->subscribe($filter, fn (string $topic, string $message, bool $retained = false) => $this->message($topic, $message, $retained));
        }
        $this->subscribed = $wanted;

        return ['added' => $added, 'removed' => $removed];
    }

    /** A new connection has no subscriptions: forget what was subscribed on the old one. */
    public function reset(): void
    {
        $this->subscribed = [];
    }

    /** @return list<int> the scenes started */
    public function message(string $topic, string $message, bool $retained = false): array
    {
        if ($retained) {
            return [];
        }

        try {
            return $this->triggers->mqtt($topic, $message);
        } catch (\Throwable $e) {
            Log::warning("Scene trigger (mqtt {$topic}) failed: {$e->getMessage()}");

            return [];
        }
    }
}
