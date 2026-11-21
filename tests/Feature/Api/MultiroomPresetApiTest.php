<?php

namespace Tests\Feature\Api;

use App\Models\Device;
use App\Models\MultiroomPreset;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\FakeBareDriver;
use Tests\Support\FakePlayerDriver;
use Tests\TestCase;

/** /api/multiroom/presets (docs/api/multiroom-presets.md): open like multiroom itself, no admin token. */
class MultiroomPresetApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        FakePlayerDriver::reset();
    }

    protected function tearDown(): void
    {
        FakePlayerDriver::reset();   // the fake's static state must not leak into the next test
        parent::tearDown();
    }

    private function devices(int $n = 3): array
    {
        return array_map(fn ($i) => Device::factory()->create(['device_name' => "Room {$i}"]), range(1, $n));
    }

    public function test_presets_are_open_without_any_token(): void
    {
        [$a, $b] = $this->devices(2);

        $this->postJson('/api/multiroom/presets', ['name' => 'Downstairs', 'device_ids' => [$a->id, $b->id]])->assertCreated();
        $this->getJson('/api/multiroom/presets')->assertOk();
    }

    public function test_create_and_list_keep_the_host_first(): void
    {
        [$a, $b, $c] = $this->devices();

        $this->postJson('/api/multiroom/presets', ['name' => ' Party ', 'device_ids' => [$c->id, $a->id, $b->id]])
            ->assertCreated()
            ->assertJsonPath('data.name', 'Party')
            ->assertJsonPath('data.device_ids', [$c->id, $a->id, $b->id])
            ->assertJsonPath('data.devices.0.name', 'Room 3');

        $this->getJson('/api/multiroom/presets')->assertOk()->assertJsonCount(1, 'data')->assertJsonStructure(['data' => [['id', 'name', 'device_ids', 'devices' => [['id', 'name']]]]]);
    }

    public function test_validation(): void
    {
        [$a] = $this->devices(1);

        $this->postJson('/api/multiroom/presets', ['name' => 'x', 'device_ids' => [$a->id]])->assertStatus(422)->assertJsonValidationErrors(['device_ids']);
        $this->postJson('/api/multiroom/presets', ['name' => 'x', 'device_ids' => [$a->id, 999]])->assertStatus(422)->assertJsonValidationErrors(['device_ids.1']);
        $this->postJson('/api/multiroom/presets', ['device_ids' => [$a->id, $a->id]])->assertStatus(422)->assertJsonValidationErrors(['name', 'device_ids.0']);
        $this->assertDatabaseCount('multiroom_presets', 0);
    }

    public function test_update_renames_and_regroups(): void
    {
        [$a, $b, $c] = $this->devices();
        $preset = MultiroomPreset::create(['name' => 'Old', 'device_ids' => [$a->id, $b->id]]);

        $this->putJson("/api/multiroom/presets/{$preset->id}", ['name' => 'New'])->assertOk()->assertJsonPath('data.name', 'New')->assertJsonPath('data.device_ids', [$a->id, $b->id]);
        $this->putJson("/api/multiroom/presets/{$preset->id}", ['device_ids' => [$b->id, $c->id]])->assertOk()->assertJsonPath('data.device_ids', [$b->id, $c->id]);
        $this->putJson('/api/multiroom/presets/999', ['name' => 'x'])->assertNotFound();
    }

    public function test_delete(): void
    {
        [$a, $b] = $this->devices(2);
        $preset = MultiroomPreset::create(['name' => 'Gone', 'device_ids' => [$a->id, $b->id]]);

        $this->deleteJson("/api/multiroom/presets/{$preset->id}")->assertOk()->assertJsonPath('status', 'ok');
        $this->assertDatabaseMissing('multiroom_presets', ['id' => $preset->id]);
    }

    public function test_activating_makes_the_others_join_the_first(): void
    {
        [$a, $b, $c] = $this->devices();
        $preset = MultiroomPreset::create(['name' => 'All', 'device_ids' => [$a->id, $b->id, $c->id]]);

        $this->postJson("/api/multiroom/presets/{$preset->id}/activate")
            ->assertOk()
            ->assertJsonPath('status', 'ok')
            ->assertJsonPath('host.id', $a->id)
            ->assertJsonCount(2, 'joined')
            ->assertJsonCount(0, 'failed');

        $this->assertSame([['joinSession', $a->id], ['joinSession', $a->id]], FakePlayerDriver::$calls);
    }

    public function test_a_device_without_multiroom_is_reported_and_the_rest_still_join(): void
    {
        [$a, $b] = $this->devices(2);
        $plain = Device::factory()->driver(FakeBareDriver::class)->create(['device_name' => 'Plain']);
        $preset = MultiroomPreset::create(['name' => 'Mixed', 'device_ids' => [$a->id, $plain->id, $b->id]]);

        $this->postJson("/api/multiroom/presets/{$preset->id}/activate")
            ->assertOk()
            ->assertJsonPath('status', 'partial')
            ->assertJsonPath('joined.0.id', $b->id)
            ->assertJsonPath('failed.0.id', $plain->id);
    }

    public function test_nobody_joining_is_a_driver_error_and_too_few_devices_is_invalid(): void
    {
        [$a, $b] = $this->devices(2);
        $preset = MultiroomPreset::create(['name' => 'Down', 'device_ids' => [$a->id, $b->id]]);

        FakePlayerDriver::$throw = new \RuntimeException('no answer');
        $this->postJson("/api/multiroom/presets/{$preset->id}/activate")->assertStatus(502)->assertJsonPath('error', 'driver_error')->assertJsonPath('failed.0.message', 'no answer');

        $b->delete();
        $this->postJson("/api/multiroom/presets/{$preset->id}/activate")->assertStatus(422)->assertJsonPath('error', 'invalid');
    }
}
