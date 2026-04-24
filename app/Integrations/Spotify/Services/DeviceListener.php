<?php

namespace App\Integrations\Spotify\Services;

use App\Domain\Device\DeviceCache;
use App\Domain\Device\State;
use App\Domain\Media\AlbumData;
use App\Domain\Media\ArtistData;
use App\Domain\Media\NowPlaying;
use App\Domain\Media\TrackData;
use App\Events\Device\NowPlayingEnded;
use App\Events\Device\NowPlayingUpdated;
use App\Events\Device\ProgressUpdated;
use App\Services\SpotifyTokenService;
use Illuminate\Support\Facades\Log;
use SpotifyWebAPI\SpotifyWebAPIException;

class DeviceListener
{
    protected int $pollIntervalSeconds = 3;

    protected ?\Closure $onError = null;

    public function __construct(protected SpotifyTokenService $tokenService) {}

    public function onError(\Closure $callback): void
    {
        $this->onError = $callback;
    }

    public function listen(string $deviceId): void
    {
        $cacheKey = "listener_running_{$deviceId}";
        $retryDelaySeconds = 1;
        $maxRetryDelaySeconds = 30;

        $lastNowPlayingKey = null;
        $lastPositionSeconds = null;

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

                $playback = $api->getMyCurrentPlaybackInfo(['additional_types' => 'track']);

                if ($playback === null || empty($playback)) {
                    // 204 No Content — nothing playing
                    if ($lastNowPlayingKey !== null) {
                        event(new NowPlayingEnded(deviceId: $deviceId));
                        $lastNowPlayingKey = null;
                        $lastPositionSeconds = null;
                    }
                    DeviceCache::updateState($deviceId, State::Standby);
                    $retryDelaySeconds = 1;
                    sleep($this->pollIntervalSeconds);

                    continue;
                }

                $isPlaying = (bool) ($playback['is_playing'] ?? false);
                $item = $playback['item'] ?? null;

                if (!$isPlaying || $item === null) {
                    if ($lastNowPlayingKey !== null) {
                        event(new NowPlayingEnded(deviceId: $deviceId));
                        $lastNowPlayingKey = null;
                        $lastPositionSeconds = null;
                    }
                    DeviceCache::updateState($deviceId, State::Standby);
                    $retryDelaySeconds = 1;
                    sleep($this->pollIntervalSeconds);

                    continue;
                }

                $nowPlaying = $this->buildNowPlaying($item, $playback);

                if ($nowPlaying !== null) {
                    $key = $this->nowPlayingKey($nowPlaying);

                    if ($key !== $lastNowPlayingKey) {
                        event(new NowPlayingUpdated(
                            deviceId: $deviceId,
                            nowPlaying: $nowPlaying,
                            sourceType: 'spotify',
                        ));
                        $lastNowPlayingKey = $key;
                    }

                    $positionSeconds = (int) round(($playback['progress_ms'] ?? 0) / 1000);
                    if (abs($positionSeconds - ($lastPositionSeconds ?? -999)) > 2) {
                        event(new ProgressUpdated(deviceId: $deviceId, progress: $positionSeconds));
                        $lastPositionSeconds = $positionSeconds;
                    }
                }

                $retryDelaySeconds = 1;
                sleep($this->pollIntervalSeconds);

            } catch (SpotifyWebAPIException $e) {
                if ($e->getCode() === 401) {
                    sleep(5);

                    continue;
                }

                Log::error("Spotify listener [{$deviceId}] API error: {$e->getMessage()}", ['exception' => $e]);
                if ($this->onError) {
                    ($this->onError)($e);
                }
                cache()->forget($cacheKey);
                DeviceCache::updateState($deviceId, State::Unreachable);
                $retryDelaySeconds = min($retryDelaySeconds * 2, $maxRetryDelaySeconds);
                sleep($retryDelaySeconds);

            } catch (\Throwable $e) {
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
        $spotifyId = 'spotify:track:'.($item['id'] ?? '');

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
}
