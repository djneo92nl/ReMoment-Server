<?php

namespace Tests\Feature;

use App\Livewire\DeviceCard;
use App\Models\Device;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * A Livewire component has one root element. A <style> (or anything else) ahead of the card's <div> became the
 * root of the first card on the page, and of every card on a refresh, so a refresh morphed the card away.
 */
class DeviceCardRootTest extends TestCase
{
    use RefreshDatabase;

    public function test_every_card_on_the_grid_has_a_div_as_its_root(): void
    {
        Device::factory()->count(3)->create();

        $html = $this->get('/devices')->assertOk()->getContent();

        preg_match_all('/<(\w+)[^>]*\bwire:id=/', $html, $matches);

        $this->assertSame(['div', 'div', 'div'], array_slice($matches[1], -3));
    }

    public function test_a_refreshed_card_keeps_a_div_root_and_its_content(): void
    {
        $device = Device::factory()->create(['device_name' => 'Kitchen']);

        $card = Livewire::test(DeviceCard::class, ['device' => $device])->call('$refresh');

        $this->assertMatchesRegularExpression('/^\s*<div[^>]*\bwire:id=/', $card->html());
        $card->assertSee('Kitchen');
    }
}
