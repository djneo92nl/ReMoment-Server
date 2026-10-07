<?php

namespace App\Integrations\Spotify\Services;

use App\Domain\Device\DeviceCache;
use App\Domain\Device\PlaybackModes;
use App\Domain\Device\SpotifyRouting;
use App\Domain\Device\State;
use App\Domain\Library\SpotifyUri;
use App\Domain\Media\AlbumData;
use App\Domain\Media\ArtistData;
use App\Domain\Media\NowPlaying;
use App\Domain\Media\TrackData;
use App\Events\Device\NowPlayingEnded;
use App\Events\Device\NowPlayingUpdated;
use App\Events\Device\PlaybackModesUpdated;
use App\Events\Device\ProgressUpdated;
use App\Integrations\Contracts\DeviceListenerInterface;
use App\Integrations\Spotify\MusicPlayerDriver;
use App\Models\Device;
use App\Models\DeviceMeta;
use App\Services\SpotifyTokenService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use SpotifyWebAPI\SpotifyWebAPI;
use SpotifyWebAPI\SpotifyWebAPIException;

class DeviceListener implements DeviceListenerInterface
{
    protected int $pollIntervalSeconds = 3;

    protected ?\Closure $onError = null;

    protected ?PlaybackModes $lastModes = null;

    /** Device the Spotify modes were last reported for (the routed speaker or the Spotify device). */
    protected ?int $modesDeviceId = null;

    protected ?string $lastNowPlayingKey = null;

    protected ?int $lastPositionSeconds = null;

    protected ?int $lastEffectiveDeviceId = null;

    public function __construct(protected SpotifyTokenService $tokenService) {}

    /** Only while an account is connected: without one there is nothing to poll. */
    public static function forDevice(Device $device): ?static
    {
        $tokens = app(SpotifyTokenService::class);

        return $tokens->isConnected() ? new static($tokens) : null;
    }

    public function onError(\Closure $callback): void
    {
        $this->onError = $callback;
    }

    public function listen(string $deviceId): void
    {
        $cacheKey = "listener_running_{$deviceId}";
        $retryDelaySeconds = 1;
        $maxRetryDelaySeconds = 30;

        $this->lastEffectiveDeviceId = (int) $deviceId;

        DeviceCache::updateState($deviceId, State::Unreachable);

        while (true) {
            cache()->put($cacheKey, true, now()->addSeconds(10));

            try {
                $api = $this->tokenService->makeApiClient();

                if ($this->tokenService->getAccessToken() === null) {
                    // No token yet — wait and retry
                    DeviceCache::updateState($deviceId, State::Unreachable);
                    sleep(30);

                    continue;
                }

                $this->poll((int) $deviceId, $api);

                $retryDelaySeconds = 1;
                sleep($this->pollIntervalSeconds);

            } catch (SpotifyWebAPIException $e) {
                if ($e->getCode() === 401) {
                    sleep(5);

                    continue;
                }

                $this->stopPlayback((int) $deviceId);
                Log::error("Spotify listener [{$deviceId}] API error: {$e->getMessage()}", ['exception' => $e]);
                if ($this->onError) {
                    ($this->onError)($e);
                }
                cache()->forget($cacheKey);
                DeviceCache::updateState($deviceId, State::Unreachable);
                $retryDelaySeconds = min($retryDelaySeconds * 2, $maxRetryDelaySeconds);
                sleep($retryDelaySeconds);

            } catch (\Throwable $e) {
                $this->stopPlayback((int) $deviceId);
                Log::error("Spotify listener [{$deviceId}] error: {$e->getMessage()}", ['exception' => $e]);
                if ($this->onError) {
                    ($this->onError)($e);
                }
                cache()->forget($cacheKey);
                DeviceCache::updateState($deviceId, State::Unreachable);
                $retryDelaySeconds = min($retryDelaySeconds * 2, $maxRetryDelaySeconds);
                sleep($retryDelaySeconds);
            }
        }
    }

