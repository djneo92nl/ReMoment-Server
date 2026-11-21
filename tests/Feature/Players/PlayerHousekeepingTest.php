<?php

namespace Tests\Feature\Players;

use App\Domain\Device\DeviceCache;
use App\Integrations\ReMoment\PlayerDriver;
use App\Models\Device;
use App\Models\DeviceMeta;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Tests\Support\SignsInAsAdmin;
use Tests\TestCase;

/** Stale players do not pile up (docs/api/players.md, Housekeeping). */
class PlayerHousekeepingTest extends TestCase
{
    use RefreshDatabase;
    use SignsInAsAdmin;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
        config(['players.hide_after_days' => 30]);
    }

    private function register(array $body = []): array
    {
        return $this->postJson('/api/players/register', $body + ['name' => 'Remko\'s iPhone', 'kind' => 'ios'])->assertCreated()->json();
    }

    private function silence(int $deviceId, int $days): void
    {
        Device::whereKey($deviceId)->update(['last_seen' => now()->subDays($days)]);
        DeviceCache::forgetListener($deviceId);
    }

    public function test_a_player_has_last_seen_from_its_first_registration_and_its_reports(): void
    {
        $player = $this->register();
        $this->assertNotNull(Device::find($player['device_id'])->last_seen);

        Device::whereKey($player['device_id'])->update(['last_seen' => now()->subHour()]);
        $this->putJson("/api/players/{$player['player_token']}/state", ['state' => 'paused'])->assertOk();

        $this->assertTrue(Device::find($player['device_id'])->last_seen->gt(now()->subMinute()));
        $row = $this->getJson('/api/players')->assertOk()->json('data.0');
        $this->assertSame(['id', 'name', 'kind', 'state', 'last_seen', 'hidden'], array_keys($row));
        $this->assertNotNull($row['last_seen']);
        $this->assertFalse($row['hidden']);
    }

    public function test_stale_players_are_hidden_not_deleted_and_the_rest_is_left_alone(): void
    {
        $old = $this->register(['name' => 'Old iPhone']);
        $recent = $this->register(['name' => 'New iPhone']);
        $speaker = Device::factory()->create();
        $this->silence($old['device_id'], 45);
        $this->silence($recent['device_id'], 3);
        Device::whereKey($speaker->id)->update(['last_seen' => now()->subDays(400)]);

        Artisan::call('players:hide-stale');

        $this->assertTrue(Device::find($old['device_id'])->hidden);
        $this->assertFalse(Device::find($recent['device_id'])->hidden);
        $this->assertFalse($speaker->fresh()->hidden, 'only players are housekept');
        $this->assertSame(['New iPhone'], array_column($this->getJson('/api/players')->json('data'), 'name'));
        $this->assertCount(2, $this->getJson('/api/players?include_hidden=1')->json('data'));
        $ids = array_column($this->getJson('/api/devices')->json('data'), 'id');
        $this->assertNotContains($old['device_id'], $ids);
        $this->assertContains($speaker->id, $ids);
        $this->assertContains($old['device_id'], array_column($this->getJson('/api/devices?include_hidden=1')->json('data'), 'id'));
    }

    public function test_a_live_player_is_never_hidden_and_the_dry_run_changes_nothing(): void
    {
        $player = $this->register();
        Device::whereKey($player['device_id'])->update(['last_seen' => now()->subDays(90)]);

        Artisan::call('players:hide-stale', ['--dry-run' => true]);
        $this->assertFalse(Device::find($player['device_id'])->hidden);

        DeviceCache::markListenerAlive($player['device_id']);
        Artisan::call('players:hide-stale');
        $this->assertFalse(Device::find($player['device_id'])->hidden);
    }

    public function test_housekeeping_can_be_switched_off(): void
    {
        config(['players.hide_after_days' => 0]);
        $player = $this->register();
        $this->silence($player['device_id'], 400);

        Artisan::call('players:hide-stale');

        $this->assertFalse(Device::find($player['device_id'])->hidden);
    }

    public function test_a_hidden_player_returns_when_it_reports_but_an_admin_hidden_one_stays_hidden(): void
    {
        $auto = $this->register(['name' => 'Auto']);
        $manual = $this->register(['name' => 'Manual']);
        $this->silence($auto['device_id'], 60);
        Artisan::call('players:hide-stale');
        Device::whereKey($manual['device_id'])->update(['hidden' => true]);
        $this->assertTrue(Device::find($auto['device_id'])->hidden);

        $this->putJson("/api/players/{$auto['player_token']}/state", ['state' => 'paused'])->assertOk();
        $this->putJson("/api/players/{$manual['player_token']}/state", ['state' => 'paused'])->assertOk();

        $this->assertFalse(Device::find($auto['device_id'])->hidden);
        $this->assertDatabaseMissing('device_meta', ['device_id' => $auto['device_id'], 'key' => 'player_auto_hidden']);
        $this->assertTrue(Device::find($manual['device_id'])->hidden);
    }

    public function test_register_with_adopt_by_name_takes_over_the_offline_player(): void
    {
        $old = $this->register();
        $this->silence($old['device_id'], 50);
        Artisan::call('players:hide-stale');

        $new = $this->register(['adopt_by_name' => true, 'capabilities' => ['seek']]);

        $this->assertSame($old['device_id'], $new['device_id']);
        $this->assertNotSame($old['player_token'], $new['player_token']);
        $this->assertSame(1, Device::where('device_driver', PlayerDriver::class)->count());
        $this->assertFalse(Device::find($old['device_id'])->hidden, 'it reported, so it is back');
        $this->assertSame('["seek"]', DeviceMeta::where('device_id', $old['device_id'])->where('key', 'player_capabilities')->value('value'));
        $this->putJson("/api/players/{$new['player_token']}/state", ['state' => 'paused'])->assertOk();
    }

    public function test_adopt_by_name_is_opt_in_and_never_takes_a_live_or_different_player(): void
    {
        $first = $this->register();
        $this->silence($first['device_id'], 10);

        // Without the flag: a new player, as before.
        $second = $this->register();
        $this->assertNotSame($first['device_id'], $second['device_id']);

        // The second is live now; a third with the flag takes over the first (offline), never the live one.
        DeviceCache::markListenerAlive($second['device_id']);
        $third = $this->register(['adopt_by_name' => true]);
        $this->assertSame($first['device_id'], $third['device_id']);

        // Both live now: another registration with the flag makes a new one. A different name or kind never matches.
        DeviceCache::markListenerAlive($first['device_id']);
        $this->assertNotContains($this->register(['adopt_by_name' => true])['device_id'], [$first['device_id'], $second['device_id']]);
        $other = $this->register(['adopt_by_name' => true, 'name' => 'Remko\'s iPhone', 'kind' => 'web']);
        $this->assertNotContains($other['device_id'], [$first['device_id'], $second['device_id']]);
    }

    public function test_an_admin_can_delete_a_player_through_the_devices_endpoint(): void
    {
        $player = $this->register();
        $this->deleteJson("/api/admin/devices/{$player['device_id']}")->assertStatus(401);

        $this->deleteJson("/api/admin/devices/{$player['device_id']}", [], $this->adminHeaders())->assertOk();

        $this->assertDatabaseMissing('devices', ['id' => $player['device_id']]);
        $this->assertDatabaseMissing('device_meta', ['device_id' => $player['device_id']]);
        $this->putJson("/api/players/{$player['player_token']}/state", ['state' => 'paused'])->assertNotFound();
    }
}
