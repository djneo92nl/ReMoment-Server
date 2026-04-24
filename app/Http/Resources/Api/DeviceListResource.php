<?php

namespace App\Http\Resources\Api;

use App\Integrations\Contracts\MediaControlsInterface;
use App\Integrations\Contracts\MultiRoomInterface;
use App\Integrations\Contracts\RadioControlInterface;
use App\Integrations\Contracts\SourceActivationInterface;
use App\Integrations\Contracts\SourcesInterface;
use App\Integrations\Contracts\VolumeControlInterface;
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
            'capabilities' => $this->resolveCapabilities(),
            'mqtt_topic' => "remoment/player/{$this->id}",
        ];
    }

    private function resolveCapabilities(): array
    {
        $capabilities = [];

        try {
            $driver = $this->driver;
            if ($driver instanceof MediaControlsInterface) {
                $capabilities[] = 'media_controls';
            }
            if ($driver instanceof VolumeControlInterface) {
                $capabilities[] = 'volume_control';
            }
            if ($driver instanceof RadioControlInterface) {
                $capabilities[] = 'radio_control';
            }
            if ($driver instanceof SourcesInterface) {
                $capabilities[] = 'source_control';
            }
            if ($driver instanceof SourceActivationInterface) {
                $capabilities[] = 'source_activation';
            }
            if ($driver instanceof MultiRoomInterface) {
                $capabilities[] = 'multi_room';
            }
        } catch (\Exception) {
            // Driver not loadable — return empty capabilities
        }

        return $capabilities;
    }
}
