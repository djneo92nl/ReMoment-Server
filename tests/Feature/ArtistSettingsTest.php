<?php

namespace Tests\Feature;

use App\Domain\Library\ArtistIdentity;
use App\Domain\Library\Enrichment;
use App\Jobs\EnrichArtistAudioDb;
use App\Jobs\EnrichArtistMusicBrainz;
use App\Jobs\FetchSpotifyArtistImages;
use App\Livewire\ArtistSettings;
use App\Models\File;
use App\Models\Media\Album;
use App\Models\Media\Artist;
use App\Models\Media\Track;
use App\Models\StorageLocation;
use App\Models\TrackFile;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Tests\TestCase;

class ArtistSettingsTest extends TestCase
{
    use RefreshDatabase;

    private function artist(): Artist
    {
        $artist = Artist::create(['name' => 'Hayley Williams', 'source' => 'files']);
        Enrichment::save($artist, 'images', json_encode([
            'thumb' => 'https://img.test/thumb.jpg', 'fanart' => 'https://img.test/f1.jpg', 'fanart2' => 'https://img.test/f2.jpg',
        ]), 'json', 'audiodb');

        return $artist;
    }

    public function test_guests_cannot_open_the_page_or_act(): void
    {
        $artist = $this->artist();

        $this->get(route('artists.settings', $artist))->assertRedirect();

        Livewire::test(ArtistSettings::class, ['artist' => $artist])->call('choosePhoto', 'https://img.test/f1.jpg')->assertForbidden();
        $this->assertSame('https://img.test/thumb.jpg', $artist->fresh()->photoUrl());
    }

    public function test_admin_sees_the_page_and_the_artist_page_links_to_it(): void
    {
        $artist = $this->artist();
        $this->actingAs(User::factory()->create());

        $this->get(route('artists.settings', $artist))->assertOk()->assertSee('Artist settings');
        $this->get(route('artists.show', $artist))->assertOk()->assertSee(route('artists.settings', $artist), false);
    }

    public function test_a_chosen_photo_outranks_the_automatic_one_and_can_be_reset(): void
    {
        $artist = $this->artist();
        $this->actingAs(User::factory()->create());

        Livewire::test(ArtistSettings::class, ['artist' => $artist])->call('choosePhoto', 'https://img.test/f2.jpg');
        $this->assertSame('https://img.test/f2.jpg', Artist::find($artist->id)->photoUrl());

        // Enrichment running again writes its own row and doesn't undo the choice.
        Enrichment::save($artist, 'images', json_encode(['thumb' => 'https://img.test/new.jpg']), 'json', 'audiodb');
        $this->assertSame('https://img.test/f2.jpg', Artist::find($artist->id)->photoUrl());

        Livewire::test(ArtistSettings::class, ['artist' => $artist])->call('resetPhoto');
        $this->assertSame('https://img.test/new.jpg', Artist::find($artist->id)->photoUrl());
    }

    public function test_hidden_pictures_leave_the_gallery_and_come_back(): void
    {
        $artist = $this->artist();
        $this->actingAs(User::factory()->create());

        $this->assertEqualsCanonicalizing(['https://img.test/f1.jpg', 'https://img.test/f2.jpg'], $artist->galleryUrls());

        Livewire::test(ArtistSettings::class, ['artist' => $artist])->call('toggleHidden', 'https://img.test/f1.jpg');
        $this->assertSame(['https://img.test/f2.jpg'], Artist::find($artist->id)->galleryUrls());

        Livewire::test(ArtistSettings::class, ['artist' => $artist])->call('setAllHidden', true);
        $this->assertSame([], Artist::find($artist->id)->galleryUrls());

        Livewire::test(ArtistSettings::class, ['artist' => $artist])->call('setAllHidden', false);
        $this->assertCount(2, Artist::find($artist->id)->galleryUrls());
    }

