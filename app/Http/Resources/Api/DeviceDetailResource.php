<?php

namespace App\Http\Resources\Api;

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
            $nowPlaying = DeviceCache::getNowPlaying($this->id)?->toArray();
        }

        $data['now_playing'] = $nowPlaying;

        return $data;
    }
}
