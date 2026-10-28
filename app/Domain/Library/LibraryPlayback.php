<?php

namespace App\Domain\Library;

use App\Domain\Device\SpotifyRouting;
use App\Integrations\Contracts\LibraryPlaybackInterface;
use App\Integrations\Spotify\Services\SpotifyLibraryImporter;
use App\Models\Device;
use App\Models\DeviceMeta;
use App\Models\Media\Album;
use App\Models\Media\Artist;
use App\Models\Media\Metadata;
use App\Models\Media\Playlist;
use App\Models\Media\Track;
use App\Services\SpotifyTokenService;
use Illuminate\Database\Eloquent\Builder;
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
        return SpotifyUri::isTrack($id);
    }

    /** Whether $device can play a library track from DLNA or Spotify at all. */
    public static function canPlayAny(Device $device): bool
    {
        return self::canPlayDlna($device) || self::canPlaySpotify($device);
    }

    /**
     * Narrows a tracks query (`tracks` table) to those trackPlayable() says $device can play: a
     * DLNA url when it plays DLNA, a Spotify track URI (own id or kept metadata) when it plays Spotify.
     */
    public static function playableTracks(Builder $tracks, Device $device): Builder
    {
        $morph = (new Track)->getMorphClass();
        $metadata = fn ($m, string $key) => $m->selectRaw('1')->from('metadata')
            ->whereColumn('metadata.metadatable_id', 'tracks.id')
            ->where('metadata.metadatable_type', $morph)
            ->where('metadata.key', $key);

        return $tracks->where(function (Builder $q) use ($device, $metadata) {
            if (self::canPlayDlna($device)) {
                $q->orWhereExists(fn ($m) => $metadata($m, 'dlna_url')->where('metadata.value', '!=', ''));
            }
            if (self::canPlaySpotify($device)) {
                $q->orWhere('tracks.external_id', 'like', 'spotify:track:%')
                    ->orWhereExists(fn ($m) => $metadata($m, 'external_id')->where('metadata.source', 'spotify')->where('metadata.value', 'like', 'spotify:track:%'));
            }
        });
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

    /**
     * The library a playlist belongs to (`spotify` for a synced Spotify playlist), null for local and
     * smart playlists, which have none of their own and take what their tracks offer.
     */
    public static function playlistLibrary(Playlist $playlist): ?string
    {
        return LibrarySources::libraryOfSource($playlist->source)
            ?? (SpotifyUri::isPlaylist($playlist->external_id) ? 'spotify' : null);
    }

    /**
     * Whether $track of $playlist is playable. A playlist of a library plays only through that
     * library (playPlaylist), so its tracks count only when they exist there and, with a $device,
     * the device can play that library; other playlists are judged per track (trackPlayable).
     */
    public static function playlistTrackPlayable(Playlist $playlist, Track $track, ?Device $device = null): bool
    {
        $library = self::playlistLibrary($playlist);
        if ($library === null) {
            return self::trackPlayable($track, $device);
        }

        return in_array($library, LibrarySources::ofTracks([$track]), true)
            && ($device === null || LibrarySources::playableOn($library, $device));
    }

    /** Metadata the album order reads, next to what playback needs. */
    private const ORDER_METADATA = ['disc_number', 'track_number'];

    /** Tracks in album order: disc, then track number (tracks without a position last, in the order they were added). */
    public static function albumTracks(Album $album, array $hiddenSources = []): Collection
    {
        return self::inAlbumOrder(LibrarySources::tracks($album->tracks(), $hiddenSources)
            ->with(['metadata' => fn ($q) => $q->whereIn('key', [...self::PLAYBACK_METADATA, ...self::ORDER_METADATA])])
            ->get());
    }

    /** Tracks grouped by album, in album order within each. */
    public static function artistTracks(Artist $artist): Collection
    {
        return self::inAlbumOrder($artist->tracks()
            ->with(['metadata' => fn ($q) => $q->whereIn('key', [...self::PLAYBACK_METADATA, ...self::ORDER_METADATA])])
            ->get(), byAlbum: true);
    }

    /** @param  Collection<int, Track>  $tracks  with their metadata loaded */
    private static function inAlbumOrder(Collection $tracks, bool $byAlbum = false): Collection
    {
        return $tracks->sortBy(fn (Track $t) => [
            $byAlbum ? (int) $t->album_id : 0,
            (int) ($t->metaValue('disc_number') ?: 1),
            (int) ($t->metaValue('track_number') ?: 9999),
            $t->id,
        ])->values();
    }

    /** @param  string|null  $source  `files` | `dlna` | `spotify`: play from that path only (see playTracks) */
    public function playAlbum(Device $device, Album $album, ?Track $start = null, bool $shuffle = false, ?string $source = null): PlaybackResult
    {
        if ($source === 'spotify') {
            return $this->playSpotifyAlbum($device, $album, $start, $shuffle);
        }

        return $this->playTracks($device, self::albumTracks($album), $start, $shuffle, $album, $source);
    }

    /**
     * Plays the album as Spotify has it (its album context), whatever of it the library holds: the Spotify side of
     * the album page. Needs a device that plays Spotify and an album Spotify knows.
     */
    private function playSpotifyAlbum(Device $device, Album $album, ?Track $start, bool $shuffle): PlaybackResult
    {
        if (!self::canPlaySpotify($device)) {
            throw new NotPlayableException("\"{$album->name}\" can't be played from Spotify on {$device->device_name}.");
        }

        try {
            $albumId = app(SpotifyLibraryImporter::class)->findAlbumId($album);
        } catch (\Throwable $e) {
            throw new PlaybackFailedException('Spotify did not answer: '.$e->getMessage(), previous: $e);
        }

        if ($albumId === null) {
            throw new NotPlayableException("\"{$album->name}\" isn't on Spotify.");
        }

        $this->playOnSpotify($device, collect(), $start ? self::spotifyUri($start) : null, $shuffle, context: 'spotify:album:'.$albumId);

        return new PlaybackResult(PlaybackResult::VIA_SPOTIFY, 1, 1);
    }

    /** @param  string|null  $source  `files` | `dlna` | `spotify`: play from that path only (see playTracks) */
    public function playArtist(Device $device, Artist $artist, bool $shuffle = false, ?string $source = null): PlaybackResult
    {
        return $this->playTracks($device, self::artistTracks($artist), null, $shuffle, null, $source);
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

    /** Plays a Spotify track by URI, without it being in the library (a "More on Spotify" row). */
    public function playSpotifyUri(Device $device, string $uri): PlaybackResult
    {
        if (!self::isSpotifyTrackUri($uri) || !self::canPlaySpotify($device)) {
            throw new NotPlayableException("That track can't be played on {$device->device_name}.");
        }

        $this->playOnSpotify($device, collect([$uri]));

        return new PlaybackResult(PlaybackResult::VIA_SPOTIFY, 1, 1);
    }

    /**
     * Plays an item of an external catalogue without it being in the library (a search or "More on Spotify" result):
     * Spotify as the album or artist context, or the track URI. Other providers join with workstream 5.
     */
    public function playExternal(Device $device, string $provider, string $type, string $id, bool $shuffle = false): PlaybackResult
    {
        if ($provider !== 'spotify' || !in_array($type, ['artist', 'album', 'track', 'playlist'], true) || !preg_match('/^[A-Za-z0-9]{10,40}$/', $id)) {
            throw new NotPlayableException("Nothing can be played from \"{$provider}\" like that.");
        }

        if (!self::canPlaySpotify($device)) {
            throw new NotPlayableException("That can't be played from Spotify on {$device->device_name}.");
        }

        if ($type === 'track') {
            return $this->playSpotifyUri($device, SpotifyUri::track($id));
        }

        $this->playOnSpotify($device, collect(), null, $shuffle, context: "spotify:{$type}:{$id}");

        return new PlaybackResult(PlaybackResult::VIA_SPOTIFY, 1, 1);
    }

    /** Tracks in playlist order, with what playback reads; $limit for a listing. */
    public static function playlistTracks(Playlist $playlist, ?int $limit = null, array $hiddenSources = []): Collection
    {
        return LibrarySources::tracks($playlist->tracks(), $hiddenSources)
            ->orderBy('playlist_track.id')
            ->with(['artist', 'metadata' => fn ($q) => $q->whereIn('key', self::PLAYBACK_METADATA)])
            ->when($limit !== null, fn ($q) => $q->limit($limit))
            ->get();
    }

    /**
     * Plays a playlist in the mode PlaylistMode::resolve picks for $library (the library the client
     * browsed in; invalid ones raise a ValidationException):
     *
     * - library mode: a Spotify playlist plays as its Spotify context (offset at $start, or a random
     *   track when shuffling); any other playlist as its tracks, like an album (DLNA, then Spotify
     *   track URIs). A playlist of a library plays only where the device can play that library.
     * - local mode: only the tracks with a playable Local copy are queued, in playlist order, the
     *   others skipped; needs a device that plays the Local library and at least one such track.
     *
     * Not playable: NotPlayableException (naming the library). Sets `last_played_at` once started.
     */
    public function playPlaylist(Device $device, Playlist $playlist, ?Track $start = null, bool $shuffle = false, ?string $library = null): PlaybackResult
    {
        $mode = PlaylistMode::resolve($playlist, $library);

        $result = $mode->isLocal()
            ? $this->playPlaylistLocally($device, $playlist, $start, $shuffle)
            : $this->playPlaylistOfLibrary($device, $playlist, $start, $shuffle);

        $playlist->forceFill(['last_played_at' => now()])->save();

        return $result;
    }

    private function playPlaylistOfLibrary(Device $device, Playlist $playlist, ?Track $start, bool $shuffle): PlaybackResult
    {
        $tracks = self::playlistTracks($playlist);
        $spotifyUri = SpotifyUri::isPlaylist($playlist->external_id) ? $playlist->external_id : null;

        // A playlist of a library plays through that library only, never as the merged local copies of its tracks.
        $library = self::playlistLibrary($playlist);
        if ($library !== null && !LibrarySources::playableOn($library, $device)) {
            throw new NotPlayableException("\"{$playlist->name}\" plays through the ".self::libraryName($library).' library, which this device can\'t play.');
        }

        if ($spotifyUri !== null && self::canPlaySpotify($device)) {
            $spotifyTracks = $tracks->filter(fn (Track $t) => self::spotifyUri($t) !== null)->values();
            $uris = self::order($spotifyTracks, $start, $shuffle)->map(fn (Track $t) => self::spotifyUri($t));
            $this->playOnSpotify($device, $uris, $start ? self::spotifyUri($start) : null, $shuffle, context: $spotifyUri);

            $result = new PlaybackResult(PlaybackResult::VIA_SPOTIFY, $spotifyTracks->count(), $tracks->count());
        } else {
            $result = $this->playTracks($device, $tracks, $start, $shuffle);
        }

        return new PlaybackResult($result->via, $result->playable, $result->total, PlaylistMode::LIBRARY);
    }

    /** Queues the playlist's tracks with a Local copy, in playlist order; the rest is skipped. */
    private function playPlaylistLocally(Device $device, Playlist $playlist, ?Track $start, bool $shuffle): PlaybackResult
    {
        $local = self::libraryName(PlaylistMode::LOCAL_LIBRARY);
        if (!LibrarySources::playableOn(PlaylistMode::LOCAL_LIBRARY, $device)) {
            throw new NotPlayableException("\"{$playlist->name}\" can only be played from the {$local} library here, which this device can't play.");
        }

        $tracks = self::playlistTracks($playlist);
        $queue = $tracks->filter(fn (Track $t) => $t->isOwnFile())->values();

        if ($queue->isEmpty()) {
            throw new NotPlayableException("None of the tracks of \"{$playlist->name}\" exist in the {$local} library.");
        }
        if ($start !== null && !$queue->contains('id', $start->id)) {
            throw new NotPlayableException("\"{$start->name}\" has no copy in the {$local} library.");
        }

        $ordered = self::order($queue, $start, $shuffle);
        $this->onDriver(fn () => $device->driver->playLibraryTracks($ordered));

        return new PlaybackResult(PlaybackResult::VIA_DLNA, $ordered->count(), $tracks->count(), PlaylistMode::LOCAL, $tracks->count() - $queue->count());
    }

    private static function libraryName(string $id): string
    {
        return LibrarySources::LIBRARIES[$id]['name'] ?? $id;
    }

    /**
     * @param  Collection<int, Track>  $tracks  in play order
     * @param  string|null  $source  `files` | `dlna` | `spotify` limits playback to that path and to the tracks it has
     *                               (a device that can't play it: NotPlayableException); null tries DLNA, then Spotify
     */
    public function playTracks(Device $device, Collection $tracks, ?Track $start = null, bool $shuffle = false, ?Album $album = null, ?string $source = null): PlaybackResult
    {
        if (in_array($source, ['files', 'dlna'], true)) {
            $tracks = $tracks->filter(fn (Track $t) => (bool) $t->getDlnaUrl())->values();
        } elseif ($source === 'spotify') {
            $tracks = $tracks->filter(fn (Track $t) => self::spotifyUri($t) !== null)->values();
        }

        if ($source !== 'spotify' && self::canPlayDlna($device)) {
            $ordered = self::order($tracks, $start, $shuffle);
            $playable = $ordered->filter(fn (Track $t) => (bool) $t->getDlnaUrl())->count();

            if ($playable > 0) {
                $this->onDriver(fn () => $device->driver->playLibraryTracks($ordered));

                return new PlaybackResult(PlaybackResult::VIA_DLNA, $playable, $tracks->count());
            }
        }

        $spotifyTracks = in_array($source, ['files', 'dlna'], true) ? collect() : $tracks->filter(fn (Track $t) => self::spotifyUri($t) !== null)->values();

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

    /**
     * Moves what Spotify plays to $device with Spotify's own transfer: the queue and position come along and
     * playback continues there. $device is the Spotify device itself or a speaker mapped to a Connect name.
     */
    public function transferSpotify(Device $device, bool $play = true): void
    {
        if (!self::canPlaySpotify($device) || SpotifyRouting::isSpotify($device)) {
            throw new NotPlayableException("Spotify can't be moved to {$device->device_name}: map it to a Spotify Connect name first.");
        }

        try {
            $api = $this->tokens->makeApiClient();
            $api->changeMyDevice(['device_ids' => [$this->connectDeviceId($api, $device)], 'play' => $play]);
        } catch (PlaybackFailedException $e) {
            throw $e;
        } catch (\Throwable $e) {
            throw new PlaybackFailedException('Spotify did not move playback: '.$e->getMessage(), previous: $e);
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

        if (!SpotifyUri::isAlbum($uri)) {
            return null;
        }

        Enrichment::save($album, 'spotify_album_uri', $uri, 'string', Enrichment::SPOTIFY);

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