    public function test_pictures_can_be_added_by_link_and_uploaded(): void
    {
        Storage::fake('local');
        $artist = $this->artist();
        $this->actingAs(User::factory()->create());

        Livewire::test(ArtistSettings::class, ['artist' => $artist])
            ->set('imageUrl', 'not a url')->call('addImage')->assertSet('problems.photo', 'That is not an http(s) link to a picture.')
            ->set('imageUrl', 'https://img.test/mine.jpg')->call('addImage')
            ->set('upload', UploadedFile::fake()->image('me.png', 400, 400))
            ->call('removeImage', 'https://img.test/mine.jpg');

        $extra = Artist::find($artist->id)->extraImages();
        $this->assertCount(1, $extra);
        $this->assertStringStartsWith('remoment:cover/', $extra[0]);
        $this->assertContains($extra[0], Artist::find($artist->id)->galleryUrls());
    }

    public function test_a_manual_musicbrainz_match_replaces_the_old_one_and_fetches_again(): void
    {
        Bus::fake();
        $artist = $this->artist();
        $old = '11111111-1111-1111-1111-111111111111';
        $new = '22222222-2222-2222-2222-222222222222';
        Enrichment::save($artist, 'mbid', $old, 'string', 'musicbrainz');
        Enrichment::save($artist, 'country', 'XX', 'string', 'musicbrainz');
        Enrichment::markDone($artist, 'musicbrainz');
        Enrichment::markDone($artist, 'audiodb');
        $this->actingAs(User::factory()->create());

        Livewire::test(ArtistSettings::class, ['artist' => $artist])
            ->call('chooseMusicBrainz', 'nonsense')->assertSet('problems.identity', 'That is not a MusicBrainz id or artist link.')
            ->call('chooseMusicBrainz', "https://musicbrainz.org/artist/{$new}");

        $fresh = Artist::find($artist->id);
        $this->assertSame($new, Enrichment::mbid($fresh));
        $this->assertNull($fresh->country(), 'what the old match brought is gone');
        $this->assertFalse(Enrichment::isDone($fresh, 'musicbrainz'));
        Bus::assertDispatched(EnrichArtistMusicBrainz::class);
        Bus::assertDispatched(EnrichArtistAudioDb::class);

        Livewire::test(ArtistSettings::class, ['artist' => $artist])->call('resetMapping', 'mbid');
        $this->assertNull(Enrichment::mbid(Artist::find($artist->id)));
    }

    public function test_a_spotify_link_is_parsed_and_the_photo_fetched_again(): void
    {
        Bus::fake();
        $artist = $this->artist();
        $this->actingAs(User::factory()->create());

        Livewire::test(ArtistSettings::class, ['artist' => $artist])
            ->call('chooseSpotify', 'https://open.spotify.com/artist/6FQ9IAXCH0GALKAjMwxqUh?si=abc');

        $this->assertSame('6FQ9IAXCH0GALKAjMwxqUh', Artist::find($artist->id)->metaValue('spotify_id'));
        Bus::assertDispatched(FetchSpotifyArtistImages::class);
        $this->assertNull(ArtistIdentity::parseSpotifyId('nope'));
    }

    public function test_the_lastfm_name_is_used_for_the_lookup_and_refetch_clears_markers(): void
    {
        Bus::fake();
        $artist = $this->artist();
        Enrichment::markDone($artist, 'lastfm');
        $this->actingAs(User::factory()->create());

        Livewire::test(ArtistSettings::class, ['artist' => $artist])->set('lastfmInput', 'HAYLEY')->call('saveLastfmName');

        $this->assertSame('HAYLEY', Artist::find($artist->id)->metaValue('lastfm_name'));
        $this->assertFalse(Enrichment::isDone(Artist::find($artist->id), 'lastfm'));
    }

    public function test_rename_and_merge(): void
    {
        $artist = $this->artist();
        $twin = Artist::create(['name' => 'Hayley Willams', 'source' => 'dlna']);
        $album = Album::create(['name' => 'Petals', 'artist_id' => $twin->id, 'source' => 'dlna']);
        $this->actingAs(User::factory()->create());

        $component = Livewire::test(ArtistSettings::class, ['artist' => $artist])
            ->set('nameInput', '  ')->call('rename')->assertSet('problems.names', 'The name can not be empty.')
            ->set('nameInput', 'Hayley Nichole Williams')->call('rename')
            ->set('mergeSearch', 'Willams')->assertSee('Hayley Willams')
            ->call('merge', $twin->id);

        $this->assertSame('Hayley Nichole Williams', $artist->fresh()->name);
        $this->assertSame($artist->id, $album->fresh()->artist_id);
        $this->assertNull(Artist::find($twin->id));
    }

