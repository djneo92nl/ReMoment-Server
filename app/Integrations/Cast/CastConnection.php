<?php

namespace App\Integrations\Cast;

use RuntimeException;

/**
 * A Google Cast v2 sender connection to one receiver (a Chromecast, or a TV with Chromecast built in): TLS on port
 * 8009, 4-byte big-endian length framing around CastMessage, a virtual connection to the platform receiver, and
 * request/response over JSON namespaces matched by requestId. The receiver's certificate is self-signed and not
 * verified (we only talk to an address the admin gave us on the LAN). Heartbeats are answered while reading;
 * a caller that keeps a connection open must call `heartbeat()` about every 5 s.
 */
class CastConnection
{
    public const NS_CONNECTION = 'urn:x-cast:com.google.cast.tp.connection';

    public const NS_HEARTBEAT = 'urn:x-cast:com.google.cast.tp.heartbeat';

    public const NS_RECEIVER = 'urn:x-cast:com.google.cast.receiver';

    public const NS_MEDIA = 'urn:x-cast:com.google.cast.media';

    public const PLATFORM = 'receiver-0';

    public const SENDER = 'sender-remoment';

    /** @var resource|null */
    private $socket = null;

    private int $requestId = 0;

    public function __construct(
        private readonly string $host,
        private readonly int $port = 8009,
        private readonly float $timeout = 5.0,
    ) {}

    public function connect(): void
    {
        $context = stream_context_create(['ssl' => ['verify_peer' => false, 'verify_peer_name' => false, 'allow_self_signed' => true]]);
        $socket = @stream_socket_client("tls://{$this->host}:{$this->port}", $errno, $error, $this->timeout, STREAM_CLIENT_CONNECT, $context);

        if (!$socket) {
            throw new RuntimeException("Cast receiver {$this->host}:{$this->port} is not reachable: {$error} ({$errno})");
        }

        $this->socket = $socket;
        stream_set_timeout($this->socket, (int) $this->timeout);
        $this->send(self::PLATFORM, self::NS_CONNECTION, ['type' => 'CONNECT']);
    }

    public function close(): void
    {
        if ($this->socket) {
            @$this->send(self::PLATFORM, self::NS_CONNECTION, ['type' => 'CLOSE']);
            fclose($this->socket);
            $this->socket = null;
        }
    }

    public function heartbeat(): void
    {
        $this->send(self::PLATFORM, self::NS_HEARTBEAT, ['type' => 'PING']);
    }

    /** Receiver status: the running app (appId, transportId, sessionId) and volume. */
    public function receiverStatus(): array
    {
        return $this->request(self::PLATFORM, self::NS_RECEIVER, ['type' => 'GET_STATUS'])['status'] ?? [];
    }

    /**
     * What a running app plays, from its media namespace: the `status` list of MEDIA_STATUS entries (playerState,
     * currentTime, media with metadata and duration). Empty when the app has no media session. Read-only: a virtual
     * connection to the app's transport and GET_STATUS.
     *
     * @return array<int, array<string, mixed>>
     */
    public function mediaStatus(string $transportId): array
    {
        $this->send($transportId, self::NS_CONNECTION, ['type' => 'CONNECT']);

        return $this->request($transportId, self::NS_MEDIA, ['type' => 'GET_STATUS'])['status'] ?? [];
    }

    public function send(string $destination, string $namespace, array $payload): void
    {
        $bytes = CastMessage::json(self::SENDER, $destination, $namespace, $payload)->encode();

        if (fwrite($this->socket(), pack('N', strlen($bytes)).$bytes) === false) {
            throw new RuntimeException('Could not write to the Cast receiver.');
        }
    }

    /** Send with a fresh requestId and wait for the reply carrying it; other traffic is skipped. */
    public function request(string $destination, string $namespace, array $payload): array
    {
        $id = ++$this->requestId;
        $this->send($destination, $namespace, $payload + ['requestId' => $id]);
        $deadline = microtime(true) + $this->timeout;

        while (microtime(true) < $deadline) {
            $message = $this->read();
            if ($message && ($message->data()['requestId'] ?? null) === $id) {
                return $message->data();
            }
        }

        throw new RuntimeException('The Cast receiver did not answer '.($payload['type'] ?? 'the request').'.');
    }

    /** The next message, or null on a read timeout. PINGs are answered and not returned. */
    public function read(): ?CastMessage
    {
        $header = $this->readBytes(4);
        if ($header === null) {
            return null;
        }

        $size = unpack('N', $header)[1];
        $bytes = $this->readBytes($size);
        if ($bytes === null) {
            return null;
        }

        $message = CastMessage::decode($bytes);

        if ($message->namespace === self::NS_HEARTBEAT) {
            if (($message->data()['type'] ?? null) === 'PING') {
                $this->send($message->sourceId, self::NS_HEARTBEAT, ['type' => 'PONG']);
            }

            return null;
        }

        return $message;
    }

    private function readBytes(int $length): ?string
    {
        $buffer = '';
        while (strlen($buffer) < $length) {
            $chunk = fread($this->socket(), $length - strlen($buffer));
            if ($chunk === false || $chunk === '') {
                if (feof($this->socket())) {
                    throw new RuntimeException('The Cast receiver closed the connection.');
                }

                return null;
            }
            $buffer .= $chunk;
        }

        return $buffer;
    }

    /** @return resource */
    private function socket()
    {
        return $this->socket ?? throw new RuntimeException('Not connected to a Cast receiver.');
    }
}
