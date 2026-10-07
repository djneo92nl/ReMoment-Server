<?php

namespace Tests\Unit;

use App\Domain\Device\HardwareFeatures;
use App\Models\Device;
use Tests\TestCase;

class HardwareFeaturesTest extends TestCase
{
    private function device(?string $product): Device
    {
        return new Device(['device_product_type' => $product]);
    }

    public function test_only_the_moment_has_wisa(): void
    {
        $this->assertTrue(HardwareFeatures::allows($this->device('BeoSound Moment'), 'wireless_speakers'));
        $this->assertTrue(HardwareFeatures::allows($this->device('beosound moment'), 'wireless_speakers'));

        foreach (['BeoSound Essence', 'Beoplay M3', 'Beoplay M5', 'Beosound Balance', 'Sonos Speaker', '', null] as $product) {
            $this->assertFalse(HardwareFeatures::allows($this->device($product), 'wireless_speakers'), (string) $product);
        }
    }

    public function test_the_essence_and_the_moment_have_external_speakers_the_portables_do_not(): void
    {
        foreach (['BeoSound Essence', 'BeoSound Moment', 'beosound essence'] as $product) {
            $this->assertTrue(HardwareFeatures::allows($this->device($product), 'wired_speakers'), $product);
        }

        foreach (['Beoplay M3', 'Beoplay M5', 'Beosound Balance', 'Sonos Speaker', '', null] as $product) {
            $this->assertFalse(HardwareFeatures::allows($this->device($product), 'wired_speakers'), (string) $product);
        }
    }

    public function test_external_speakers_do_not_imply_wisa(): void
    {
        $essence = $this->device('BeoSound Essence');

        $this->assertTrue(HardwareFeatures::allows($essence, 'wired_speakers'));
        $this->assertFalse(HardwareFeatures::allows($essence, 'wireless_speakers'));
    }

    public function test_the_gate_only_applies_to_hardware_bound_capabilities(): void
    {
        $essence = $this->device('BeoSound Essence');

        $this->assertFalse(HardwareFeatures::allows($essence, 'wireless_speakers'));
        $this->assertTrue(HardwareFeatures::allows($essence, 'network_settings'));
        $this->assertTrue(HardwareFeatures::allows($essence, 'bluetooth'));
        $this->assertTrue(HardwareFeatures::allows($this->device('BeoSound Moment'), 'wireless_speakers'));
    }

    public function test_the_moment_is_listed_under_the_real_brand_so_it_can_be_picked(): void
    {
        $this->assertArrayHasKey('BeoSound Moment', config('devices')['Bang & Olufsen']);
        $this->assertArrayNotHasKey('Bang &amp; Olufsen', config('devices'));
    }
}
