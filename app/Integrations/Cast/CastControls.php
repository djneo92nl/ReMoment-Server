<?php

namespace App\Integrations\Cast;

use App\Integrations\Common\UnsupportedOperationException;

/**
 * Commands to the media session of the app a Cast receiver is running: one connection per command (connect, find the
 * running app and its media session, send the media-namespace command, close). Cast has no "play this" that a sender
 * that did not start the session may rely on, but the transport commands of an existing session are open to any sender
 * on the LAN. **Unverified on a real Chromecast**: built from the Cast v2 media namespace (PLAY, PAUSE, STOP, SEEK,
 * QUEUE_NEXT, QUEUE_PREV); an app that ignores a command answers with its status or not at all.
 */
final class CastControls
{
    public function __construct(private readonly CastConnection $connection) {}

    /** @param  array<string, mixed>  $extra  more fields of the command (SEEK: `currentTime`) */
    public function send(string $type, array $extra = []): void
    {
        $this->connection->connect();

        try {
            $receiver = $this->connection->receiverStatus();
            $transport = $receiver['applications'][0]['transportId'] ?? null;

            if ($transport === null) {
                throw new UnsupportedOperationException('Nothing is casting.');
            }

            $sessionId = $this->connection->mediaStatus($transport)[0]['mediaSessionId'] ?? null;

            if ($sessionId === null) {
                throw new UnsupportedOperationException('The app that is running has no media session to control.');
            }

            $this->connection->request($transport, CastConnection::NS_MEDIA, ['type' => $type, 'mediaSessionId' => $sessionId] + $extra);
        } finally {
            $this->connection->close();
        }
    }
}
