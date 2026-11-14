<?php

namespace App\Domain\Network;

/**
 * Decides from plain strings whether the address ReMoment is reached on looks private (LAN) or public.
 * Pure: no DNS, no network, so it is unit-testable. Warn-only: the caller turns the findings into an alert.
 * See docs/architecture/network-exposure.md.
 */
final class ExposureCheck
{
    public const HOST_PUBLIC = 'host_public';

    public const CLIENT_PUBLIC = 'client_public';

    public const APP_URL_PUBLIC = 'app_url_public';

    public const SERVER_PUBLIC = 'server_public';

    /** Name suffixes that never resolve on the public internet. */
    private const PRIVATE_SUFFIXES = ['local', 'lan', 'home', 'home.arpa', 'internal', 'intranet', 'private', 'localdomain', 'localhost', 'test', 'corp'];

    /** True for RFC1918, loopback, link-local, ULA, CGNAT (Tailscale) addresses, `.local`/`.lan`-style and single-label names. */
    public static function isPrivateHost(?string $host): bool
    {
        $host = self::normalizeHost($host);
        if ($host === null) {
            return true;   // nothing to judge
        }

        if (filter_var($host, FILTER_VALIDATE_IP)) {
            return self::isPrivateIp($host);
        }

        if (!str_contains($host, '.')) {
            return true;   // single label: resolved by the local network
        }

        foreach (self::PRIVATE_SUFFIXES as $suffix) {
            if ($host === $suffix || str_ends_with($host, '.'.$suffix)) {
                return true;
            }
        }

        return false;
    }

    public static function isPrivateIp(string $ip): bool
    {
        if (!filter_var($ip, FILTER_VALIDATE_IP)) {
            return true;
        }

        // IPv4-mapped IPv6 (::ffff:1.2.3.4)
        if (preg_match('/^::ffff:(\d+\.\d+\.\d+\.\d+)$/i', $ip, $m)) {
            $ip = $m[1];
        }

        if (str_contains($ip, ':')) {
            $lower = strtolower($ip);

            return $lower === '::1' || $lower === '::'
                || preg_match('/^f[cd][0-9a-f]{2}:/', $lower) === 1      // fc00::/7 unique local
                || preg_match('/^fe[89ab][0-9a-f]:/', $lower) === 1;     // fe80::/10 link local
        }

        $long = ip2long($ip);
        if ($long !== null && $long >= ip2long('100.64.0.0') && $long <= ip2long('100.127.255.255')) {
            return true;   // CGNAT, what Tailscale uses
        }

        return filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false;
    }

    /**
     * What looks reachable from outside, for one request.
     *
     * @param  string|null  $host  the Host header
     * @param  string|null  $forwardedHost  X-Forwarded-Host (first entry wins, then it replaces the Host)
     * @param  string|null  $forwardedFor  X-Forwarded-For (first entry is the original client)
     * @param  string|null  $remoteAddr  the connecting address
     * @param  string|null  $serverAddr  the address of the server's own socket the request came in on
     * @return list<string> reason codes, empty when everything looks private
     */
    public static function assessRequest(?string $host, ?string $forwardedHost = null, ?string $forwardedFor = null, ?string $remoteAddr = null, ?string $serverAddr = null): array
    {
        $reasons = [];

        $seenHost = self::first($forwardedHost) ?? $host;
        if (!self::isPrivateHost($seenHost)) {
            $reasons[] = self::HOST_PUBLIC;
        }

        $client = self::first($forwardedFor) ?? $remoteAddr;
        if ($client !== null && filter_var(self::normalizeHost($client), FILTER_VALIDATE_IP) && !self::isPrivateIp(self::normalizeHost($client))) {
            $reasons[] = self::CLIENT_PUBLIC;
        }

        $server = self::normalizeHost($serverAddr);
        if ($server !== null && filter_var($server, FILTER_VALIDATE_IP) && !self::isPrivateIp($server)) {
            $reasons[] = self::SERVER_PUBLIC;
        }

        return $reasons;
    }

    /** What the configured address (APP_URL, STREAM_BASE_URL) says, without a request. */
    public static function assessConfigured(?string $url): array
    {
        $host = $url ? parse_url($url, PHP_URL_HOST) : null;

        return is_string($host) && !self::isPrivateHost($host) ? [self::APP_URL_PUBLIC] : [];
    }

    /** Plain HTTP as the visitor sees it: X-Forwarded-Proto wins over the connection's own scheme. */
    public static function isPlainHttp(?string $scheme, ?string $forwardedProto = null): bool
    {
        return strtolower(self::first($forwardedProto) ?? $scheme ?? 'http') !== 'https';
    }

    /** Strips port, brackets and case from a host or address. */
    public static function normalizeHost(?string $host): ?string
    {
        $host = strtolower(trim((string) $host));
        if ($host === '') {
            return null;
        }

        if (str_starts_with($host, '[')) {
            $end = strpos($host, ']');

            return $end === false ? null : substr($host, 1, $end - 1);
        }

        if (substr_count($host, ':') === 1) {
            $host = explode(':', $host)[0];
        }

        return rtrim($host, '.') ?: null;
    }

    private static function first(?string $list): ?string
    {
        if ($list === null) {
            return null;
        }
        $first = trim(explode(',', $list)[0]);

        return $first === '' ? null : $first;
    }
}
