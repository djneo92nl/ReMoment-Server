<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * $params
 *
 * @property string ip_address
 */
class Device extends Model
{
    public $fillable = [
        'ip_address',
        'uuid',
        'device_brand_name',
        'device_product_type',
        'device_name',
        'device_type',
        'device_driver_name',
        'last_seen',
    ];

    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'ip_address' => $this->ip_address,
            'uuid' => $this->uuid,
            'device_name' => $this->device_name,
            'device_brand_name' => $this->device_brand_name ?? '',
            'device_driver_name' => $this->device_driver_name ?? '',
            'device_product_type' => $this->device_product_type ?? '',
            'device_type' => $this->device_type ?? '',
            'last_seen' => $this->last_seen ?? '',
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
