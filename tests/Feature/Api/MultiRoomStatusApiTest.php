<?php

namespace Tests\Feature\Api;

use App\Domain\Device\Cache\MultiRoom;
use App\Domain\Device\MultiRoomStatus;
use App\Events\Device\MultiRoomUpdated;
use App\Models\Device;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** Multiroom role: cache, `multiroom` in the API, MQTT `/multiroom`. */
class MultiRoomStatusApiTest extends TestCase
{
    use RefreshDatabase;

    private function makeDevice(string $name, string $jid): Device
    {
        $device = Device::factory()->create([
            'device_name' => $name,
        ]);
        $device->meta()->create(['key' => 'fake_id', 'value' => $jid]);

        return $device;
    }

    public function test_a_device_without_a_reported_role_has_none(): void
    {
        $device = $this->makeDevice('M3', 'm3');

        $this->getJson("/api/devices/{$device->id}")->assertOk()->assertJsonPath('data.multiroom', null);
    }

    public function test_the_event_caches_and_publishes_a_host_with_its_listeners(): void
    {
        $m3 = $this->makeDevice('Beoplay M3', 'm3');
        $m5 = $this->makeDevice('Beoplayer M5', 'm5');

        event(new MultiRoomUpdated((string) $m3->id, MultiRoomStatus::hosting(['m5'])));

        $this->assertSame('host', MultiRoom::get($m3->id)?->role);
        $this->getJson("/api/devices/{$m3->id}")->assertJsonPath('data.multiroom', [
            'role' => 'host',
            'host' => null,
            'listeners' => [['id' => $m5->id, 'name' => 'Beoplayer M5']],
        ]);

        $published = array_values(array_filter($this->mqtt->published, fn ($p) => $p['topic'] === "remoment/player/{$m3->id}/multiroom"));
        $this->assertCount(1, $published);
        $this->assertSame('host', json_decode($published[0]['message'], true)['role']);
    }

    public function test_a_joined_device_names_its_host(): void
    {
        $m3 = $this->makeDevice('Beoplay M3', 'm3');
        $m5 = $this->makeDevice('Beoplayer M5', 'm5');

        event(new MultiRoomUpdated((string) $m5->id, MultiRoomStatus::joined('m3')));

        $this->getJson('/api/devices')->assertJsonFragment([
            'role' => 'listener',
            'host' => ['id' => $m3->id, 'name' => 'Beoplay M3'],
            'listeners' => [],
        ]);
    }

    public function test_an_unchanged_role_is_not_published_again(): void
    {
        $m3 = $this->makeDevice('Beoplay M3', 'm3');

        event(new MultiRoomUpdated((string) $m3->id, MultiRoomStatus::standalone()));
        event(new MultiRoomUpdated((string) $m3->id, MultiRoomStatus::standalone()));
        event(new MultiRoomUpdated((string) $m3->id, MultiRoomStatus::joined('x')));

        $published = array_filter($this->mqtt->published, fn ($p) => $p['topic'] === "remoment/player/{$m3->id}/multiroom");
        $this->assertCount(2, $published);
    }
}
