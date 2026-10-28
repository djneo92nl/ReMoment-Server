<?php

namespace Tests\Feature\Library;

use App\Models\Media\Album;
use App\Models\Media\Artist;
use App\Models\Media\Metadata;
use App\Models\Media\Track;
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
}
