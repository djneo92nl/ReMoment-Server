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
            'key' => $source === 'spotify' ? 'external_id' : 'dlna_url', 'value' => 'x', 'type' => 'url',
            'source' => $source === 'dlna' ? 'dlna:1' : ($source === 'files' ? 'files' : 'spotify'),
        ]);

        return $album;
    }

    public function test_files_lead_then_dlna_then_the_own_files_again(): void
    {
        $this->assertSame('files', LeadingSource::leading());
        $this->assertSame('local', LeadingSource::defaultScope());

        $this->album('Streamed', 'dlna');

        $this->assertSame('dlna', LeadingSource::leading());
        $this->assertSame('streaming', LeadingSource::defaultScope());

        $this->album('Local', 'files');

        $this->assertSame('files', LeadingSource::leading());
        $this->assertSame('local', LeadingSource::defaultScope());
    }

    public function test_the_setting_overrides_automatic_detection(): void
    {
        $this->album('Local', 'files');
        Setting::set(LeadingSource::SETTING, 'dlna');

        $this->assertSame('dlna', LeadingSource::leading());
        $this->assertSame('streaming', LeadingSource::defaultScope());
    }
}
