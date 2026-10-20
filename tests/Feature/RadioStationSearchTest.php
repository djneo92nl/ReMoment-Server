<?php

namespace Tests\Feature;

use App\Domain\Radio\IcyReader;
use App\Domain\Radio\RadioBrowser;
use App\Domain\Radio\StreamTitles;
use App\Jobs\PollRadioTitle;
use App\Livewire\RadioStationSearch;
use App\Livewire\RadioStreamFinder;
use App\Models\RadioStation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;
use Tests\TestCase;

/** Stream-URL stations: ICY titles, the poll chain and the radio-browser.info search. */
class RadioStationSearchTest extends TestCase
{
    use RefreshDatabase;

    private function icyStream(string $title, int $metaint = 16): mixed
    {
        $block = "StreamTitle='{$title}';";
        $block .= str_repeat("\0", (16 - strlen($block) % 16) % 16);
        $audio = str_repeat('A', $metaint);

        $stream = fopen('php://memory', 'r+');
        fwrite($stream, $audio.chr(strlen($block) / 16).$block.$audio);
        rewind($stream);

        return $stream;
    }

    public function test_the_title_is_read_after_the_metaint_audio_bytes(): void
    {
        $reader = new IcyReader;

        $this->assertSame('Artist - Song', $reader->readTitle($this->icyStream('Artist - Song'), 16));
        $this->assertNull($reader->readTitle($this->icyStream(''), 16));
        $this->assertNull($reader->readTitle($this->icyStream('Artist - Song'), null));
        $this->assertSame(12345, IcyReader::metaint(['HTTP/1.1 200 OK', 'icy-metaint: 12345']));
        $this->assertNull(IcyReader::metaint(['HTTP/1.1 200 OK']));
    }

    public function test_titles_split_into_artist_and_song_and_latin1_is_converted(): void
    {
        $this->assertSame(['Artist', 'Song - Live'], IcyReader::split('Artist - Song - Live'));
        $this->assertSame([null, 'Just a title'], IcyReader::split('Just a title'));
        $this->assertSame('Caf'."\u{e9}", IcyReader::parseBlock("StreamTitle='Caf\xe9';StreamUrl='';\0\0"));
    }

    public function test_the_poll_chain_stores_the_title_and_ends_when_nobody_listens(): void
    {
        Queue::fake();
        $station = RadioStation::create(['name' => 'Radio Test']);
        $station->setMeta('url', 'http://stream.test/radio.mp3');
        $this->app->instance(IcyReader::class, new class extends IcyReader
        {
            public function title(string $url): ?string
            {
                return 'Artist - Song';
            }
        });

        StreamTitles::touch($station);
        Queue::assertPushed(PollRadioTitle::class, 1);

        StreamTitles::touch($station); // a second listener of the same station does not start a second chain
        Queue::assertPushed(PollRadioTitle::class, 1);

        (new PollRadioTitle($station->id))->handle(app(IcyReader::class));
        $this->assertSame('Artist - Song', StreamTitles::get($station->id));
        Queue::assertPushed(PollRadioTitle::class, 2);

        Cache::forget("radio:active:{$station->id}");
        (new PollRadioTitle($station->id))->handle(app(IcyReader::class));
        $this->assertNull(StreamTitles::get($station->id));
        Queue::assertPushed(PollRadioTitle::class, 2);
    }

    public function test_the_search_keeps_plain_streams_and_fills_name_logo_and_genre(): void
    {
        Http::fake(['*' => Http::response([
            ['name' => 'Radio Fip', 'url' => 'http://old/fip', 'url_resolved' => 'https://stream.test/fip.mp3', 'favicon' => 'https://img.test/fip.png', 'tags' => 'eclectic,jazz', 'codec' => 'mp3', 'bitrate' => 128, 'countrycode' => 'FR', 'hls' => 0],
            ['name' => 'Playlist only', 'url' => 'http://x/list.m3u', 'url_resolved' => 'http://x/list.m3u', 'hls' => 0],
            ['name' => 'HLS', 'url' => 'https://x/live', 'url_resolved' => 'https://x/live', 'hls' => 1],
        ])]);

        $results = app(RadioBrowser::class)->search('fip');

        $this->assertCount(1, $results);
        $this->assertSame(['name' => 'Radio Fip', 'url' => 'https://stream.test/fip.mp3', 'image_url' => 'https://img.test/fip.png', 'genre' => 'Eclectic', 'codec' => 'MP3', 'bitrate' => 128, 'country' => 'FR'], $results[0]);
        $this->assertSame([], app(RadioBrowser::class)->search('f'));
    }

