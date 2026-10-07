<?php

namespace Tests\Feature;

use App\Livewire\DeviceSourceManager;
use App\Models\Device;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\Support\FakeSourceDriver;
use Tests\TestCase;

class DeviceSourceManagerTest extends TestCase
{
    use RefreshDatabase;

    private function device(): Device
    {
        return Device::factory()->create([
            'device_name' => 'Ray',
            'device_brand_name' => 'Sonos',
            'device_product_type' => 'Sonos Ray',
            'device_driver' => FakeSourceDriver::class,
        ]);
    }

    public function test_a_device_that_was_never_synced_loads_its_sources_on_first_view(): void
    {
        $device = $this->device();
        $this->assertSame(0, $device->deviceSources()->count());

        Livewire::test(DeviceSourceManager::class, ['device' => $device])->assertSee('TV');

        $this->assertSame(2, $device->deviceSources()->count());
        $this->assertTrue((bool) $device->deviceSources()->where('source_id', 'spotify')->value('hidden'), 'a shared source starts hidden');
    }

    public function test_stored_preferences_are_not_reset_by_a_view(): void
    {
        $device = $this->device();
        $device->deviceSources()->create(['source_id' => 'tv', 'friendly_name' => 'TV', 'source_type' => 'TV', 'category' => 'video', 'in_use' => false, 'borrowed' => false, 'hidden' => true]);

        Livewire::test(DeviceSourceManager::class, ['device' => $device]);

        $this->assertSame(1, $device->deviceSources()->count());
    }

    public function test_a_device_without_sources_shows_no_card(): void
    {
        $device = $this->device();
        $device->update(['device_driver' => \Tests\Support\FakeBareDriver::class]);

        Livewire::test(DeviceSourceManager::class, ['device' => $device])->assertDontSee('Sources');
    }
}