    /**
     * One poll of `GET /v1/me/player` for the Spotify device $deviceId.
     * Playing on a mapped Spotify Connect speaker routes the playback and
     * the modes to that local device (see SpotifyRouting).
     */
    public function poll(int $deviceId, SpotifyWebAPI $api): void
    {
        $this->lastEffectiveDeviceId ??= $deviceId;

        $playback = $api->getMyCurrentPlaybackInfo(['additional_types' => 'track']);

        if (empty($playback)) {
            // 204 No Content — nothing playing
            $this->stopPlayback($deviceId);
            DeviceCache::updateState($deviceId, State::Standby);

            return;
        }

        $isPlaying = (bool) ($playback['is_playing'] ?? false);
        $item = $playback['item'] ?? null;

        // Route to a local device if this Spotify Connect speaker is mapped
        $localDeviceId = $item !== null ? $this->resolveLocalDevice($playback['device']['name'] ?? null) : null;

        if (!$isPlaying || $item === null) {
            $this->endNowPlaying();

            // Paused on a mapped speaker: stay routed, so play/next on it still
            // reach Spotify — unless the speaker now plays something by itself.
            if ($localDeviceId !== null && DeviceCache::getState($localDeviceId) !== State::Playing) {
                DeviceCache::setSpotifyRoutedDevice($localDeviceId);
            } else {
                $localDeviceId = null;
                DeviceCache::clearSpotifyRoutedDevice();
            }
            DeviceCache::updateState($deviceId, State::Standby);

            if ($item !== null) {
                $this->reportModes($localDeviceId ?? $deviceId, $deviceId, $playback, $api);
            } else {
                $this->moveModesTo($deviceId, $deviceId);
            }

            return;
        }

        $effectiveDeviceId = $localDeviceId ?? $deviceId;

        // Device changed — end playback on the previous effective device
        if ($effectiveDeviceId !== $this->lastEffectiveDeviceId) {
            $this->endNowPlaying();
        }
        $this->lastEffectiveDeviceId = $effectiveDeviceId;

        if ($localDeviceId !== null) {
            DeviceCache::setSpotifyRoutedDevice($localDeviceId);
            // Keep Spotify virtual device quiet
            DeviceCache::updateState($deviceId, State::Standby);
        } else {
            DeviceCache::clearSpotifyRoutedDevice();
        }

        $this->reportModes($effectiveDeviceId, $deviceId, $playback, $api);

        $nowPlaying = $this->buildNowPlaying($item, $playback);

        if ($nowPlaying !== null) {
            $key = $this->nowPlayingKey($nowPlaying);

            if ($key !== $this->lastNowPlayingKey) {
                event(new NowPlayingUpdated(
                    deviceId: (string) $effectiveDeviceId,
                    nowPlaying: $nowPlaying,
                    sourceType: 'spotify',
                ));
                $this->lastNowPlayingKey = $key;
            }

            $positionSeconds = (int) round(($playback['progress_ms'] ?? 0) / 1000);
            if (abs($positionSeconds - ($this->lastPositionSeconds ?? -999)) > 2) {
                event(new ProgressUpdated(deviceId: (string) $effectiveDeviceId, progress: $positionSeconds));
                $this->lastPositionSeconds = $positionSeconds;
            }
        }
    }

    protected function endNowPlaying(): void
    {
        if ($this->lastNowPlayingKey !== null) {
            event(new NowPlayingEnded(deviceId: (string) $this->lastEffectiveDeviceId));
            $this->lastNowPlayingKey = null;
            $this->lastPositionSeconds = null;
        }
    }

    /** Nothing plays (or Spotify can't be reached): end playback and routing. */
    protected function stopPlayback(int $deviceId): void
    {
        $this->endNowPlaying();
        DeviceCache::clearSpotifyRoutedDevice();
        $this->moveModesTo($deviceId, $deviceId);
    }

    /**
     * Shuffle/repeat come with every poll; whether the track is liked is
     * looked up once per track. Reported for $targetId: the local speaker
     * Spotify is routed to, or the Spotify device itself.
     */
    protected function reportModes(int $targetId, int $spotifyDeviceId, array $playback, SpotifyWebAPI $api): void
    {
        $this->moveModesTo($targetId, $spotifyDeviceId);

        $modes = $this->modesFromPlayback($playback, $this->likedFor($playback['item']['id'] ?? null, $api));

        if ($this->lastModes === null || !$this->lastModes->equals($modes)) {
            event(new PlaybackModesUpdated(
                deviceId: (string) $targetId,
                modes: $modes,
                routed: $targetId !== $spotifyDeviceId,
            ));
            $this->lastModes = $modes;
        }
    }

