<?php

namespace App\Http\Resources\Api;

use App\Domain\Artwork\ArtworkCache;
use App\Domain\Device\DeviceCache;
use App\Domain\Device\State;
use App\Models\Media\Track;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class DeviceDetailResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $data = (new DeviceListResource($this->resource))->toArray($request);

        $nowPlaying = null;
        if ($this->state !== State::Unreachable) {
            $cachedNowPlaying = DeviceCache::getNowPlaying($this->id);
            $nowPlaying = $cachedNowPlaying?->toArray();

            if ($nowPlaying !== null && $cachedNowPlaying !== null) {
                $url = ArtworkCache::extractImageUrl($cachedNowPlaying);
                $artwork = $url ? ArtworkCache::get($url) : null;
                if ($artwork !== null) {
                    $nowPlaying['artwork'] = $artwork;
                }

                $track = $this->resolveTrack($cachedNowPlaying);
                if ($track) {
                    $plain = $track->lyricsPlain();
                    $synced = $track->lyricsSynced();
                    if ($plain !== null || $synced !== null) {
                        $nowPlaying['lyrics'] = ['plain' => $plain, 'synced' => $synced];
                    }
                }
            }
        }

        $data['now_playing'] = $nowPlaying;

        return $data;
    }

    private function resolveTrack(\App\Domain\Media\NowPlaying $nowPlaying): ?Track
    {
        $externalId = $nowPlaying->track?->id;
        $name = $nowPlaying->track?->name;
        $artist = $nowPlaying->track?->artist?->name;

        $withLyrics = fn ($q) => $q->whereIn('key', ['lyrics_plain', 'lyrics_synced']);

        if ($externalId) {
            $track = Track::where('external_id', $externalId)->with(['metadata' => $withLyrics])->first();
            if ($track) {
                return $track;
            }
        }

        if ($name && $artist) {
            return Track::where('name', $name)
                ->whereHas('artist', fn ($q) => $q->where('name', $artist))
                ->whereHas('metadata', fn ($q) => $q->whereIn('key', ['lyrics_plain', 'lyrics_synced'])->where('value', '!=', ''))
                ->with(['metadata' => $withLyrics])
                ->first();
        }

        return null;
    }
}
