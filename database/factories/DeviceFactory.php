<?php

namespace Database\Factories;

use App\Domain\Device\DeviceCache;
use App\Domain\Device\State;
use App\Models\Device;
use Illuminate\Database\Eloquent\Factories\Factory;
use Tests\Support\FakePlayerDriver;

/**
 * A device driven by the test fake (`Tests\Support\FakePlayerDriver`) unless told otherwise.
 *
 * @extends Factory<Device>
 */
class DeviceFactory extends Factory
{
    protected $model = Device::class;

    public function definition(): array
    {
        return [
            'ip_address' => '10.0.0.10',
            'device_name' => 'Living Room',
            'device_brand_name' => 'Test',
            'device_product_type' => 'Speaker',
            'device_driver' => FakePlayerDriver::class,
            'device_driver_name' => 'Fake',
        ];
    }

    /** Driven by this driver class (and the name it is listed under). */
    public function driver(string $class, string $name = 'Fake'): static
    {
        return $this->state(['device_driver' => $class, 'device_driver_name' => $name]);
    }

    /** Created in this state, as its listener would have cached it. */
    public function inState(State $state): static
    {
        return $this->afterCreating(fn (Device $device) => DeviceCache::updateState($device->id, $state));
    }
}
