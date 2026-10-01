<?php

namespace App\Domain\Library;

use App\Domain\Device\SpotifyRouting;
use App\Integrations\Contracts\LibraryPlaybackInterface;
use App\Models\Device;
use App\Models\DeviceMeta;
use App\Models\Media\Album;
use App\Models\Media\Artist;
use App\Models\Media\Metadata;
use App\Models\Media\Playlist;
use App\Models\Media\Track;
use App\Services\SpotifyTokenService;
use Illuminate\Support\Collection;
use SpotifyWebAPI\SpotifyWebAPI;

/**
 * Plays library albums, artists, tracks and playlists on a device, shared
 * by the REST API and the web pages. Two paths, tried in order:
 *
 * 1. DLNA — the device's LibraryPlaybackInterface driver, for tracks with a
 *    `dlna_url` (DLNA library scans).
 * 2. Spotify — for tracks with a Spotify URI (`external_id`
 *    `spotify:track:…`: the Spotify library import and Spotify plays, or
 *    kept as `external_id` metadata on a merged track), on
 *    the Spotify device itself (the active Connect device) or on a local
 *    device mapped to a Spotify Connect name (`spotify_connect_name`),
 *    through the Spotify Web API.
 */
class LibraryPlayback
{
    /** Spotify's `uris` body is kept to this many tracks. */
    public const MAX_SPOTIFY_URIS = 100;

    /** Metadata keys playback reads: the DLNA stream and other sources' ids (a Spotify URI). */
    public const PLAYBACK_METADATA = ['dlna_url', 'external_id'];

    public function __construct(protected SpotifyTokenService $tokens) {}

    public static function canPlayDlna(Device $device): bool
    {
        return is_a((string) $device->device_driver, LibraryPlaybackInterface::class, true);
    }

    /** The Spotify device, or a device mapped to a Spotify Connect name, while Spotify is connected. */
    public static function canPlaySpotify(Device $device): bool
    {
        if (!SpotifyRouting::isSpotify($device) && self::connectName($device) === null) {
            return false;
        }

        return app(SpotifyTokenService::class)->isConnected();
    }

    /** Whether either path exists: the device's `library_playback` capability. */
    public static function availableFor(Device $device): bool
    {
        return self::canPlayDlna($device) || self::canPlaySpotify($device);
    }

    public static function connectName(Device $device): ?string
    {
        return DeviceMeta::where('device_id', $device->id)->where('key', 'spotify_connect_name')->value('value');
    }

    /**
     * The track's Spotify URI: its own `external_id`, or, for a track merged
     * from several sources (LibraryIdentity), Spotify's id kept as
     * `external_id` metadata.
     */
    public static function spotifyUri(Track $track): ?string
    {
        if (self::isSpotifyTrackUri($track->external_id)) {
            return $track->external_id;
        }

        $kept = $track->relationLoaded('metadata')
            ? $track->metadata->where('key', 'external_id')->where('source', 'spotify')->pluck('value')
            : $track->metadata()->where('key', 'external_id')->where('source', 'spotify')->pluck('value');

        return $kept->first(fn ($uri) => self::isSpotifyTrackUri($uri));
    }

    private static function isSpotifyTrackUri(?string $id): bool
    {
        return is_string($id) && str_starts_with($id, 'spotify:track:') && strlen($id) > strlen('spotify:track:');
    }

    /** Whether the server has a stream for the track (any device), or one $device can play. */
    public static function trackPlayable(Track $track, ?Device $device = null): bool
    {
        $dlna = (bool) $track->getDlnaUrl();
        $spotify = self::spotifyUri($track) !== null;

        if ($device === null) {
            return $dlna || $spotify;
        }

        return ($dlna && self::canPlayDlna($device)) || ($spotify && self::canPlaySpotify($device));
    }

    /** Tracks in best-effort album order (no track number column exists). */
    public static function albumTracks(Album $album): Collection
    {
        return $album->tracks()
            ->orderBy('id')
            ->with(['metadata' => fn ($q) => $q->whereIn('key', self::PLAYBACK_METADATA)])
            ->get();
    }

