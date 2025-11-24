<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class DeviceResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'ip_address' => $this->ip_address,
            'device_name' => $this->device_name,
            'device_brand_name' => $this->device_brand_name,
            'device_driver_name' => $this->device_driver_name,
            'device_product_type' => $this->device_product_type,
            'device_type' => $this->device_type,
            'last_seen' => $this->last_seen,
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
