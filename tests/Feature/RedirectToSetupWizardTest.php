<?php

namespace Tests\Feature;

use App\Models\Device;
use App\Models\Setting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RedirectToSetupWizardTest extends TestCase
{
    use RefreshDatabase;

    private function makeDevice(): Device
    {
        return Device::create([
            'ip_address' => '10.0.0.1',
            'device_name' => 'Test Speaker',
            'device_brand_name' => 'Test',
            'device_product_type' => 'Speaker',
            'device_driver' => \App\Integrations\Spotify\MusicPlayerDriver::class,
            'device_driver_name' => 'Fake',
        ]);
    }

    public function test_guest_requests_are_never_redirected(): void
    {
        $response = $this->get('/devices');

        $response->assertOk();
    }

    public function test_authenticated_request_is_redirected_to_setup_when_no_devices_exist(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->get('/devices');

        $response->assertRedirect(route('setup.index'));
    }

    public function test_authenticated_request_is_not_redirected_once_a_device_exists(): void
    {
        $user = User::factory()->create();
        $this->makeDevice();

        $response = $this->actingAs($user)->get('/devices');

        $response->assertOk();
    }

    public function test_authenticated_request_is_not_redirected_once_wizard_is_dismissed(): void
    {
        $user = User::factory()->create();
        Setting::set('setup_wizard_dismissed', '1');

        $response = $this->actingAs($user)->get('/devices');

        $response->assertOk();
    }

    public function test_the_setup_page_itself_is_never_redirected(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->get('/setup');

        $response->assertOk();
    }

    public function test_livewire_ajax_requests_are_not_redirected(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)
            ->withHeaders(['X-Livewire' => 'true'])
            ->get('/devices');

        $response->assertOk();
    }
}