    /** Tracks grouped by album, best-effort order within. */
    public static function artistTracks(Artist $artist): Collection
    {
        return $artist->tracks()
            ->orderBy('album_id')->orderBy('id')
            ->with(['metadata' => fn ($q) => $q->whereIn('key', self::PLAYBACK_METADATA)])
            ->get();
    }

    public function playAlbum(Device $device, Album $album, ?Track $start = null, bool $shuffle = false): PlaybackResult
    {
        return $this->playTracks($device, self::albumTracks($album), $start, $shuffle, $album);
    }

    public function playArtist(Device $device, Artist $artist, bool $shuffle = false): PlaybackResult
    {
        return $this->playTracks($device, self::artistTracks($artist), null, $shuffle);
    }

    public function playTrack(Device $device, Track $track): PlaybackResult
    {
        if (self::canPlayDlna($device) && $track->getDlnaUrl()) {
            $this->onDriver(fn () => $device->driver->playLibraryTrack($track));

            return new PlaybackResult(PlaybackResult::VIA_DLNA, 1, 1);
        }

        if (($uri = self::spotifyUri($track)) && self::canPlaySpotify($device)) {
            $this->playOnSpotify($device, collect([$uri]));

            return new PlaybackResult(PlaybackResult::VIA_SPOTIFY, 1, 1);
        }

        throw new NotPlayableException("\"{$track->name}\" can't be played on {$device->device_name}.");
    }

    /** A Spotify playlist plays as its Spotify context; any other through the device's library driver. */
    public function playPlaylist(Device $device, Playlist $playlist): PlaybackResult
    {
        $spotifyUri = str_starts_with((string) $playlist->external_id, 'spotify:playlist:') ? $playlist->external_id : null;

        if ($spotifyUri !== null && self::canPlaySpotify($device)) {
            $this->playOnSpotify($device, collect(), context: $spotifyUri);

            return new PlaybackResult(PlaybackResult::VIA_SPOTIFY, 0, 0);
        }

        if (self::canPlayDlna($device)) {
            $this->onDriver(fn () => $device->driver->playLibraryPlaylist($playlist));

            return new PlaybackResult(PlaybackResult::VIA_DLNA, 0, 0);
        }

        throw new NotPlayableException("\"{$playlist->name}\" can't be played on {$device->device_name}.");
    }

    /**
     * @param  Collection<int, Track>  $tracks  in play order
     */
    public function playTracks(Device $device, Collection $tracks, ?Track $start = null, bool $shuffle = false, ?Album $album = null): PlaybackResult
    {
        if (self::canPlayDlna($device)) {
            $ordered = self::order($tracks, $start, $shuffle);
            $playable = $ordered->filter(fn (Track $t) => (bool) $t->getDlnaUrl())->count();

            if ($playable > 0) {
                $this->onDriver(fn () => $device->driver->playLibraryTracks($ordered));

                return new PlaybackResult(PlaybackResult::VIA_DLNA, $playable, $tracks->count());
            }
        }

        $spotifyTracks = $tracks->filter(fn (Track $t) => self::spotifyUri($t) !== null)->values();

        if ($spotifyTracks->isNotEmpty() && self::canPlaySpotify($device)) {
            $startUri = $start ? self::spotifyUri($start) : null;
            $this->playOnSpotify($device, self::order($spotifyTracks, $start, $shuffle)->map(fn (Track $t) => self::spotifyUri($t)), $startUri, $shuffle, $album);

            return new PlaybackResult(PlaybackResult::VIA_SPOTIFY, $spotifyTracks->count(), $tracks->count());
        }

        throw new NotPlayableException($tracks->isEmpty()
            ? 'There are no tracks to play.'
            : "None of these tracks can be played on {$device->device_name}.");
    }

