<?php

namespace Tests\Feature\Api;

use App\Models\RadioStation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\Support\SignsInAsAdmin;
use Tests\TestCase;

/** /api/admin/radio-stations (docs/api/admin-radio.md): the station management of the web pages, for apps. */
class AdminRadioStationApiTest extends TestCase
{
    use RefreshDatabase;
    use SignsInAsAdmin;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();   // artwork processing of a logo is never waited for
    }

    private function station(string $name = 'Radio Fip', array $meta = ['url' => 'https://stream.test/fip.mp3']): RadioStation
    {
        $station = RadioStation::create(['name' => $name, 'genre' => 'Jazz']);
        foreach ($meta as $key => $value) {
            $station->setMeta($key, $value);
        }

        return $station;
    }

    public function test_every_route_needs_the_admin_token(): void
    {
        $station = $this->station();

        $this->assertNeedsAdminToken([
            ['GET', '/api/admin/radio-stations'], ['POST', '/api/admin/radio-stations'], ['GET', '/api/admin/radio-stations/search?q=fip'],
            ['POST', '/api/admin/radio-stations/directory'], ['GET', "/api/admin/radio-stations/{$station->id}"],
            ['PUT', "/api/admin/radio-stations/{$station->id}"], ['DELETE', "/api/admin/radio-stations/{$station->id}"],
        ]);
        $this->assertDatabaseCount('radio_stations', 1);
    }

    public function test_the_list_is_every_station_by_name_with_its_identifiers(): void
    {
        $this->station('Zulu FM', ['tunein' => 's1']);
        $this->station('Alpha Radio', ['beoradio' => 'b1', 'url' => 'https://stream.test/a.mp3']);

        $response = $this->getJson('/api/admin/radio-stations', $this->adminHeaders())->assertOk();

        $response->assertJsonPath('data.0.name', 'Alpha Radio')->assertJsonPath('data.1.name', 'Zulu FM');
        $response->assertJsonPath('data.0.identifiers', ['beoradio' => 'b1', 'url' => 'https://stream.test/a.mp3']);
        $response->assertJsonStructure(['data' => [['id', 'name', 'genre', 'favorite', 'artwork', 'now', 'image_url', 'identifiers']]]);

        $this->getJson('/api/admin/radio-stations?q=zul', $this->adminHeaders())->assertJsonCount(1, 'data')->assertJsonPath('data.0.name', 'Zulu FM');
    }

    public function test_create_stores_fields_and_non_empty_identifiers(): void
    {
        $response = $this->postJson('/api/admin/radio-stations', [
            'name' => 'Jazz FM', 'genre' => 'Jazz', 'image_url' => 'https://img.test/j.png',
            'identifiers' => ['url' => 'https://stream.test/jazz.mp3', 'tunein' => '  ', 'beoradio' => 'abc'],
        ], $this->adminHeaders())->assertCreated();

        $response->assertJsonPath('data.name', 'Jazz FM')->assertJsonPath('data.image_url', 'https://img.test/j.png')
            ->assertJsonPath('data.identifiers', ['beoradio' => 'abc', 'url' => 'https://stream.test/jazz.mp3']);
        $this->assertSame('https://stream.test/jazz.mp3', RadioStation::first()->streamUrl());
    }

    public function test_the_detail(): void
    {
        $station = $this->station();

        $this->getJson("/api/admin/radio-stations/{$station->id}", $this->adminHeaders())->assertOk()->assertJsonPath('data.id', $station->id);
        $this->getJson('/api/admin/radio-stations/999', $this->adminHeaders())->assertNotFound();
    }

    public function test_update_changes_the_fields_sent_and_an_empty_identifier_removes_it(): void
    {
        $station = $this->station('Old', ['url' => 'https://stream.test/old.mp3', 'tunein' => 's1']);

        $this->putJson("/api/admin/radio-stations/{$station->id}", ['name' => 'New', 'identifiers' => ['tunein' => '', 'beoradio' => 'b2']], $this->adminHeaders())
            ->assertOk()->assertJsonPath('data.name', 'New')->assertJsonPath('data.genre', 'Jazz')
            ->assertJsonPath('data.identifiers', ['beoradio' => 'b2', 'url' => 'https://stream.test/old.mp3']);

        $this->putJson("/api/admin/radio-stations/{$station->id}", ['genre' => null], $this->adminHeaders())->assertOk()->assertJsonPath('data.genre', null)->assertJsonPath('data.name', 'New');
    }

    public function test_validation(): void
    {
        $this->postJson('/api/admin/radio-stations', [], $this->adminHeaders())->assertStatus(422)->assertJsonValidationErrors(['name']);
        $this->postJson('/api/admin/radio-stations', ['name' => 'x', 'image_url' => 'nope', 'identifiers' => ['url' => 'not a url']], $this->adminHeaders())
            ->assertStatus(422)->assertJsonValidationErrors(['image_url', 'identifiers.url']);
        $this->postJson('/api/admin/radio-stations', ['name' => 'x', 'identifiers' => ['Bad Key' => 'v']], $this->adminHeaders())->assertStatus(422)->assertJsonValidationErrors(['identifiers']);
        $this->assertDatabaseCount('radio_stations', 0);
    }

    public function test_delete(): void
    {
        $station = $this->station();

        $this->deleteJson("/api/admin/radio-stations/{$station->id}", [], $this->adminHeaders())->assertOk()->assertJsonPath('status', 'ok');
        $this->assertDatabaseMissing('radio_stations', ['id' => $station->id]);
    }

    public function test_the_stream_finder_searches_the_directory(): void
    {
        Cache::flush();
        Http::fake(['*' => Http::response([
            ['name' => 'Radio Fip', 'url' => 'http://old/fip', 'url_resolved' => 'https://stream.test/fip.mp3', 'favicon' => 'https://img.test/fip.png', 'tags' => 'eclectic,jazz', 'codec' => 'mp3', 'bitrate' => 128, 'countrycode' => 'FR', 'stationuuid' => 'u-1', 'hls' => 0],
            ['name' => 'HLS', 'url' => 'https://x/live', 'hls' => 1],
        ])]);

        $this->getJson('/api/admin/radio-stations/search?q=fip', $this->adminHeaders())
            ->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.url', 'https://stream.test/fip.mp3')->assertJsonPath('data.0.genre', 'Eclectic')->assertJsonPath('data.0.uuid', 'u-1');
        $this->getJson('/api/admin/radio-stations/search', $this->adminHeaders())->assertStatus(422);
    }

    public function test_a_search_result_is_added_once_per_stream_url(): void
    {
        $result = ['name' => 'Radio Fip', 'url' => 'https://stream.test/fip.mp3', 'image_url' => 'https://img.test/fip.png', 'genre' => 'Jazz', 'uuid' => 'u-1'];

        $first = $this->postJson('/api/admin/radio-stations/directory', $result, $this->adminHeaders())->assertCreated();
        $first->assertJsonPath('data.identifiers', ['radio_browser_uuid' => 'u-1', 'url' => 'https://stream.test/fip.mp3']);
        $this->postJson('/api/admin/radio-stations/directory', $result, $this->adminHeaders())->assertOk()->assertJsonPath('data.id', $first->json('data.id'));

        $this->assertDatabaseCount('radio_stations', 1);
    }
}
