<?php

namespace App\Models;

use App\Domain\Device\DiscoveredDevice;
use Illuminate\Database\Eloquent\Model;

/** A device a scan found that is not added yet (see App\Domain\Device\DiscoveryInbox). */
class DiscoveredDeviceRecord extends Model
{
    protected $table = 'discovered_devices';

    protected $fillable = [
        'ip_address', 'device_name', 'device_brand_name', 'device_product_type',
        'device_driver', 'device_driver_name', 'meta', 'last_seen_at', 'ignored_at',
    ];

    protected function casts(): array
    {
        return [
            'meta' => 'array',
            'last_seen_at' => 'datetime',
            'ignored_at' => 'datetime',
        ];
    }

    public function toDiscoveredDevice(?string $name = null): DiscoveredDevice
    {
        return new DiscoveredDevice(
            $this->ip_address,
            $name !== null && trim($name) !== '' ? trim($name) : $this->device_name,
            $this->device_brand_name,
            $this->device_product_type,
            $this->device_driver,
            $this->device_driver_name,
            $this->meta ?? [],
        );
    }
}
