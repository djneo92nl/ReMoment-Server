<?php

namespace App\Integrations\Spotify;

use App\Domain\Device\DeviceCache;
use App\Domain\Device\QueueItem;
use App\Domain\Device\RepeatMode;
use App\Integrations\Contracts\LikeInterface;
use App\Integrations\Contracts\MediaControlsInterface;
use App\Integrations\Contracts\MusicPlayerDriverInterface;
use App\Integrations\Contracts\QueueInterface;
use App\Integrations\Contracts\RepeatInterface;
use App\Integrations\Contracts\SeekInterface;
use App\Integrations\Contracts\ShuffleInterface;
use App\Models\Device;
use App\Services\SpotifyTokenService;
use Illuminate\Support\Facades\Cache;

class MusicPlayerDriver implements LikeInterface, MediaControlsInterface, MusicPlayerDriverInterface, QueueInterface, RepeatInterface, SeekInterface, ShuffleInterface
{
    public function __construct(public Device $device) {}

    public function getCurrentPlayingAttribute(): array
    {
        return DeviceCache::getNowPlaying($this->device->id)?->toArray() ?? [];
    }

    public function play(): void
    {
        $this->api()->play();
    }

    public function pause(): void
    {
        $this->api()->pause();
    }

    public function next(): void
    {
        $this->api()->next();
    }

    public function previous(): void
    {
        $this->api()->previous();
    }

    public function stop(): void
    {
        $this->api()->pause();
    }

    public function seek(int $seconds): void
    {
        $this->api()->seek(['position_ms' => max(0, $seconds) * 1000]);
    }

    public function getUpNext(int $limit = 20): array
    {
        $queue = $this->api()->getMyQueue();
        $items = array_slice((array) (data_get($queue, 'queue') ?? []), 0, $limit);

        return array_map(fn ($item) => new QueueItem(
            name: data_get($item, 'name') ?? 'Unknown',
            // Tracks carry `artists`; podcast episodes carry `show` instead.
            artist: collect(data_get($item, 'artists') ?? [data_get($item, 'show')])
                ->map(fn ($artist) => data_get($artist, 'name'))->filter()->implode(', ') ?: null,
            album: data_get($item, 'album.name'),
            image: data_get($item, 'album.images.0.url') ?? data_get($item, 'images.0.url'),
            duration: ($ms = data_get($item, 'duration_ms')) ? intdiv((int) $ms, 1000) : null,
            uri: data_get($item, 'uri'),
        ), $items);
    }

    public function setShuffle(bool $shuffle): void
    {
        $this->api()->shuffle(['state' => $shuffle]);
    }

    public function setRepeat(RepeatMode $mode): void
    {
        $this->api()->repeat(['state' => self::spotifyRepeatState($mode)]);
    }

    /** Saves the playing track to (or removes it from) the user's Liked Songs. */
    public function setLiked(bool $liked): void
    {
        $api = $this->api();
        $trackId = data_get($api->getMyCurrentPlaybackInfo(), 'item.id');

        if (!$trackId) {
            throw new \RuntimeException('Nothing is playing on Spotify.');
        }

        $liked ? $api->addMyTracks(['ids' => [$trackId]]) : $api->deleteMyTracks([$trackId]);

        // So the listener reports the new value instead of its cached lookup.
        Cache::put(self::likedCacheKey($trackId), $liked, 3600);
    }

    public static function likedCacheKey(string $trackId): string
    {
        return "spotify:liked:{$trackId}";
    }

    /** Spotify names repeat-all "context" and repeat-one "track". */
    public static function spotifyRepeatState(RepeatMode $mode): string
    {
        return match ($mode) {
            RepeatMode::Off => 'off',
            RepeatMode::All => 'context',
            RepeatMode::One => 'track',
        };
    }

    public static function repeatModeFromSpotify(?string $state): ?RepeatMode
    {
        return match ($state) {
            'off' => RepeatMode::Off,
            'context' => RepeatMode::All,
            'track' => RepeatMode::One,
            default => null,
        };
    }

    private function api(): \SpotifyWebAPI\SpotifyWebAPI
    {
        return app(SpotifyTokenService::class)->makeApiClient();
    }
}
