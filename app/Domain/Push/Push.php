<?php

namespace App\Domain\Push;

use App\Jobs\SendAlertPush;
use App\Models\Alert;
use App\Models\PushSubscription;
use Illuminate\Support\Facades\Log;

/** The glue between alerts and Web Push. Nothing here may stop an alert from being raised. */
final class Push
{
    /** Only these severities wake a closed browser. */
    public const SEVERITIES = ['warning', 'critical'];

    /** Called by Alerts when an alert is new or got more severe; queues the delivery. Never throws. */
    public static function alert(Alert $alert): void
    {
        try {
            if (!in_array($alert->severity, self::SEVERITIES, true) || !PushSubscription::query()->exists()) {
                return;
            }
            SendAlertPush::dispatch($alert->id, $alert->severity);
        } catch (\Throwable $e) {
            Log::warning("Push for alert {$alert->id} was not queued: {$e->getMessage()}");
        }
    }

    /** @return array<string, mixed> what the service worker shows */
    public static function payload(Alert $alert): array
    {
        return [
            'id' => $alert->id,
            'severity' => $alert->severity,
            'title' => $alert->title,
            'body' => $alert->message,
            'tag' => 'remoment-alert-'.$alert->id, // the same tag as the in-page notification: an open tab and a push show one
            'url' => '/settings/health',
        ];
    }

    /** Sends to every subscription, deleting the ones that are gone. @return array{sent: int, removed: int, failed: int} */
    public static function deliver(array $payload): array
    {
        $sender = app(PushSender::class);
        $sent = $removed = $failed = 0;

        foreach (PushSubscription::query()->cursor() as $subscription) {
            try {
                $result = $sender->send($subscription, $payload);
            } catch (\Throwable $e) {
                $result = new PushResult(false, false, $e->getMessage());
            }

            if ($result->ok) {
                $sent++;
                $subscription->forceFill(['last_success_at' => now()])->save();
            } elseif ($result->gone) {
                $subscription->delete();
                $removed++;
            } else {
                $failed++;
                Log::info("Push to subscription {$subscription->id} failed: {$result->reason}");
            }
        }

        return compact('sent', 'removed', 'failed');
    }

    /** Is the endpoint one of the browsers' push services? https only, no IP address, a host on the allow list. */
    public static function allowedEndpoint(string $endpoint): bool
    {
        $parts = parse_url($endpoint);
        if (!is_array($parts) || ($parts['scheme'] ?? '') !== 'https' || isset($parts['user']) || isset($parts['port']) && $parts['port'] !== 443) {
            return false;
        }
        $host = strtolower((string) ($parts['host'] ?? ''));
        if ($host === '' || filter_var($host, FILTER_VALIDATE_IP) !== false) {
            return false;
        }
        foreach ((array) config('push.allowed_hosts', []) as $allowed) {
            $allowed = strtolower(trim((string) $allowed));
            if ($allowed !== '' && ($host === $allowed || str_ends_with($host, '.'.$allowed))) {
                return true;
            }
        }

        return false;
    }
}
