<?php

namespace Tests\Feature;

use App\Domain\Radio\NowPlayingProviders;
use App\Domain\Radio\StreamTitles;
use App\Integrations\Radio\Nts\NtsClient;
use App\Jobs\PollRadioTitle;
use App\Models\RadioStation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/** NTS Radio: the live show as a station's title, and the import of live channels and mixtapes. */
class NtsRadioTest extends TestCase
{
    use RefreshDatabase;

    private function fakeNts(): void
    {
        Cache::flush();
        Http::fake([
            '*/api/v2/live' => Http::response(['results' => [
                ['channel_name' => '1', 'now' => ['broadcast_title' => 'AURAL w/ ANGEL D&#039;LITE', 'start_timestamp' => '2026-10-24T19:00:00+01:00', 'end_timestamp' => '2026-10-24T20:00:00+01:00', 'embeds' => ['details' => ['genres' => [['id' => 'x', 'value' => 'Techno']], 'media' => ['picture_medium' => 'https://img.test/a.jpg']]]]],
                ['channel_name' => '2', 'now' => ['broadcast_title' => 'Second Show', 'embeds' => []]],
            ]]),
            '*/api/v2/mixtapes' => Http::response(['results' => [
                ['mixtape_alias' => 'poolside', 'title' => 'Poolside', 'subtitle' => 'Sun', 'audio_stream_endpoint' => 'https://stream.test/mixtape4', 'media' => ['picture_medium' => 'https://img.test/p.jpg']],
                ['mixtape_alias' => 'broken'],
            ]]),
        ]);
    }

    public function test_the_live_show_is_read_per_channel_with_entities_decoded(): void
    {
        $this->fakeNts();

        $now = app(NtsClient::class)->now('1');

        $this->assertSame("AURAL w/ ANGEL D'LITE", $now['title']);
        $this->assertSame(['Techno'], $now['genres']);
        $this->assertSame('Second Show', app(NtsClient::class)->now('2')['title']);
        $this->assertNull(app(NtsClient::class)->now('3'));
    }

    public function test_an_unreachable_api_means_no_title(): void
    {
        Cache::flush();
        Http::fake(['*' => Http::response('', 500)]);

        $this->assertNull(app(NtsClient::class)->now('1'));
    }

    public function test_the_import_creates_live_channels_and_mixtapes_once(): void
    {
        $this->fakeNts();

        $this->artisan('radio:import-nts')->assertSuccessful();
        $this->assertSame(3, RadioStation::count());

        $this->artisan('radio:import-nts')->assertSuccessful();
        $this->assertSame(3, RadioStation::count());

        $live = RadioStation::with('meta')->get()->first(fn ($s) => $s->getMeta('nts_channel') === '2');
        $this->assertSame('NTS 2', $live->name);
        $this->assertSame('https://stream-relay-geo.ntslive.net/stream2', $live->streamUrl());
        $this->assertSame('https://stream.test/mixtape4', RadioStation::with('meta')->where('name', 'NTS Poolside')->first()->streamUrl());
    }

    public function test_a_live_channel_polls_the_show_title_and_a_mixtape_falls_back_to_icy(): void
    {
        $this->fakeNts();
        Queue::fake();
        $this->artisan('radio:import-nts');

        $live = RadioStation::with('meta')->get()->first(fn ($s) => $s->getMeta('nts_channel') === '1');
        StreamTitles::touch($live);
        (new PollRadioTitle($live->id))->handle(app(NowPlayingProviders::class));

        $this->assertSame("AURAL w/ ANGEL D'LITE", StreamTitles::get($live->id));
        $this->assertSame('nts', StreamTitles::source($live->id));
        $this->assertSame('nts', $live->fresh()->apiItem()['now']['source']);
    }
}
