<?php

namespace Tests\Feature;

use App\Domain\Device\DeviceModels;
use App\Livewire\DeviceDriverManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DeviceModelsTest extends TestCase
{
    use RefreshDatabase;

    public function test_only_brands_are_returned_not_the_other_settings_in_the_file(): void
    {
        $brands = array_keys(DeviceModels::all());

        $this->assertContains('Bang & Olufsen', $brands);
        $this->assertContains('Sonos', $brands);
        $this->assertNotContains('discoverers', $brands);
        $this->assertNotContains('listeners', $brands);
        $this->assertNotContains('multiroom_meta_keys', $brands);
    }

    public function test_models_are_found_by_brand_and_model_or_by_product_name_alone(): void
    {
        $this->assertSame('ASE', DeviceModels::find('Bang & Olufsen', 'Beoplay M5')['driver_name']);
        $this->assertNull(DeviceModels::find('Bang & Olufsen', 'Toaster'));
        $this->assertSame('Mozart', DeviceModels::findByProduct('  beosound theatre ')['driver_name']);
        $this->assertNull(DeviceModels::findByProduct(''));
    }

    public function test_the_driver_manager_groups_every_model_by_its_driver(): void
    {
        $groups = (new DeviceDriverManager)->driverGroups();

        $this->assertEqualsCanonicalizing(['ASE', 'Mozart', 'Spotify', 'Sonos'], array_keys($groups));
        $this->assertTrue($groups['Spotify']['virtual']);
        $this->assertTrue($groups['Sonos']['discoverable']);
    }

    public function test_the_add_and_edit_device_pages_render(): void
    {
        $this->actingAs(\App\Models\User::factory()->create());

        $this->get('/devices/create')->assertOk()->assertSee('Beoplay M5');
    }
}
