<?php

namespace App\Services\Network;

use InvalidArgumentException;
use RuntimeException;

/**
 * Wake-on-LAN: the magic packet (6 x 0xFF, then the MAC 16 times) as UDP to port 9. A sleeping device has no
 * ARP entry to unicast to, so it goes out as a broadcast, to 255.255.255.255 and to the /24 broadcast address
 * of the device's own address, and as a unicast to the device itself for the case a router keeps its entry.
 * From a container on a bridge network the broadcast may not leave the host: use host networking there.
 */
final class WakeOnLan
{
    /** @var (\Closure(string, array<int, string>): void)|null a test replaces the socket */
    public static ?\Closure $fake = null;

    public static function packet(string $mac): string
    {
        $hex = preg_replace('/[^0-9a-f]/i', '', $mac);

        if (strlen($hex) !== 12) {
            throw new InvalidArgumentException("Not a MAC address: {$mac}");
        }

        return str_repeat("\xFF", 6).str_repeat((string) hex2bin($hex), 16);
    }

    /** @return string[] where the packet is sent for a device at `$ip` (null: broadcast only) */
    public static function targets(?string $ip): array
    {
        $targets = ['255.255.255.255'];

        if ($ip !== null && filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
            $targets[] = preg_replace('/\.\d+$/', '.255', $ip);
            $targets[] = $ip;
        }

        return array_values(array_unique($targets));
    }

    public static function send(string $mac, ?string $ip = null): void
    {
        $packet = self::packet($mac);
        $targets = self::targets($ip);

        if (self::$fake !== null) {
            (self::$fake)($packet, $targets);

            return;
        }

        $socket = @socket_create(AF_INET, SOCK_DGRAM, SOL_UDP);
        if ($socket === false) {
            throw new RuntimeException('Could not open a UDP socket for Wake-on-LAN.');
        }

        socket_set_option($socket, SOL_SOCKET, SO_BROADCAST, 1);
        $sent = 0;

        foreach ($targets as $target) {
            if (@socket_sendto($socket, $packet, strlen($packet), 0, $target, 9) !== false) {
                $sent++;
            }
        }

        socket_close($socket);

        if ($sent === 0) {
            throw new RuntimeException('The Wake-on-LAN packet could not be sent.');
        }
    }
}
