<?php

namespace Djneo92nl\BeoMozart\WebSocket;

/**
 * Minimal, dependency-free RFC 6455 WebSocket client for the Mozart
 * real-time notification feed, built on raw PHP streams rather than an
 * external library — matching this codebase's existing convention of
 * hand-rolled raw-socket protocol clients (see AseDiscovery's SSDP and
 * MozartDiscoveryService's mDNS, both in the main app).
 *
 * NOTE: port 9000 is NOT documented anywhere in the Mozart OpenAPI spec — it
 * has no `servers:` section and no port/path for real-time notifications at
 * all. This default is based on external/community knowledge of B&O's
 * official client libraries and MUST be verified against real hardware.
 */
class NotificationClient
{
    private const HANDSHAKE_GUID = '258EAFA5-E914-47DA-95CA-C5AB0DC85B11';

    public function __construct(
        protected string $host,
        protected int $port = 9000,
        protected string $path = '/',
    ) {}

    /**
     * Blocking. Connects and loops until the connection fails, invoking
     * $onEvent for every well-formed {eventType, eventData} text payload
     * received. Malformed/unrecognized JSON frames are silently skipped.
     * Does not reconnect on failure — that's the caller's responsibility
     * (mirrors the ASE driver's split between a dumb transport and a
     * listener that owns retry/backoff).
     *
     * @param  callable(string $eventType, mixed $eventData, array $raw): void  $onEvent
     */
    public function connectAndListen(callable $onEvent, ?callable $onError = null): void
    {
        $socket = @stream_socket_client(
            "tcp://{$this->host}:{$this->port}",
            $errno,
            $errstr,
            10
        );

        if ($socket === false) {
            throw new MozartWebSocketException("Unable to connect to Mozart WebSocket at {$this->host}:{$this->port}: {$errstr}");
        }

        // Effectively no read timeout — mirrors the ASE driver's Guzzle
        // stream, which is opened with timeout=0 ("allow hanging").
        stream_set_timeout($socket, 86400);

        try {
            $this->performHandshake($socket);
            $this->readLoop($socket, $onEvent);
        } catch (\Throwable $e) {
            if ($onError) {
                $onError($e);
            }

            throw $e instanceof MozartWebSocketException
                ? $e
                : new MozartWebSocketException('Mozart WebSocket listener failed: '.$e->getMessage(), previous: $e);
        } finally {
            if (is_resource($socket)) {
                fclose($socket);
            }
        }
    }

    /**
     * @param  callable(string, mixed, array): void  $onEvent
     */
    private function readLoop($socket, callable $onEvent): void
    {
        $buffer = '';
        $assembling = false;

        while (true) {
            $frame = $this->readFrame($socket);

            switch ($frame['opcode']) {
                case FrameEncoder::OPCODE_PING:
                    fwrite($socket, FrameEncoder::encode($frame['payload'], FrameEncoder::OPCODE_PONG));
                    break;

                case FrameEncoder::OPCODE_PONG:
                    break;

                case FrameEncoder::OPCODE_CLOSE:
                    throw new MozartWebSocketException('Mozart WebSocket connection closed by server');
                case FrameEncoder::OPCODE_TEXT:
                    $buffer = $frame['payload'];
                    $assembling = !$frame['fin'];
                    if (!$assembling) {
                        $this->dispatch($buffer, $onEvent);
                        $buffer = '';
                    }
                    break;

                case FrameEncoder::OPCODE_CONTINUATION:
                    $buffer .= $frame['payload'];
                    $assembling = !$frame['fin'];
                    if (!$assembling) {
                        $this->dispatch($buffer, $onEvent);
                        $buffer = '';
                    }
                    break;

                default:
                    // Unknown/binary opcode — ignore, matches the ASE
                    // listener's tolerance for unexpected payload shapes.
            }
        }
    }

    /**
     * @param  callable(string, mixed, array): void  $onEvent
     */
    private function dispatch(string $message, callable $onEvent): void
    {
        if ($message === '') {
            return;
        }

        $decoded = json_decode($message, true);

        if (!is_array($decoded) || !array_key_exists('eventType', $decoded)) {
            return;
        }

        $onEvent((string) $decoded['eventType'], $decoded['eventData'] ?? null, $decoded);
    }

    private function performHandshake($socket): void
    {
        $key = base64_encode(random_bytes(16));

        $request = "GET {$this->path} HTTP/1.1\r\n".
            "Host: {$this->host}:{$this->port}\r\n".
            "Upgrade: websocket\r\n".
            "Connection: Upgrade\r\n".
            "Sec-WebSocket-Key: {$key}\r\n".
            "Sec-WebSocket-Version: 13\r\n".
            "\r\n";

        fwrite($socket, $request);

        $response = '';
        while (!str_contains($response, "\r\n\r\n")) {
            if (feof($socket)) {
                throw new MozartWebSocketException('Mozart WebSocket handshake failed: connection closed');
            }

            $chunk = fread($socket, 1024);
            if ($chunk === false) {
                throw new MozartWebSocketException('Mozart WebSocket handshake failed: read error');
            }

            $response .= $chunk;
        }

        if (!str_contains($response, ' 101 ')) {
            throw new MozartWebSocketException("Mozart WebSocket handshake failed: unexpected response: {$response}");
        }

        $expectedAccept = base64_encode(sha1($key.self::HANDSHAKE_GUID, true));
        if (!str_contains($response, $expectedAccept)) {
            throw new MozartWebSocketException('Mozart WebSocket handshake failed: Sec-WebSocket-Accept mismatch');
        }
    }

    /**
     * @return array{fin: bool, opcode: int, payload: string}
     */
    private function readFrame($socket): array
    {
        $header = $this->readExactly($socket, 2);
        $byte1 = ord($header[0]);
        $byte2 = ord($header[1]);

        $fin = ($byte1 & 0x80) !== 0;
        $opcode = $byte1 & 0x0F;
        $masked = ($byte2 & 0x80) !== 0;
        $length = $byte2 & 0x7F;

        if ($length === 126) {
            $length = unpack('n', $this->readExactly($socket, 2))[1];
        } elseif ($length === 127) {
            $length = unpack('J', $this->readExactly($socket, 8))[1];
        }

        $maskKey = $masked ? $this->readExactly($socket, 4) : '';
        $payload = $length > 0 ? $this->readExactly($socket, $length) : '';

        if ($masked) {
            $unmasked = '';
            for ($i = 0; $i < strlen($payload); $i++) {
                $unmasked .= $payload[$i] ^ $maskKey[$i % 4];
            }
            $payload = $unmasked;
        }

        return ['fin' => $fin, 'opcode' => $opcode, 'payload' => $payload];
    }

    private function readExactly($socket, int $length): string
    {
        $data = '';

        while (strlen($data) < $length) {
            if (feof($socket)) {
                throw new MozartWebSocketException('Mozart WebSocket connection closed unexpectedly');
            }

            $chunk = fread($socket, $length - strlen($data));
            if ($chunk === false) {
                throw new MozartWebSocketException('Mozart WebSocket read error');
            }

            $data .= $chunk;
        }

        return $data;
    }
}
