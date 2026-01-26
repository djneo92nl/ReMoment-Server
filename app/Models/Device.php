<?php

namespace App\Models;

use App\Domain\Device\DeviceCache;
use App\Domain\Device\State;
use App\Integrations\Contracts\MusicPlayerDriverInterface;
use Illuminate\Database\Eloquent\Model;

/**
 * $params
 *
 * @property string ip_address
 */
class Device extends Model
{
    protected $driver = null;

    public $fillable = [
        'ip_address',
        'uuid',
        'device_brand_name',
        'device_product_type',
        'device_name',
        'device_driver',
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
            'device_driver' => $this->device_driver ?? '',
            'last_seen' => $this->last_seen ?? '',
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }

    private function loadDriver()
    {

        if (!class_exists($this->device_driver)) {
            throw new \Exception('Device Product Driver Not Found');
        }
        //        if (!$this->device_driver instanceof MusicPlayerDriverInterface) {
        //            throw new \LogicException('Invalid driver class');
        //        }

        $driver = app()->make(
            $this->device_driver,
            [
                'device' => $this,
            ]
        );

        $this->driver = $driver;
    }

    public function getDriverAttribute()
    {

        if ($this->driver === null) {
            $this->loadDriver();
        }

        return $this->driver;
    }

    public function getStateAttribute(): ?\App\Domain\Device\State
    {
        return DeviceCache::getState($this->id);
    }

    public function getCurrentPlayingAttribute()
    {
        if ($this->getStateAttribute() !== State::Unreachable) {
            $driver = $this->getDriverAttribute();

            return $driver->getCurrentPlayingAttribute();
        }

        return false;

    }
}
