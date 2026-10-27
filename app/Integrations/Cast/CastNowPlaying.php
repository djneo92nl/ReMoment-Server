<?php

namespace App\Integrations\Cast;

use App\Domain\Media\AlbumData;
use App\Domain\Media\ArtistData;
use App\Domain\Media\NowPlaying;
use App\Domain\Media\Source;
use App\Domain\Media\TrackData;

/**
 * What a Cast receiver (a Chromecast, or a TV with Chromecast built in) is showing, as a `NowPlaying`: the title,
 * artist, artwork and position of a casted media session when there is one, else just the app that is running.
 * A native app that publishes no media session (Jellyfin on Android TV) only gives its name.
 */
class CastNowPlaying
{
    /** Apps that are the receiver's idle screen, not something playing. */
    private const IDLE_APP_IDS = ['E8C28D3C'];

    public function __construct(private readonly CastConnection $connection) {}

    /** One connection: receiver status, then the media status of the running app. Null when nothing runs. */
    public function read(): ?NowPlaying
    {
        $this->connection->connect();

        try {
            $receiver = $this->connection->receiverStatus();
            $app = $receiver['applications'][0] ?? null;
            $media = $app === null || empty($app['transportId']) ? [] : $this->connection->mediaStatus($app['transportId']);
        } finally {
            $this->connection->close();
        }

        return self::fromStatus($receiver, $media);
    }

    /**
     * @param  array<string, mixed>  $receiver  `receiverStatus()`
     * @param  array<int, array<string, mixed>>  $media  `mediaStatus()`
     */
    public static function fromStatus(array $receiver, array $media): ?NowPlaying
    {
        $app = $receiver['applications'][0] ?? null;

        if ($app === null || ($app['isIdleScreen'] ?? false) || in_array($app['appId'] ?? '', self::IDLE_APP_IDS, true)) {
            return null;
        }

        $source = new Source(name: (string) ($app['displayName'] ?? 'Cast'), category: 'video', sourceType: 'CAST', connector: $app['appId'] ?? null);
        $session = $media[0] ?? null;
        $info = $session['media'] ?? null;

        if ($info === null) {
            return new NowPlaying(state: 'playing', type: 'video', platform: 'media', source: $source);
        }

        $meta = $info['metadata'] ?? [];
        $images = array_values(array_filter(array_map(fn ($image) => $image['url'] ?? null, $meta['images'] ?? [])));
        $title = $meta['title'] ?? null;
        $artist = $meta['artist'] ?? $meta['seriesTitle'] ?? $meta['subtitle'] ?? null;
        $duration = isset($info['duration']) ? (int) round($info['duration']) : null;

        return new NowPlaying(
            track: $title === null ? null : new TrackData(
                id: $info['contentId'] ?? null,
                name: $title,
                source: 'cast',
                artist: $artist === null ? null : new ArtistData(name: $artist),
                duration: $duration ?: null,
                images: $images,
            ),
            album: isset($meta['albumName']) ? new AlbumData(name: $meta['albumName'], images: $images) : null,
            state: match ($session['playerState'] ?? 'PLAYING') {
                'PAUSED' => 'paused',
                'IDLE' => 'paused',
                default => 'playing',
            },
            position: (int) round($session['currentTime'] ?? 0),
            type: ($info['streamType'] ?? null) === 'LIVE' ? 'live' : 'video',
            platform: 'media',
            source: $source,
        );
    }
}
