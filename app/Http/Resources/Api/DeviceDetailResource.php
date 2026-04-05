<?php

namespace App\Http\Resources\Api;

use App\Domain\Artwork\ArtworkCache;
use App\Domain\Device\DeviceCache;
use App\Domain\Device\State;
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
            }
        }

        $data['now_playing'] = $nowPlaying;

        return $data;
    }
}
