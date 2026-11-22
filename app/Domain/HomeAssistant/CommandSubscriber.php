<?php

namespace App\Domain\HomeAssistant;

use Illuminate\Support\Facades\Log;

/**
 * The MQTT side of the Home Assistant command path: one subscription to `remoment/ha/command/#` while the path is
 * active (`HomeAssistant::commandsActive()`), dropped when it is switched off, every message to `CommandHandler`.
 * Run by `php artisan ha:listen` under supervisor. `$client` is a php-mqtt client (`subscribe`, `unsubscribe`), left
 * untyped so a test can pass a recorder instead of a broker.
 */
final class CommandSubscriber
{
    private bool $subscribed = false;

    public function __construct(private readonly CommandHandler $handler) {}

    /** Subscribes or unsubscribes to match the settings. @return 'subscribed'|'unsubscribed'|'unchanged' */
    public function sync(object $client): string
    {
        $active = HomeAssistant::commandsActive();

        if ($active && !$this->subscribed) {
            $client->subscribe(CommandHandler::FILTER, fn (string $topic, string $message, bool $retained = false) => $this->message($topic, $message, $retained));
            $this->subscribed = true;

            return 'subscribed';
        }
        if (!$active && $this->subscribed) {
            $client->unsubscribe(CommandHandler::FILTER);
            $this->subscribed = false;

            return 'unsubscribed';
        }

        return 'unchanged';
    }

    /** A new connection has no subscriptions. */
    public function reset(): void
    {
        $this->subscribed = false;
    }

    /** @return array<string, mixed>|null */
    public function message(string $topic, string $message, bool $retained = false): ?array
    {
        if ($retained) {
            return null;
        }

        try {
            return $this->handler->handle($topic, $message, false);
        } catch (\Throwable $e) {
            Log::warning("Home Assistant command ({$topic}) failed: {$e->getMessage()}");

            return null;
        }
    }
}