    /**
     * When Spotify's modes move to another device, the previous one gets its
     * own back: a local speaker the values its own driver last reported (or
     * none), the Spotify device none, since its playback is on a speaker now.
     */
    protected function moveModesTo(int $targetId, int $spotifyDeviceId): void
    {
        $previous = $this->modesDeviceId;
        $this->modesDeviceId = $targetId;

        if ($previous === null || $previous === $targetId) {
            return;
        }

        $this->lastModes = null;

        if ($previous === $spotifyDeviceId) {
            event(new PlaybackModesUpdated(deviceId: (string) $previous, modes: new PlaybackModes));
        } else {
            SpotifyRouting::restoreOwnModes($previous);
        }
    }

    public function modesFromPlayback(array $playback, ?bool $liked): PlaybackModes
    {
        return new PlaybackModes(
            shuffle: isset($playback['shuffle_state']) ? (bool) $playback['shuffle_state'] : null,
            repeat: MusicPlayerDriver::repeatModeFromSpotify($playback['repeat_state'] ?? null),
            liked: $liked,
        );
    }

    /** Cached per track; MusicPlayerDriver::setLiked() writes the same key. */
    protected function likedFor(?string $trackId, SpotifyWebAPI $api): ?bool
    {
        if (!$trackId) {
            return null;
        }

        $cached = Cache::get(MusicPlayerDriver::likedCacheKey($trackId));
        if (is_bool($cached)) {
            return $cached;
        }

        try {
            $liked = (bool) ($api->myTracksContains([$trackId])[0] ?? false);
        } catch (\Throwable) {
            return null;
        }

        Cache::put(MusicPlayerDriver::likedCacheKey($trackId), $liked, 3600);

        return $liked;
    }

    protected function buildNowPlaying(array $item, array $playback): ?NowPlaying
    {
        $trackName = trim((string) ($item['name'] ?? ''));
        if ($trackName === '') {
            return null;
        }

        $artistName = trim((string) ($item['artists'][0]['name'] ?? ''));
        $albumName = trim((string) ($item['album']['name'] ?? ''));
        $durationSeconds = (int) round(($item['duration_ms'] ?? 0) / 1000);
        $positionSeconds = (int) round(($playback['progress_ms'] ?? 0) / 1000);
        $spotifyId = SpotifyUri::track($item['id'] ?? '');

        // Pick largest artwork image
        $albumImages = $item['album']['images'] ?? [];
        usort($albumImages, fn ($a, $b) => ($b['width'] ?? 0) <=> ($a['width'] ?? 0));
        $artworkUrl = $albumImages[0]['url'] ?? null;
        $images = $artworkUrl !== null ? [$artworkUrl] : [];

        $releaseDate = $item['album']['release_date'] ?? null;
        $artist = $artistName !== '' ? new ArtistData(name: $artistName) : null;
        $album = $albumName !== '' ? new AlbumData(
            name: $albumName,
            images: $images,
            artist: $artist,
            released_at: $releaseDate,
        ) : null;

        $track = new TrackData(
            id: $spotifyId,
            name: $trackName,
            source: 'spotify',
            artist: $artist,
            duration: $durationSeconds,
            images: $images,
            meta: [['spotifyId' => $spotifyId]],
        );

        return new NowPlaying(
            track: $track,
            album: $album,
            position: $positionSeconds,
            type: 'music',
            platform: 'media',
        );
    }

    protected function nowPlayingKey(NowPlaying $nowPlaying): string
    {
        return md5(json_encode([
            'track' => $nowPlaying->track?->name,
            'artist' => $nowPlaying->track?->artist?->name,
            'album' => $nowPlaying->album?->name,
            'id' => $nowPlaying->track?->id,
        ]));
    }

    protected function resolveLocalDevice(?string $spotifyDeviceName): ?int
    {
        if ($spotifyDeviceName === null) {
            return null;
        }

        $meta = DeviceMeta::where('key', 'spotify_connect_name')
            ->where('value', $spotifyDeviceName)
            ->first();

        return $meta?->device_id;
    }
}
