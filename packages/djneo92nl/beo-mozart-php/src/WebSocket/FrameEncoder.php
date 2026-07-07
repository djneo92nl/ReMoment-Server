<?php

namespace Djneo92nl\BeoMozart\WebSocket;

/**
 * Minimal RFC 6455 frame builder for the handful of client->server frames
 * this listener needs to send (pong replies to the server's keepalive
 * pings). Reading frames is inherently stream/socket-driven and lives
 * directly in NotificationClient; this class only covers the pure,
 * testable half — building an outgoing masked frame from a payload.
 */
class FrameEncoder
{
    public const OPCODE_CONTINUATION = 0x0;

    public const OPCODE_TEXT = 0x1;

    public const OPCODE_CLOSE = 0x8;

    public const OPCODE_PING = 0x9;

    public const OPCODE_PONG = 0xA;

    public static function encode(string $payload, int $opcode): string
    {
        $byte1 = 0x80 | $opcode; // FIN=1, no fragmentation needed for our outgoing frames
        $length = strlen($payload);
        $maskKey = random_bytes(4);

        if ($length <= 125) {
            $header = chr($byte1).chr($length | 0x80);
        } elseif ($length < 65536) {
            $header = chr($byte1).chr(126 | 0x80).pack('n', $length);
        } else {
            $header = chr($byte1).chr(127 | 0x80).pack('J', $length);
        }

        $masked = '';
        for ($i = 0; $i < $length; $i++) {
            $masked .= $payload[$i] ^ $maskKey[$i % 4];
        }

        return $header.$maskKey.$masked;
    }
}
