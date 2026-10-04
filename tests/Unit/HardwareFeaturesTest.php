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
        $this->assertTrue(HardwareFeatures::wisa($this->device('BeoSound Moment')));
        $this->assertTrue(HardwareFeatures::wisa($this->device('beosound moment')));

        foreach (['BeoSound Essence', 'Beoplay M3', 'Beoplay M5', 'Beosound Balance', 'Sonos Speaker', '', null] as $product) {
            $this->assertFalse(HardwareFeatures::wisa($this->device($product)), (string) $product);
        }
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
