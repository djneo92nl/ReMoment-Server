<?php

namespace App\Domain\Push;

use App\Models\PushSubscription;
use Minishlink\WebPush\Subscription;
use Minishlink\WebPush\WebPush;

/** minishlink/web-push (RFC 8030/8291/8292); the payload is encrypted for the browser, the request signed with the VAPID key. */
final class WebPushSender implements PushSender
{
    public function send(PushSubscription $subscription, array $payload): PushResult
    {
        $webPush = new WebPush(['VAPID' => Vapid::auth()], ['TTL' => (int) config('push.ttl', 3600), 'urgency' => 'high'], 15);

        $report = $webPush->sendOneNotification(
            Subscription::create(['endpoint' => $subscription->endpoint, 'publicKey' => $subscription->p256dh, 'authToken' => $subscription->auth, 'contentEncoding' => 'aes128gcm']),
            (string) json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        );

        if ($report->isSuccess()) {
            return new PushResult(true);
        }

        return new PushResult(false, $report->isSubscriptionExpired(), $report->getReason());
    }
}