    public function test_details_overrides_win_and_blank_goes_back_to_automatic(): void
    {
        $artist = $this->artist();
        Enrichment::save($artist, 'country', 'US', 'string', 'musicbrainz');
        Enrichment::save($artist, 'genres', json_encode(['Pop Punk']), 'json', 'musicbrainz');
        $this->actingAs(User::factory()->create());

        Livewire::test(ArtistSettings::class, ['artist' => $artist])
            ->set('details', ['bio' => 'My own bio', 'genres' => 'Art Pop, Indie', 'country' => 'NL', 'type' => 'group', 'tags' => 'loud, red'])
            ->call('saveDetails');

        $fresh = Artist::find($artist->id);
        $this->assertSame('My own bio', $fresh->bio());
        $this->assertSame('NL', $fresh->country());
        $this->assertSame('group', $fresh->details()['type']);
        $this->assertSame(['loud', 'red'], $fresh->details()['tags']);
        $this->assertEqualsCanonicalizing(['Art Pop', 'Indie'], $fresh->genres());

        Livewire::test(ArtistSettings::class, ['artist' => $artist])
            ->set('details', ['bio' => '', 'genres' => '', 'country' => '', 'type' => '', 'tags' => ''])->call('saveDetails');

        $fresh = Artist::find($artist->id);
        $this->assertSame('US', $fresh->country());
        $this->assertNull($fresh->bio());
        $this->assertSame(['Pop Punk'], $fresh->genres());
    }

    public function test_the_default_source_picks_the_page_source(): void
    {
        $artist = $this->artist();
        $this->actingAs(User::factory()->create());

        Livewire::test(ArtistSettings::class, ['artist' => $artist])->set('defaultSource', 'spotify')->call('saveDefaultSource');
        $this->assertSame('spotify', Artist::find($artist->id)->metaValue('default_source'));

        Livewire::test(ArtistSettings::class, ['artist' => $artist])->set('defaultSource', '')->call('saveDefaultSource');
        $this->assertNull(Artist::find($artist->id)->metaValue('default_source'));
    }

    public function test_only_the_artists_own_files_can_be_changed(): void
    {
        $artist = $this->artist();
        $other = Artist::create(['name' => 'Someone Else', 'source' => 'files']);
        $location = StorageLocation::create(['name' => 'Music', 'driver' => 'local', 'config' => ['root' => sys_get_temp_dir()], 'role' => 'library']);
        $mine = $this->fileFor($artist, $location, 'a.mp3');
        $theirs = $this->fileFor($other, $location, 'b.mp3');
        $this->actingAs(User::factory()->create());

        $component = Livewire::test(ArtistSettings::class, ['artist' => $artist])->assertSee('a.mp3')->assertDontSee('b.mp3');
        $component->call('toggleFileHidden', $mine->id);
        $this->assertTrue($mine->fresh()->isHidden());

        try {
            $component->call('toggleFileHidden', $theirs->id);
            $this->fail('another artist\'s file must not be reachable');
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException) {
        }
        $this->assertFalse($theirs->fresh()->isHidden());

        $component->call('setAllFilesHidden', false);
        $this->assertFalse($mine->fresh()->isHidden());
    }

    private function fileFor(Artist $artist, StorageLocation $location, string $path): File
    {
        $file = File::create(['storage_location_id' => $location->id, 'path' => $path, 'hash' => md5($path), 'size' => 10, 'mtime' => 1, 'status' => File::PRESENT]);
        $track = Track::create(['name' => $path, 'artist_id' => $artist->id, 'source' => 'files']);
        TrackFile::create(['track_id' => $track->id, 'file_id' => $file->id, 'role' => 'original', 'is_primary' => true]);

        return $file;
    }

    public function test_remove_from_library_moves_to_the_settings_page(): void
    {
        $artist = Artist::create(['name' => 'Nothing', 'source' => 'spotify']);
        $this->actingAs(User::factory()->create());

        Livewire::test(ArtistSettings::class, ['artist' => $artist])->call('removeFromLibrary')->assertRedirect(route('artists.index'));
        $this->assertNull(Artist::find($artist->id));
    }
}
