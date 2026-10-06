<?php

namespace Tests\Feature\Library;

use App\Domain\Library\LeadingSource;
use App\Models\Media\Album;
use App\Models\Media\Artist;
use App\Models\Media\Metadata;
use App\Models\Media\Track;
use App\Models\Setting;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class LeadingSourceTest extends TestCase
{
    use RefreshDatabase;

    private function album(string $name, string $source): Album
    {
        $artist = Artist::create(['name' => "Artist {$name}", 'source' => $source]);
        $album = Album::create(['artist_id' => $artist->id, 'name' => $name, 'source' => $source]);
        $track = Track::create(['artist_id' => $artist->id, 'album_id' => $album->id, 'name' => "{$name} song", 'external_id' => "{$source}:{$name}", 'source' => $source, 'duration' => 100]);
        Metadata::create([
            'metadatable_type' => Track::class, 'metadatable_id' => $track->id,
            'key' => $source === 'dlna' ? 'dlna_url' : 'external_id', 'value' => 'x', 'type' => 'url',
            'source' => $source === 'dlna' ? 'dlna:1' : 'spotify',
        ]);

        return $album;
    }

    public function test_dlna_leads_when_it_has_tracks_and_spotify_otherwise(): void
    {
        $this->assertSame('spotify', LeadingSource::leading());
        $this->assertSame('streaming', LeadingSource::defaultScope());

        $this->album('Local', 'dlna');

        $this->assertSame('dlna', LeadingSource::leading());
        $this->assertSame('local', LeadingSource::defaultScope());
    }

    public function test_the_setting_overrides_automatic_detection(): void
    {
        $this->album('Local', 'dlna');
        Setting::set(LeadingSource::SETTING, 'spotify');

        $this->assertSame('spotify', LeadingSource::leading());
    }

    public function test_api_defaults_to_the_leading_source_and_accepts_a_scope(): void
    {
        $this->album('Local', 'dlna');
        $this->album('Zephyr', 'spotify');

        $names = fn ($r) => collect($r->json('data'))->pluck('name')->sort()->values()->all();

        $this->assertSame(['Artist Local'], $names($this->getJson('/api/library/artists')));
        $this->assertSame(['Artist Zephyr'], $names($this->getJson('/api/library/artists?scope=streaming')));
        $this->assertSame(['Artist Local', 'Artist Zephyr'], $names($this->getJson('/api/library/artists?scope=all')));
        $this->getJson('/api/library/artists?scope=nope')->assertUnprocessable();
    }

    public function test_web_pages_follow_the_scope_and_remember_it(): void
    {
        $this->album('Local', 'dlna');
        $this->album('Zephyr', 'spotify');

        $this->get('/albums')->assertSee('Local')->assertDontSee('Zephyr');
        $this->get('/albums?scope=streaming')->assertSee('Zephyr')->assertDontSee('Artist Local');
        $this->withSession(['library_scope' => 'streaming'])->get('/albums')->assertSee('Zephyr');
    }

    public function test_admin_can_save_the_leading_source(): void
    {
        $this->actingAs(\App\Models\User::factory()->create())
            ->post('/settings/library', ['leading_source' => 'spotify'])
            ->assertRedirect('/settings/library');

        $this->assertSame('spotify', Setting::get(LeadingSource::SETTING));
    }
}