    public function test_adding_a_search_result_creates_the_station_once(): void
    {
        Http::fake(['*' => Http::response([['name' => 'Radio Fip', 'url' => 'https://stream.test/fip.mp3', 'tags' => 'jazz', 'hls' => 0]])]);

        $component = Livewire::test(RadioStationSearch::class)->set('query', 'fip')->call('search')->call('add', 0)->call('add', 0);

        $this->assertSame(1, RadioStation::count());
        $station = RadioStation::with('meta')->first();
        $this->assertSame('Radio Fip', $station->name);
        $this->assertSame('Jazz', $station->genre);
        $this->assertSame('https://stream.test/fip.mp3', $station->streamUrl());
        $component->assertSee('Added');
        $this->assertSame('Jazz', $station->apiItem()['genre']);
    }

    public function test_the_add_page_has_the_search_and_a_stream_url_can_be_stored(): void
    {
        $this->actingAs(\App\Models\User::factory()->create())->get('/radio/create')->assertOk()->assertSee('Find a station');

        $this->post('/radio', ['name' => 'By hand', 'identifiers' => ['url' => 'https://stream.test/hand.mp3']])->assertRedirect();
        $this->assertSame('https://stream.test/hand.mp3', RadioStation::where('name', 'By hand')->first()->streamUrl());

        $this->post('/radio', ['name' => 'Bad', 'identifiers' => ['url' => 'not a url']])->assertSessionHasErrors('identifiers.url');
    }

    public function test_the_edit_form_inputs_are_styled_and_hold_the_saved_identifiers(): void
    {
        $station = RadioStation::create(['name' => 'Energy 98', 'genre' => 'Dance']);
        $station->setMeta('tunein', 's15200');
        $station->setMeta('url', 'https://stream.test/energy.mp3');

        $html = $this->actingAs(\App\Models\User::factory()->create())->get("/radio/{$station->id}/edit")->assertOk()
            ->assertSee('Find the stream URL')->getContent();

        // Every identifier input keeps its classes (a quoting slip in the Blade attribute once dropped them) and its value.
        preg_match_all('/<input[^>]*>/s', $html, $inputs);
        foreach (['beoradio', 'tunein', 'url'] as $platform) {
            $tag = collect($inputs[0])->first(fn ($input) => str_contains($input, 'identifiers['.$platform.']'));
            $this->assertNotNull($tag, $platform);
            $this->assertStringContainsString('rounded-xl', $tag, $platform);
        }
        $this->assertStringContainsString('value="s15200"', $html);
        // The form's Alpine state starts with the saved values (Laravel's @js writes them as JSON.parse('...') with \u0022 quotes).
        $state = html_entity_decode($html);
        $this->assertStringContainsString('stream.test', $state);
        $this->assertStringContainsString('\u0022genre\u0022:\u0022Dance\u0022', $state);
        $this->assertStringContainsString('name="genre"', $html);
    }

    public function test_the_edit_form_saves_url_genre_and_clears_the_url(): void
    {
        $station = RadioStation::create(['name' => 'Energy 98']);

        $this->patch("/radio/{$station->id}", ['name' => 'Energy 98', 'genre' => 'Dance', 'identifiers' => ['url' => 'https://stream.test/energy.mp3', 'tunein' => '']])->assertRedirect();
        $station = $station->fresh('meta');
        $this->assertSame('Dance', $station->genre);
        $this->assertSame('https://stream.test/energy.mp3', $station->streamUrl());

        $this->patch("/radio/{$station->id}", ['name' => 'Energy 98', 'identifiers' => ['url' => '']])->assertRedirect();
        $this->assertNull($station->fresh('meta')->streamUrl());
    }

    public function test_the_stream_finder_searches_by_the_stations_name_and_hands_the_pick_to_the_form(): void
    {
        Http::fake(['*' => Http::response([['name' => '181.FM Energy 98', 'url' => 'https://stream.test/energy.mp3', 'favicon' => 'https://img.test/e.png', 'tags' => 'dance', 'codec' => 'MP3', 'hls' => 0]])]);

        Livewire::test(RadioStreamFinder::class, ['name' => '181.FM Energy 98'])
            ->assertSet('query', '181.FM Energy 98')
            ->call('search')
            ->assertSee('181.FM Energy 98')
            ->call('pick', 0)
            ->assertDispatched('radio-url-picked', url: 'https://stream.test/energy.mp3', image_url: 'https://img.test/e.png', genre: 'Dance')
            ->assertSee('Filled in');
    }
}