    /** From $start onwards (the rest shuffled when asked), else all, shuffled when asked. */
    public static function order(Collection $tracks, ?Track $start, bool $shuffle): Collection
    {
        $index = $start ? $tracks->search(fn (Track $t) => $t->id === $start->id) : false;

        if ($index === false) {
            return ($shuffle ? $tracks->shuffle() : $tracks)->values();
        }

        $rest = $tracks->slice($index + 1);

        return collect([$tracks[$index]])->concat($shuffle ? $rest->shuffle() : $rest)->values();
    }

    /**
     * Starts Spotify playback: the album as context (full album, offset at
     * $startUri or a random track when shuffling) when its Spotify album URI
     * is known or can be looked up, else the given track URIs. Shuffle is
     * then set to $shuffle on the target device.
     *
     * @param  Collection<int, string>  $uris  in play order
     */
    protected function playOnSpotify(Device $device, Collection $uris, ?string $startUri = null, bool $shuffle = false, ?Album $album = null, ?string $context = null): void
    {
        try {
            $api = $this->tokens->makeApiClient();
            $connectId = SpotifyRouting::isSpotify($device) ? '' : $this->connectDeviceId($api, $device);

            $context ??= $album ? $this->spotifyAlbumUri($album, $uris, $api) : null;
            $played = false;

            if ($context !== null) {
                $options = ['context_uri' => $context];
                $offset = $startUri ?? ($shuffle && $uris->isNotEmpty() ? $uris->random() : null);
                if ($offset !== null) {
                    $options['offset'] = ['uri' => $offset];
                }

                try {
                    $api->play($connectId, $options);
                    $played = true;
                } catch (\Throwable $e) {
                    // E.g. the library track isn't in Spotify's album; play the URIs instead.
                    if ($uris->isEmpty()) {
                        throw $e;
                    }
                }
            }

            if (!$played) {
                $api->play($connectId, ['uris' => $uris->take(self::MAX_SPOTIFY_URIS)->values()->all()]);
            }

            try {
                $api->shuffle(array_filter(['state' => $shuffle, 'device_id' => $connectId ?: null], fn ($v) => $v !== null));
            } catch (\Throwable) {
                // Best effort: playback already started.
            }
        } catch (PlaybackFailedException $e) {
            throw $e;
        } catch (\Throwable $e) {
            throw new PlaybackFailedException('Spotify did not start playback: '.$e->getMessage(), previous: $e);
        }
    }

    /** The Spotify Connect device id of the speaker mapped to $device, from Spotify's device list. */
    protected function connectDeviceId(SpotifyWebAPI $api, Device $device): string
    {
        $name = self::connectName($device);

        foreach ((array) data_get($api->getMyDevices(), 'devices', []) as $connectDevice) {
            if (data_get($connectDevice, 'name') === $name && data_get($connectDevice, 'id')) {
                return (string) data_get($connectDevice, 'id');
            }
        }

        throw new PlaybackFailedException("Spotify doesn't see \"{$name}\" right now; wake the speaker or select it in Spotify once.");
    }

    /** The album's Spotify URI: stored as album metadata, else looked up from one of its tracks once. */
    protected function spotifyAlbumUri(Album $album, Collection $uris, SpotifyWebAPI $api): ?string
    {
        $stored = $album->metadata()->where('key', 'spotify_album_uri')->value('value');
        if ($stored) {
            return $stored;
        }

        if ($uris->isEmpty()) {
            return null;
        }

        try {
            $uri = data_get($api->getTrack($uris->first()), 'album.uri');
        } catch (\Throwable) {
            return null;
        }

        if (!is_string($uri) || !str_starts_with($uri, 'spotify:album:')) {
            return null;
        }

        Metadata::updateOrCreate(
            ['metadatable_type' => Album::class, 'metadatable_id' => $album->id, 'key' => 'spotify_album_uri'],
            ['value' => $uri, 'type' => 'string', 'source' => 'spotify'],
        );

        return $uri;
    }

    private function onDriver(\Closure $call): void
    {
        try {
            $call();
        } catch (\Throwable $e) {
            throw new PlaybackFailedException('The device did not respond: '.$e->getMessage(), previous: $e);
        }
    }
}
