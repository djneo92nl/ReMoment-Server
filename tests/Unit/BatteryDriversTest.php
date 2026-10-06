<?php

namespace Tests\Unit;

use App\Domain\Device\BatteryStatus;
use App\Events\Device\BatteryUpdated;
use App\Integrations\Sonos\SonosBattery;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Remoment\MozartDriver\MusicPlayerDriver;
use Remoment\MozartDriver\Services\DeviceListener;
use Tests\TestCase;

class BatteryDriversTest extends TestCase
{
    private const SONOS_XML = '<ZPSupportInfo><LocalBatteryStatus><Data name="Health">GREEN</Data><Data name="Level">87</Data><Data name="Temperature">NORMAL</Data><Data name="PowerSource">%s</Data></LocalBatteryStatus></ZPSupportInfo>';

    public function test_sonos_battery_on_battery_and_on_the_charger(): void
    {
        $this->assertEquals(new BatteryStatus(87, false), SonosBattery::parse(sprintf(self::SONOS_XML, 'BATTERY')));
        $this->assertEquals(new BatteryStatus(87, true), SonosBattery::parse(sprintf(self::SONOS_XML, 'SONOS_CHARGING_RING')));
    }

    public function test_sonos_without_a_battery_reports_none(): void
    {
        $this->assertNull(SonosBattery::parse('<ZPSupportInfo></ZPSupportInfo>'));

        Http::fake(['*' => Http::response('Not found', 404)]);
        $this->assertNull(SonosBattery::fetch('10.0.0.5'));
    }

    public function test_sonos_battery_is_read_from_the_status_page(): void
    {
        Http::fake(['10.0.0.5:1400/status/batterystatus' => Http::response(sprintf(self::SONOS_XML, 'BATTERY'))]);

        $this->assertSame(87, SonosBattery::fetch('10.0.0.5')?->level);
    }

    public function test_mozart_battery_state_is_mapped(): void
    {
        $this->assertEquals(new BatteryStatus(64, false), MusicPlayerDriver::batteryFromMozart(['batteryLevel' => 64, 'isCharging' => false, 'state' => 'BatteryMedium']));
        $this->assertTrue(MusicPlayerDriver::batteryFromMozart(['batteryLevel' => 10, 'state' => 'Charging'])->charging);
        $this->assertNull(MusicPlayerDriver::batteryFromMozart(['state' => 'BatteryNotPresent']));
        $this->assertNull(MusicPlayerDriver::batteryFromMozart(null));
    }

    public function test_mozart_listener_dispatches_battery_updated(): void
    {
        Event::fake();

        $method = new \ReflectionMethod(DeviceListener::class, 'applyEvent');
        $method->invoke(new DeviceListener('127.0.0.1'), 'WebSocketEventBattery', ['batteryLevel' => 55, 'isCharging' => true, 'state' => 'Charging'], '1');

        Event::assertDispatched(BatteryUpdated::class, fn (BatteryUpdated $e) => $e->deviceId === '1' && $e->battery->level === 55 && $e->battery->charging);
    }
}
