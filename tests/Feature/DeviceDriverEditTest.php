<?php

namespace Tests\Feature;

use App\Models\Device;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DeviceDriverEditTest extends TestCase
{
    use RefreshDatabase;

    public function test_edit_form_keeps_the_backslashes_of_the_driver_class(): void
    {
        $device = Device::factory()->create(['device_driver' => \App\Integrations\Philips\Streamium\MusicPlayerDriver::class]);

        $html = $this->actingAs(User::factory()->create())->get(route('devices.edit', $device))->getContent();

        $this->assertStringContainsString('App\\\\Integrations\\\\Philips\\\\Streamium\\\\MusicPlayerDriver', $html);
    }

    public function test_an_unknown_driver_class_is_refused(): void
    {
        $device = Device::factory()->create();

        $this->actingAs(User::factory()->create())->put(route('devices.update', $device), [
            'device_name' => 'X', 'ip_address' => '10.0.0.1', 'device_brand_name' => 'B', 'device_product_type' => 'P',
            'device_driver' => 'AppIntegrationsPhilipsStreamiumMusicPlayerDriver',
        ])->assertSessionHasErrors('device_driver');
    }
}
