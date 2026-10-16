<?php

namespace Tests\Feature;

use App\Domain\Library\LibraryIdentity;
use App\Domain\Storage\StorageTasks;
use App\Domain\Storage\TrackSuggestions;
use App\Livewire\StorageFiles;
use App\Models\File;
use App\Models\StorageLocation;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File as Fs;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use Tests\TestCase;

/** Candidates for what a file could be, picked in the tag editor of /settings/storage. */
class TrackSuggestionsTest extends TestCase
{
    use RefreshDatabase;

    private string $base;

    protected function setUp(): void
    {
        parent::setUp();

        $this->base = sys_get_temp_dir().'/remoment-suggest-'.uniqid();
        Fs::makeDirectory($this->base.'/library', 0777, true);
    }

    protected function tearDown(): void
    {
        Fs::deleteDirectory($this->base);

        parent::tearDown();
    }

    private function wav(): string
    {
        $data = str_repeat(chr(0x80), 8000);

        return 'RIFF'.pack('V', 36 + strlen($data)).'WAVEfmt '.pack('VvvVVvv', 16, 1, 1, 8000, 8000, 1, 8).'data'.pack('V', strlen($data)).$data;
    }

    private function libraryTrack(string $artist, string $album, string $title): void
    {
        $artist = LibraryIdentity::artist($artist, 'dlna');
        LibraryIdentity::track($artist, LibraryIdentity::album($artist, $album, 'dlna'), $title, null, 'dlna');
    }

    public function test_library_candidates_match_the_words_of_title_and_artist(): void
    {
        $this->libraryTrack('Daft Punk', 'Discovery', 'One More Time');
        $this->libraryTrack('Daft Punk', 'Discovery', 'Aerodynamic');
        $this->libraryTrack('Other Band', 'Other', 'One More Night');

        $found = app(TrackSuggestions::class)->library('one more', 'daft');

        $this->assertCount(1, $found);
        $this->assertSame('One More Time', $found[0]['title']);
        $this->assertSame('Daft Punk', $found[0]['artist']);
        $this->assertSame('Discovery', $found[0]['album']);
        $this->assertSame([], app(TrackSuggestions::class)->library('', ''));
    }

    public function test_musicbrainz_candidates_are_mapped_best_match_first(): void
    {
        Http::fake(['musicbrainz.org/*' => Http::response(['recordings' => [
            ['id' => 'a', 'score' => 70, 'title' => 'Song (live)', 'artist-credit' => [['name' => 'The Band']], 'releases' => [['title' => 'Live', 'date' => '2001-05-01']]],
            ['id' => 'b', 'score' => 100, 'title' => 'Song', 'artist-credit' => [['name' => 'The Band', 'joinphrase' => ' feat. ']], 'releases' => [['title' => 'Studio', 'date' => '1999']]],
        ]])]);

        $found = app(TrackSuggestions::class)->musicbrainz('Song', 'The Band');

        $this->assertSame(['Song', 'Song (live)'], array_column($found, 'title'));
        $this->assertSame('1999', $found[0]['year']);
        $this->assertSame('Live', $found[1]['album']);
        $this->assertStringContainsString('100% match', $found[0]['detail']);
    }

    public function test_picking_a_candidate_fills_the_tag_form(): void
    {
        $this->actingAs(User::factory()->create());
        $location = StorageLocation::create(['name' => 'library', 'driver' => 'local', 'role' => 'library', 'config' => ['root' => $this->base.'/library']]);
        file_put_contents($this->base.'/library/04 - one more time.wav', $this->wav());
        StorageTasks::queue('scan', $location->id);
        $file = File::firstOrFail();

        Http::fake(['musicbrainz.org/*' => Http::response(['recordings' => [
            ['id' => 'a', 'score' => 95, 'title' => 'One More Time', 'artist-credit' => [['name' => 'Daft Punk']], 'releases' => [['title' => 'Discovery', 'date' => '2001-03-12']]],
        ]])]);

        Livewire::test(StorageFiles::class)
            ->call('startEdit', $file->id)
            ->assertSet('cueTitle', 'one more time')
            ->set('cueArtist', 'Daft Punk')
            ->call('suggest')
            ->assertSee('data-test="candidate-musicbrainz-0"', false)
            ->call('useSuggestion', 'musicbrainz', 0)
            ->assertSet('tags.title', 'One More Time')
            ->assertSet('tags.artist', 'Daft Punk')
            ->assertSet('tags.album', 'Discovery')
            ->assertSet('tags.year', '2001');
    }

    public function test_a_musicbrainz_outage_is_shown_not_thrown(): void
    {
        $this->actingAs(User::factory()->create());
        $location = StorageLocation::create(['name' => 'library', 'driver' => 'local', 'role' => 'library', 'config' => ['root' => $this->base.'/library']]);
        file_put_contents($this->base.'/library/x.wav', $this->wav());
        StorageTasks::queue('scan', $location->id);

        Http::fake(['musicbrainz.org/*' => Http::response('busy', 429)]);

        Livewire::test(StorageFiles::class)
            ->call('startEdit', File::firstOrFail()->id)
            ->call('suggest')
            ->assertSee('MusicBrainz did not answer');
    }
}
