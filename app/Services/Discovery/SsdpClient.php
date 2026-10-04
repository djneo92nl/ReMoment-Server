<?php

namespace App\Services\Discovery;

use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * SSDP M-SEARCH over every local IPv4 interface, repeated a few times (UDP is lossy).
 *
 * Multicast does not leave a Docker bridge network: run the app with host networking
 * (see docs/architecture/device-discovery.md) or fall back to unicast probing.
 */
class SsdpClient
{
    private const ADDRESS = '239.255.255.250';

    private const PORT = 1900;

    /** Seconds after the start at which the M-SEARCH is (re)sent. */
    private const SEND_AT = [0.0, 0.6, 1.4];

    public function __construct(private float $timeout = 3.5) {}

    /**
     * @return array<int, array{ip: string, location: string, st: string|null, usn: string|null}> one per location
     */
    public function search(string $st): array
    {
        $sockets = $this->openSockets();
        if ($sockets === []) {
            return [];
        }

        $request = "M-SEARCH * HTTP/1.1\r\n".
            'HOST: '.self::ADDRESS.':'.self::PORT."\r\n".
            "MAN: \"ssdp:discover\"\r\n".
            "MX: 2\r\n".
            "ST: {$st}\r\n".
            "USER-AGENT: ReMoment/1.0 UPnP/1.1\r\n\r\n";

        $found = [];
        $start = microtime(true);
        $pending = self::SEND_AT;

        while (($elapsed = microtime(true) - $start) < $this->timeout) {
            while ($pending !== [] && $elapsed >= $pending[0]) {
                array_shift($pending);
                foreach ($sockets as $socket) {
                    @socket_sendto($socket, $request, strlen($request), 0, self::ADDRESS, self::PORT);
                }
            }

            $read = $sockets;
            $write = $except = [];
            if (@socket_select($read, $write, $except, 0, 200_000) < 1) {
                continue;
            }

            foreach ($read as $socket) {
                $buf = '';
                $from = '';
                $port = 0;
                if (@socket_recvfrom($socket, $buf, 4096, 0, $from, $port) === false) {
                    continue;
                }
                if ($response = self::parseResponse($buf, $from)) {
                    $found[$response['location']] = $response;
                }
            }
        }

        foreach ($sockets as $socket) {
            socket_close($socket);
        }

        return array_values($found);
    }

    /** Parse one SSDP response datagram; null when it carries no LOCATION. */
    public static function parseResponse(string $buf, string $from): ?array
    {
        $headers = [];
        foreach (explode("\r\n", $buf) as $line) {
            if (str_contains($line, ':')) {
                [$key, $val] = explode(':', $line, 2);
                $headers[strtolower(trim($key))] = trim($val);
            }
        }

        if (empty($headers['location'])) {
            return null;
        }

        return [
            'ip' => $from,
            'location' => $headers['location'],
            'st' => $headers['st'] ?? null,
            'usn' => $headers['usn'] ?? null,
        ];
    }

    /** Fetch a UPnP descriptor as an array (null when unreachable or not XML). */
    public function describe(string $location): ?array
    {
        try {
            $xml = Http::timeout(3)->get($location)->body();
        } catch (Throwable) {
            return null;
        }

        $parsed = $xml ? @simplexml_load_string($xml, 'SimpleXMLElement', LIBXML_NOCDATA) : false;

        return $parsed ? json_decode(json_encode($parsed), true) : null;
    }

    /** One socket per up, non-loopback IPv4 interface, so each sends on its own network. */
    private function openSockets(): array
    {
        $sockets = [];

        foreach ($this->localAddresses() as $address) {
            $socket = @socket_create(AF_INET, SOCK_DGRAM, SOL_UDP);
            if ($socket === false) {
                continue;
            }
            socket_set_option($socket, SOL_SOCKET, SO_REUSEADDR, 1);
            if (!@socket_bind($socket, $address, 0)) {
                socket_close($socket);

                continue;
            }
            @socket_set_option($socket, IPPROTO_IP, IP_MULTICAST_IF, $address);
            @socket_set_option($socket, IPPROTO_IP, IP_MULTICAST_TTL, 2);
            $sockets[] = $socket;
        }

        return $sockets;
    }

    /** @return string[] */
    private function localAddresses(): array
    {
        $addresses = [];

        foreach (net_get_interfaces() ?: [] as $interface) {
            if (!($interface['up'] ?? false)) {
                continue;
            }
            foreach ($interface['unicast'] ?? [] as $unicast) {
                $ip = $unicast['address'] ?? '';
                if (($unicast['family'] ?? null) === AF_INET && !str_starts_with($ip, '127.') && !str_starts_with($ip, '169.254.')) {
                    $addresses[] = $ip;
                }
            }
        }

        return $addresses === [] ? ['0.0.0.0'] : $addresses;
    }
}
