<?php

namespace Tests\Feature;

use App\Domain\Library\LeadingSource;
use App\Models\Media\Album;
use App\Models\Media\Artist;
use App\Models\Media\Track;
use App\Models\Setting;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TrackDurationDisplayTest extends TestCase
{
    use RefreshDatabase;

    public function test_album_page_shows_track_length_as_minutes_and_seconds(): void
    {
        Setting::set(LeadingSource::SETTING, 'all');
        $artist = Artist::create(['name' => 'A', 'source' => 'x']);
        $album = Album::create(['artist_id' => $artist->id, 'name' => 'LP', 'source' => 'x']);
        Track::create(['artist_id' => $artist->id, 'album_id' => $album->id, 'name' => 'Song', 'source' => 'x', 'duration' => 213]);

        $this->get(route('albums.show', $album))->assertOk()->assertSee('3:33')->assertDontSee('12:03');
    }
}
