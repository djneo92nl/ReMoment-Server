<?php

namespace Tests\Feature;

use App\Domain\Backup\BackupStatus;
use App\Domain\Credentials\ServiceCredentials;
use App\Domain\Health\ExternalServices;
use App\Livewire\ServiceCredentialsManager;
use App\Models\Setting;
use App\Models\User;
use App\Services\Discogs\DiscogsClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use Tests\TestCase;
use ZipArchive;

/** The credentials registry and its one settings page (docs/architecture/credentials.md). */
class ServiceCredentialsTest extends TestCase
{
    use RefreshDatabase;

    private const DISCOGS = 'DISCOGS-TOKEN-0123456789';

    private const LASTFM_SECRET = 'lastfmsecret0123456789abcdef';

    protected function setUp(): void
    {
        parent::setUp();

        Cache::flush();
        config([
            'spotify.auth.client_id' => null, 'spotify.auth.client_secret' => null,
            'lastfm.api_key' => null, 'lastfm.shared_secret' => null,
            'metadata.discogs_token' => null, 'metadata.audiodb_key' => '123', 'analysis.acoustid_key' => null,
        ]);
    }

    public function test_every_service_is_declared_and_every_probe_exists(): void
    {
        $this->assertEqualsCanonicalizing(['spotify', 'lastfm', 'discogs', 'acoustid', 'audiodb', 'jellyfin'], array_keys(ServiceCredentials::all()));

        $probes = (new ExternalServices)->probeIds();
        foreach (ServiceCredentials::all() as $service) {
            $this->assertNotEmpty($service->steps);
            $this->assertNotEmpty($service->probes);
            foreach ($service->probes as $probe) {
                $this->assertContains($probe, $probes, "{$service->id} points at an unknown probe");
            }
        }
    }

    public function test_spotify_keeps_its_existing_setting_keys(): void
    {
        $this->assertSame('spotify_client_id', ServiceCredentials::find('spotify')->field('client_id')->setting);
        $this->assertSame('spotify_client_secret', ServiceCredentials::find('spotify')->field('client_secret')->setting);
    }

    public function test_a_secret_is_encrypted_in_its_row_and_an_empty_input_keeps_it(): void
    {
        ServiceCredentials::save('discogs', ['token' => self::DISCOGS]);

        $this->assertNotSame(self::DISCOGS, Setting::get('discogs_token'));
        $this->assertSame(self::DISCOGS, ServiceCredentials::value('discogs', 'token'));

        ServiceCredentials::save('discogs', ['token' => '']);
        $this->assertSame(self::DISCOGS, ServiceCredentials::value('discogs', 'token'));
    }

    public function test_the_env_fallback_works_and_a_stored_value_wins(): void
    {
        config(['metadata.discogs_token' => 'from-env-token']);

        $this->assertSame('from-env-token', ServiceCredentials::value('discogs', 'token'));
        $this->assertSame('env', ServiceCredentials::origin('discogs', 'token'));
        $this->assertTrue(DiscogsClient::enabled());

        ServiceCredentials::save('discogs', ['token' => self::DISCOGS]);
        $this->assertSame(self::DISCOGS, ServiceCredentials::value('discogs', 'token'));
        $this->assertSame('settings', ServiceCredentials::origin('discogs', 'token'));
    }

    public function test_plaintext_secret_rows_are_encrypted_once(): void
    {
        Setting::set('discogs_token', 'plain-token-value');

        $this->assertSame(1, ServiceCredentials::encryptPlaintext());
        $this->assertSame('plain-token-value', ServiceCredentials::value('discogs', 'token'));
        $this->assertSame(0, ServiceCredentials::encryptPlaintext());
    }

    public function test_clients_read_the_base_url_from_the_registry(): void
    {
        Setting::set('discogs_base_url', 'https://relay.example/discogs/');
        ServiceCredentials::save('discogs', ['token' => self::DISCOGS]);
        Http::fake(['*' => Http::response(['ok' => true])]);

        (new DiscogsClient)->get('releases/1');

        Http::assertSent(fn ($r) => str_starts_with($r->url(), 'https://relay.example/discogs/releases/1'));
    }

    public function test_the_page_never_shows_a_secret_and_guests_are_sent_away(): void
    {
        ServiceCredentials::save('lastfm', ['api_key' => 'lastfmkey0123456789abcdef', 'shared_secret' => self::LASTFM_SECRET]);
        ServiceCredentials::save('discogs', ['token' => self::DISCOGS]);

        $this->get(route('settings.services'))->assertRedirect();

        $page = $this->actingAs(User::factory()->create())->get(route('settings.services'))->assertOk();
        $page->assertSee('Discogs')->assertSee('TheAudioDB')->assertSee('Leave empty to keep', false);
        foreach ([self::DISCOGS, self::LASTFM_SECRET] as $secret) {
            $page->assertDontSee($secret, false);
        }
        // Plain (non-secret) values and the last characters of a long secret are allowed.
        $page->assertSee('…ef', false);
    }

