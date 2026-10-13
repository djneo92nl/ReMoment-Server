<?php

namespace Tests\Feature;

use App\Domain\Health\ExternalServices;
use App\Livewire\ExternalServicesHealth;
use App\Models\StorageLocation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use Tests\TestCase;

class ExternalServicesHealthTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
        config([
            'spotify.auth.client_id' => null, 'lastfm.api_key' => null, 'lastfm.shared_secret' => null,
            'metadata.discogs_token' => null, 'metadata.audiodb_key' => '123',
        ]);
    }

    public function test_keyless_services_report_reachable_and_unset_credentials_are_disabled(): void
    {
        Http::fake([
            'theaudiodb.com/*' => Http::response(['artists' => [['strArtist' => 'Radiohead']]]),
            '*' => Http::response([], 200),
        ]);

        $results = (new ExternalServices)->refresh();

        $this->assertSame('ok', $results['musicbrainz']['status']);
        $this->assertSame('ok', $results['audiodb']['status']);
        $this->assertSame('disabled', $results['spotify_app']['status']);
        $this->assertSame('disabled', $results['discogs']['status']);
        $this->assertSame('disabled', $results['lastfm_key']['status']);
    }

    public function test_outage_rate_limit_and_rejected_credentials_are_flagged(): void
    {
        config(['metadata.discogs_token' => 'bad', 'lastfm.api_key' => 'bad']);
        Http::fake([
            'musicbrainz.org/*' => Http::response('', 503),
            'wikipedia.org/*' => Http::response('', 429),
            'api.discogs.com/*' => Http::response(['message' => 'Invalid token'], 401),
            'ws.audioscrobbler.com/*' => Http::response(['error' => 10, 'message' => 'Invalid API key']),
            'theaudiodb.com/*' => Http::response('Not found', 404),
            '*' => Http::response([], 200),
        ]);

        $results = (new ExternalServices)->refresh();

        $this->assertSame('error', $results['musicbrainz']['status']);
        $this->assertSame('warning', $results['wikipedia']['status']);
        $this->assertSame('error', $results['discogs']['status']);
        $this->assertSame('error', $results['lastfm_key']['status']);
        $this->assertSame('error', $results['audiodb']['status']);
    }

    public function test_missing_local_storage_directory_fails(): void
    {
        StorageLocation::create(['name' => 'Music', 'driver' => 'local', 'config' => ['root' => '/nonexistent/path'], 'role' => 'library']);
        Http::fake(['*' => Http::response([], 200)]);

        $results = (new ExternalServices)->refresh();

        $this->assertSame('error', collect($results)->firstWhere('name', 'Storage: Music')['status']);
    }

    public function test_component_shows_nothing_until_checked_then_lists_results(): void
    {
        Http::fake(['*' => Http::response([], 200)]);

        Livewire::test(ExternalServicesHealth::class)
            ->assertSee('Not checked yet')
            ->call('check')
            ->assertSee('MusicBrainz')
            ->assertSee('Checked');
    }
}
