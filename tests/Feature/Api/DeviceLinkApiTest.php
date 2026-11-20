<?php

namespace Tests\Feature\Api;

use App\Domain\SubDevices\DeviceLinks;
use App\Models\Device;
use App\Models\DeviceLink;
use App\Models\DeviceMeta;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\RateLimiter;
use Tests\Support\FakeLinkedDriver;
use Tests\Support\FakeProductsDriver;
use Tests\TestCase;

/** The admin routes for sub device links (docs/api/sub-devices.md). */
class DeviceLinkApiTest extends TestCase
{
    use RefreshDatabase;

    private array $auth;

    protected function setUp(): void
    {
        parent::setUp();

        FakeProductsDriver::reset();
        RateLimiter::clear('admin-api-login|127.0.0.1');
        User::factory()->create();
        $this->auth = ['Authorization' => 'Bearer '.$this->postJson('/api/admin/login', ['password' => 'password'])->json('token')];
    }

    private function device(string $name, string $driver = FakeLinkedDriver::class): Device
    {
        return Device::factory()->create(['device_name' => $name, 'device_driver' => $driver]);
    }

    public function test_every_link_route_needs_the_admin_token(): void
    {
        $tv = $this->device('V1');
        $link = DeviceLinks::add($this->device('Chromecast'), $tv, 'input', 'hdmi_1');

        $this->getJson("/api/admin/devices/{$tv->id}/links")->assertUnauthorized();
        $this->getJson("/api/admin/devices/{$tv->id}/link-suggestions")->assertUnauthorized();
        $this->postJson('/api/admin/device-links', [])->assertUnauthorized();
        $this->putJson("/api/admin/device-links/{$link->id}", ['via' => 'x'])->assertUnauthorized();
        $this->deleteJson("/api/admin/device-links/{$link->id}")->assertUnauthorized();
    }

    public function test_a_link_is_added_listed_changed_and_removed(): void
    {
        $tv = $this->device('V1');
        $dongle = $this->device('Chromecast');

        $id = $this->postJson('/api/admin/device-links', [
            'device_id' => $dongle->id, 'host_device_id' => $tv->id, 'kind' => 'input', 'via' => 'hdmi_1',
        ], $this->auth)
            ->assertCreated()
            ->assertJsonPath('device_name', 'Chromecast')
            ->assertJsonPath('host_name', 'V1')
            ->assertJsonPath('via', 'hdmi_1')
            ->json('id');

        $this->getJson("/api/admin/devices/{$tv->id}/links", $this->auth)
            ->assertOk()
            ->assertJsonPath('sub_devices.0.id', $id)
            ->assertJsonPath('hosts', []);
        $this->getJson("/api/admin/devices/{$dongle->id}/links", $this->auth)
            ->assertJsonPath('hosts.0.host_name', 'V1')
            ->assertJsonPath('sub_devices', []);

        $this->putJson("/api/admin/device-links/{$id}", ['via' => 'hdmi_2'], $this->auth)
            ->assertOk()
            ->assertJsonPath('via', 'hdmi_2');
        $this->assertSame('hdmi_2', DeviceLink::find($id)->via);

        $this->deleteJson("/api/admin/device-links/{$id}", [], $this->auth)->assertOk()->assertJson(['status' => 'ok']);
        $this->assertSame(0, DeviceLink::count());
        $this->assertNotNull($dongle->fresh());
    }

    public function test_a_link_the_rules_refuse_is_a_422_with_the_reason(): void
    {
        $tv = $this->device('V1');
        $dongle = $this->device('Chromecast');
        $other = $this->device('Other');
        DeviceLinks::add($dongle, $tv, 'input', 'hdmi_1');

        $this->postJson('/api/admin/device-links', ['device_id' => $tv->id, 'host_device_id' => $other->id, 'kind' => 'input', 'via' => 'x'], $this->auth)
            ->assertStatus(422)
            ->assertJsonPath('error', 'invalid')
            ->assertJsonPath('message', 'That device is a host itself: sub devices go one level deep.');

        $this->postJson('/api/admin/device-links', ['device_id' => $dongle->id, 'host_device_id' => $tv->id, 'kind' => 'input', 'via' => 'hdmi_1'], $this->auth)
            ->assertStatus(422)
            ->assertJsonPath('error', 'invalid');

        $this->postJson('/api/admin/device-links', ['device_id' => $dongle->id, 'host_device_id' => $other->id, 'kind' => 'input'], $this->auth)
            ->assertStatus(422)
            ->assertJsonPath('error', 'invalid');

        $this->postJson('/api/admin/device-links', ['device_id' => $dongle->id, 'host_device_id' => 999, 'kind' => 'magic'], $this->auth)
            ->assertStatus(422)
            ->assertJsonValidationErrors(['host_device_id', 'kind']);
    }

    public function test_a_host_reports_the_devices_of_ours_it_knows(): void
    {
        $player = $this->device('Moment', FakeProductsDriver::class);
        $converter = $this->device('Converter');
        $stranger = $this->device('Stranger');
        DeviceMeta::create(['device_id' => $converter->id, 'key' => 'converter_jid', 'value' => '1790.1179011.24978398@products.bang-olufsen.com']);
        FakeProductsDriver::$products = ['products' => [
            ['1790.1179011.24978398@products.bang-olufsen.com', ['friendlyName' => 'BeoLink Converter', 'borrowSource' => true]],
            ['9999.1.1@products.bang-olufsen.com', ['friendlyName' => 'A product we do not have']],
            ['jid' => 'no-match@products.bang-olufsen.com'],
        ]];

        $this->getJson("/api/admin/devices/{$player->id}/link-suggestions", $this->auth)
            ->assertOk()
            ->assertExactJson(['suggestions' => [
                ['device_id' => $converter->id, 'device_name' => 'Converter', 'kind' => 'borrow', 'via' => '', 'borrowing' => true],
            ]]);
        $this->assertNotNull($stranger);

        // Once linked it is no longer suggested.
        DeviceLinks::add($converter, $player, 'borrow');
        $this->getJson("/api/admin/devices/{$player->id}/link-suggestions", $this->auth)->assertExactJson(['suggestions' => []]);
    }

    public function test_a_device_that_lists_no_products_has_no_suggestions_and_a_failing_one_is_a_502(): void
    {
        $plain = $this->device('Plain');
        $player = $this->device('Moment', FakeProductsDriver::class);

        $this->getJson("/api/admin/devices/{$plain->id}/link-suggestions", $this->auth)->assertOk()->assertExactJson(['suggestions' => []]);

        FakeProductsDriver::$productsThrow = new \RuntimeException('timeout');
        $this->getJson("/api/admin/devices/{$player->id}/link-suggestions", $this->auth)
            ->assertStatus(502)
            ->assertJsonPath('error', 'driver_error');
    }
}
