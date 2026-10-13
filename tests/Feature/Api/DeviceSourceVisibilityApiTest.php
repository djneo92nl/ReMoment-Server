<?php

namespace Tests\Feature\Api;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\RateLimiter;
use Tests\Support\FakePlayerDriver;
use Tests\TestCase;

/** Admin endpoint that shows/hides and reorders a device's sources (docs/ux/sources.md). */
class DeviceSourceVisibilityApiTest extends TestCase
{
    use RefreshDatabase;

    private array $auth;

    protected function setUp(): void
    {
        parent::setUp();
        RateLimiter::clear('admin-api-login|127.0.0.1');
        User::factory()->create();
        $this->auth = ['Authorization' => 'Bearer '.$this->postJson('/api/admin/login', ['password' => 'password'])->json('token')];
    }

    private function deviceWithSource(): \App\Models\Device
    {
        $device = \App\Models\Device::factory()->create(['device_driver' => FakePlayerDriver::class]);
        $device->deviceSources()->create([
            'source_id' => 'MUSIC', 'friendly_name' => 'Music', 'source_type' => 'MUSIC', 'category' => 'music',
            'hidden' => false, 'sort_order' => 0,
        ]);

        return $device;
    }

    public function test_requires_the_admin_token(): void
    {
        $device = $this->deviceWithSource();

        $this->putJson("/api/admin/devices/{$device->id}/sources/MUSIC", ['hidden' => true])->assertUnauthorized();
    }

    public function test_hides_and_reorders_a_source(): void
    {
        $device = $this->deviceWithSource();

        $this->putJson("/api/admin/devices/{$device->id}/sources/MUSIC", ['hidden' => true, 'sort_order' => 4], $this->auth)
            ->assertOk()
            ->assertExactJson(['source_id' => 'MUSIC', 'hidden' => true, 'sort_order' => 4]);

        $this->assertDatabaseHas('device_sources', ['device_id' => $device->id, 'source_id' => 'MUSIC', 'hidden' => true, 'sort_order' => 4]);
    }

    public function test_unknown_source_is_404_and_bad_input_is_422(): void
    {
        $device = $this->deviceWithSource();

        $this->putJson("/api/admin/devices/{$device->id}/sources/NOPE", ['hidden' => true], $this->auth)->assertNotFound();
        $this->putJson("/api/admin/devices/{$device->id}/sources/MUSIC", ['hidden' => 'maybe'], $this->auth)->assertStatus(422);
    }
}