    public function test_the_component_saves_keeps_and_never_exposes_a_secret(): void
    {
        $this->actingAs(User::factory()->create());

        $c = Livewire::test(ServiceCredentialsManager::class)
            ->set('inputs.discogs.token', self::DISCOGS)
            ->call('save', 'discogs')
            ->assertHasNoErrors();

        $this->assertSame(self::DISCOGS, ServiceCredentials::value('discogs', 'token'));
        $c->assertSet('inputs.discogs.token', '')->assertDontSee(self::DISCOGS, false);
        $this->assertStringNotContainsString(self::DISCOGS, json_encode($c->snapshot));

        $c->call('save', 'discogs')->assertHasNoErrors();
        $this->assertSame(self::DISCOGS, ServiceCredentials::value('discogs', 'token'));
    }

    public function test_a_required_field_that_is_empty_is_refused_and_flagged(): void
    {
        $admin = User::factory()->create();

        Livewire::actingAs($admin)->test(ServiceCredentialsManager::class)
            ->call('save', 'discogs')
            ->assertHasErrors('inputs.discogs.token')
            ->assertSee('Missing: Personal access token');
    }

    public function test_the_component_refuses_guests(): void
    {
        Livewire::test(ServiceCredentialsManager::class)->assertStatus(403);
    }

    public function test_the_test_button_runs_the_services_probe(): void
    {
        ServiceCredentials::save('discogs', ['token' => self::DISCOGS]);
        Http::fake(['api.discogs.com/*' => Http::response(['username' => 'remko'])]);

        Livewire::actingAs(User::factory()->create())->test(ServiceCredentialsManager::class)
            ->call('test', 'discogs')
            ->assertSee('Token accepted for remko');

        Http::assertSentCount(1);
    }

    public function test_secrets_are_not_in_a_backup_and_a_probe_error_does_not_leak_them(): void
    {
        ServiceCredentials::save('lastfm', ['api_key' => 'lastfmkey0123456789abcdef', 'shared_secret' => self::LASTFM_SECRET]);
        ServiceCredentials::save('discogs', ['token' => self::DISCOGS]);
        ServiceCredentials::save('acoustid', ['api_key' => 'acoustidkey0123456789']);

        $dir = sys_get_temp_dir().'/remoment-cred-backup-'.uniqid();
        config(['backup.path' => $dir]);
        $this->assertSame(0, Artisan::call('backup:run'));
        $zip = new ZipArchive;
        $archives = BackupStatus::archives($dir);
        $zip->open(end($archives));
        $all = '';
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $all .= $zip->getFromIndex($i);
        }
        $zip->close();
        array_map('unlink', glob($dir.'/*'));
        rmdir($dir);

        foreach (['lastfmkey0123456789abcdef', self::LASTFM_SECRET, self::DISCOGS, 'acoustidkey0123456789'] as $secret) {
            $this->assertStringNotContainsString($secret, $all);
        }
        $this->assertStringNotContainsString('lastfm_api_key', $all);
        $this->assertStringNotContainsString('acoustid_api_key', $all);

        Http::fake(fn () => throw new \Illuminate\Http\Client\ConnectionException('cURL error for https://ws.audioscrobbler.com/2.0/?api_key=lastfmkey0123456789abcdef'));
        $results = (new ExternalServices)->refresh(['lastfm_key']);
        $this->assertStringNotContainsString('lastfmkey0123456789abcdef', json_encode($results));
    }

    public function test_the_old_spotify_routes_still_work(): void
    {
        $admin = User::factory()->create();

        $this->actingAs($admin)->get(route('settings.spotify'))->assertOk()->assertSee('Services');
        $this->actingAs($admin)->get(route('settings.lastfm'))->assertOk()->assertSee('Services');
        $this->actingAs($admin)->get(route('settings.index'))->assertOk()->assertSee('Services & keys');
        $this->actingAs($admin)->post(route('spotify.credentials'), ['client_id' => 'abcdef0123456789abcdef', 'client_secret' => 'abcdef0123456789abcdef'])
            ->assertRedirect(route('settings.spotify'));
        $this->assertTrue(ServiceCredentials::configured('spotify'));
    }
}
