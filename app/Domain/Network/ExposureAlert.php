<?php

namespace App\Domain\Network;

use App\Domain\Alerts\Alerts;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

/**
 * Raises and resolves the warn-only `network.exposed` alert (docs/architecture/network-exposure.md).
 * A request that looks public is noted in the cache (at most once a minute, no database write) and raises the alert;
 * the every-minute alerts:check resolves it once nothing public was seen for an hour and APP_URL is private.
 */
final class ExposureAlert
{
    public const KIND = 'network.exposed';

    private const SEEN_KEY = 'exposure:request-reasons';

    private const THROTTLE_KEY = 'exposure:request-throttle';

    private const SEEN_TTL_MINUTES = 60;

    public static function observe(Request $request): void
    {
        $reasons = ExposureCheck::assessRequest(
            $request->headers->get('Host'),
            $request->headers->get('X-Forwarded-Host'),
            $request->headers->get('X-Forwarded-For'),
            $request->server->get('REMOTE_ADDR'),
            $request->server->get('SERVER_ADDR'),
        );

        if ($reasons === []) {
            return;
        }

        $plain = ExposureCheck::isPlainHttp($request->getScheme(), $request->headers->get('X-Forwarded-Proto'));

        // Remember it for the hour, and raise at most once a minute.
        if (Cache::add(self::THROTTLE_KEY, true, now()->addMinute())) {
            Cache::put(self::SEEN_KEY, ['reasons' => $reasons, 'plain' => $plain], now()->addMinutes(self::SEEN_TTL_MINUTES));
            self::raise($reasons, $plain);
        }
    }

    /** The scheduled check: APP_URL and what requests showed lately. */
    public static function sweep(): void
    {
        $seen = Cache::get(self::SEEN_KEY);
        $reasons = array_values(array_unique(array_merge(
            ExposureCheck::assessConfigured(config('app.url')),
            $seen['reasons'] ?? [],
        )));

        if ($reasons === []) {
            Alerts::resolve(self::KIND);

            return;
        }

        $plain = ($seen['plain'] ?? false) || str_starts_with((string) config('app.url'), 'http://');
        self::raise($reasons, $plain);
    }

    private static function raise(array $reasons, bool $plain): void
    {
        $what = [
            ExposureCheck::HOST_PUBLIC => 'it was reached through a public name or address',
            ExposureCheck::CLIENT_PUBLIC => 'a request came from a public address',
            ExposureCheck::APP_URL_PUBLIC => 'APP_URL points to a public name or address',
            ExposureCheck::SERVER_PUBLIC => 'it listens on a public address',
        ];
        sort($reasons);
        $because = implode('; ', array_map(fn ($r) => $what[$r] ?? $r, $reasons));

        $message = "ReMoment looks reachable from the internet: {$because}. The API and MQTT have no authentication, so anyone who can reach it can control your devices."
            .($plain ? ' It is also served over plain HTTP.' : '')
            .' Do not forward its ports; use a VPN, or put it behind a reverse proxy with TLS and authentication (docs/user/reverse-proxy.md). This is a warning only; nothing is blocked.';

        Alerts::raise(self::KIND, '', 'warning', 'ReMoment may be reachable from the internet', $message,
            data: ['reasons' => $reasons, 'plain_http' => $plain]);
    }
}
