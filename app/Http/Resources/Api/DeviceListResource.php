<?php

namespace App\Http\Resources\Api;

use App\Domain\Device\SpotifyRouting;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class DeviceListResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'device_name' => $this->device_name,
            'device_brand_name' => $this->device_brand_name,
            'device_product_type' => $this->device_product_type,
            'device_driver_name' => $this->device_driver_name,
            'ip_address' => $this->ip_address,
            'state' => $this->state?->value,
            'last_seen' => $this->last_seen,
            'capabilities' => SpotifyRouting::capabilities($this->resource),
            'mqtt_topic' => "remoment/player/{$this->id}",
        ];
    }
}
