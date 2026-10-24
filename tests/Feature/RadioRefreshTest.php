<?php

namespace Tests\Feature;

use App\Models\RadioStation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class RadioRefreshTest extends TestCase
{
    use RefreshDatabase;

    private function station(string $name, string $url, ?string $uuid = null): RadioStation
    {
        $station = RadioStation::create(['name' => $name]);
        $station->setMeta('url', $url);
        $uuid && $station->setMeta('radio_browser_uuid', $uuid);

        return $station;
    }

    public function test_dead_streams_are_flagged_and_moved_ones_found_again(): void
    {
        Cache::flush();
        $ok = $this->station('Ok', 'https://s.test/ok');
        $dead = $this->station('Dead', 'https://s.test/dead');
        $moved = $this->station('Moved', 'https://s.test/old', 'u-1');

        Http::fake([
            'https://s.test/ok' => Http::response('audio'),
            'https://s.test/dead' => Http::response('', 404),
            'https://s.test/old' => Http::response('', 404),
            'https://s.test/new' => Http::response('audio'),
            '*/json/stations/byuuid/u-1' => Http::response([['stationuuid' => 'u-1', 'name' => 'Moved', 'url' => 'https://s.test/new', 'hls' => 0]]),
        ]);

        $this->artisan('radio:refresh')->expectsOutput('Streams: 1 ok, 1 moved, 1 dead.')->assertSuccessful();

        $this->assertSame('ok', $ok->fresh()->load('meta')->getMeta('stream_status'));
        $this->assertSame('dead', $dead->fresh()->load('meta')->getMeta('stream_status'));
        $this->assertSame('https://s.test/new', $moved->fresh()->load('meta')->streamUrl());
    }

    public function test_the_logo_finder_takes_the_directory_favicon_of_the_same_stream_host_and_falls_back_to_the_homepage(): void
    {
        Cache::flush();
        $a = $this->station('Alpha', 'https://alpha.test/live');
        $b = $this->station('Beta', 'https://beta.test/live');

        Http::fake([
            '*/json/stations/search*name=Alpha*' => Http::response([
                ['name' => 'Alpha NL', 'url' => 'https://other.test/x', 'favicon' => 'https://img.test/wrong.png', 'hls' => 0],
                ['name' => 'Alpha', 'url' => 'https://alpha.test/live', 'favicon' => 'https://img.test/alpha.png', 'hls' => 0],
            ]),
            '*/json/stations/search*name=Beta*' => Http::response([
                ['name' => 'Beta', 'url' => 'https://beta.test/live', 'favicon' => 'https://beta.test/favicon.svg', 'homepage' => 'https://beta.test/', 'hls' => 0],
            ]),
            'https://beta.test/' => Http::response('<html><head><link rel="apple-touch-icon" href="/icons/touch.png"></head></html>'),
        ]);

        $finder = app(\App\Domain\Radio\RadioLogoFinder::class);

        $this->assertSame('https://img.test/alpha.png', $finder->find($a->load('meta')));
        $this->assertSame('https://beta.test/icons/touch.png', $finder->find($b->load('meta')));
        $this->assertSame('https://upload.wikimedia.org/x/500px-a.png', \App\Domain\Radio\RadioLogoFinder::normalize('https://upload.wikimedia.org/x/1024px-a.png'));
        $this->assertFalse(\App\Domain\Radio\RadioLogoFinder::usable('https://x.test/logo.svg'));
        $this->assertSame('NR', RadioStation::make(['name' => 'NPO Radio 2'])->initials());
        $this->assertSame('E', RadioStation::make(['name' => 'NTS Expansions'])->initials());
    }
}
