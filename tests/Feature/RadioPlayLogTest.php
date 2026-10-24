<?php

namespace Tests\Feature;

use App\Domain\Radio\RadioPlayLog;
use App\Models\RadioPlay;
use App\Models\RadioStation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RadioPlayLogTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_changed_icy_title_is_logged_once_and_a_show_title_never(): void
    {
        $station = RadioStation::create(['name' => 'Fip']);

        $this->assertNotNull(RadioPlayLog::record($station, 'icy', 'Artist - Song'));
        $this->assertNull(RadioPlayLog::record($station, 'icy', 'Artist - Song'));
        $this->assertNotNull(RadioPlayLog::record($station, 'icy', 'Other - Tune'));
        $this->assertNull(RadioPlayLog::record($station, 'nts', 'Some Show'));

        $this->assertSame(2, RadioPlay::count());
        $this->assertSame('Artist', RadioPlay::orderBy('id')->first()->artist);
    }

    public function test_old_plays_are_pruned(): void
    {
        $station = RadioStation::create(['name' => 'Fip']);
        RadioPlay::create(['radio_station_id' => $station->id, 'title' => 'Old', 'started_at' => now()->subDays(RadioPlayLog::KEEP_DAYS + 1)]);
        RadioPlay::create(['radio_station_id' => $station->id, 'title' => 'New', 'started_at' => now()]);

        $this->assertSame(1, RadioPlayLog::prune());
        $this->assertSame('New', RadioPlay::first()->title);
    }

    public function test_the_station_page_lists_recent_songs(): void
    {
        $station = RadioStation::create(['name' => 'Fip']);
        RadioPlayLog::record($station, 'icy', 'Artist - Song');

        $this->get(route('radio.show', $station))->assertOk()->assertSee('Song')->assertSee('Artist');
    }
}
