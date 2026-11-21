<?php

namespace Tests\Feature\Library;

use App\Domain\Health\LibraryReports;
use App\Domain\Health\Suggestions;
use App\Domain\Library\LeadingSource;
use App\Domain\Storage\LibraryFiles;
use App\Jobs\EnrichTrackMusicBrainz;
use App\Jobs\ReplaceCoverFromArchive;
use App\Livewire\TagSuggestions;
use App\Mcp\Servers\ReMomentServer;
use App\Mcp\Tools;
use App\Models\AdminToken;
use App\Models\File;
use App\Models\FileAnalysis;
use App\Models\Media\Album;
use App\Models\Media\Artist;
use App\Models\Media\Metadata;
use App\Models\Media\Track;
use App\Models\Setting;
use App\Models\StorageLocation;
use App\Models\TrackFile;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;
use Tests\Support\MakesLibraryTracks;
use Tests\Support\SignsInAsAdmin;
use Tests\TestCase;

/** Tag and cover suggestions (roadmap 12.4): the `suggestions` health report, accept and dismiss. */
class TagSuggestionsTest extends TestCase
{
    use MakesLibraryTracks;
    use RefreshDatabase;
    use SignsInAsAdmin;

    private const RECORDING = '11111111-1111-1111-1111-111111111111';

    private Album $album;

    private int $n = 0;

    protected function setUp(): void
    {
        parent::setUp();

        Setting::set(LeadingSource::SETTING, 'all');
        LibraryReports::forget();
        $this->album = Album::create(['artist_id' => Artist::create(['name' => 'Band', 'source' => 'files'])->id, 'name' => 'LP', 'source' => 'files']);
    }

    /** A file with an AcoustID match whose MusicBrainz facts are given (the recording the job used is the same one). */
    private function track(string $name, array $mb, ?string $acoustid = self::RECORDING, ?Album $album = null): array
    {
        $location = StorageLocation::first() ?? StorageLocation::create(['name' => 'Music', 'driver' => 'local', 'config' => ['root' => '/tmp'], 'role' => 'library']);
        $track = $this->makeLibraryTrack($album ?? $this->album, $name);
        $file = File::create([
            'storage_location_id' => $location->id, 'path' => "{$name}.flac", 'hash' => $name.++$this->n, 'size' => 1, 'mtime' => 1,
            'status' => File::PRESENT, 'extension' => 'flac',
        ]);
        TrackFile::create(['track_id' => $track->id, 'file_id' => $file->id, 'role' => TrackFile::ORIGINAL, 'is_primary' => true]);
        FileAnalysis::create(['file_id' => $file->id, 'content_hash' => $file->hash, 'acoustid_recording_mbid' => $acoustid]);

        foreach (['mbid' => self::RECORDING] + $mb as $key => $value) {
            Metadata::create(['metadatable_type' => Track::class, 'metadatable_id' => $track->id, 'key' => $key, 'value' => $value, 'type' => 'string', 'source' => 'musicbrainz']);
        }

        return [$file, $track];
    }

    public function test_a_file_that_disagrees_with_the_fingerprinted_recording_gets_suggestions(): void
    {
        [, $track] = $this->track('dont stop', ['mb_title' => "Don't Stop", 'mb_artist' => 'Band', 'mb_year' => '1977']);

        $all = Suggestions::all();

        $this->assertSame(['title:'.$track->id, 'year:'.$track->id], array_column($all, 'id'));
        $this->assertSame(['dont stop', "Don't Stop", true], [$all[0]['current'], $all[0]['suggested'], $all[0]['can_apply']]);
        $this->assertSame([null, '1977'], [$all[1]['current'], $all[1]['suggested']]);
        $this->assertSame(2, LibraryReports::count('suggestions'));
    }

    public function test_agreeing_tags_and_guessed_recordings_give_nothing(): void
    {
        $this->track('Same', ['mb_title' => 'Same', 'mb_artist' => 'The Band']);
        // The job found this recording by name, the fingerprint says another one: not a confirmed match.
        $this->track('Guess', ['mb_title' => 'Other'], acoustid: '22222222-2222-2222-2222-222222222222');
        // No fingerprint at all.
        $this->track('Unprinted', ['mb_title' => 'Other'], acoustid: null);
        // A year is not suggested over an album year that exists.
        $this->album->update(['released_at' => '1999-01-01']);
        $this->track('Dated', ['mb_title' => 'Dated', 'mb_year' => '1977']);

        $this->assertSame([], Suggestions::all());
    }

    public function test_another_artist_is_suggested_but_a_the_prefix_is_not(): void
    {
        [, $track] = $this->track('Song', ['mb_title' => 'Song', 'mb_artist' => 'Other Band']);

        $this->assertSame(['artist:'.$track->id], array_column(Suggestions::all(), 'id'));
    }

    public function test_the_api_lists_them_as_a_report_with_the_suggestion_in_each_finding(): void
    {
        [, $track] = $this->track('Song', ['mb_title' => 'Song!']);

        $r = $this->getJson('/api/library/health/suggestions')->assertOk()->json();

        $this->assertSame(1, $r['count']);
        $this->assertSame('tag', $r['data'][0]['kind']);
        $this->assertSame($track->id, $r['data'][0]['track_id']);
        $this->assertSame(['id' => 'title:'.$track->id, 'kind' => 'tag', 'field' => 'title', 'current' => 'Song', 'suggested' => 'Song!', 'can_apply' => true, 'source' => 'musicbrainz', 'file_id' => $r['data'][0]['suggestion']['file_id']], $r['data'][0]['suggestion']);
        $this->assertNull($this->getJson('/api/library/health/transcodes')->json('data.0.suggestion'));
    }

    public function test_accepting_writes_the_tag_through_the_tag_editor_and_needs_the_admin_token(): void
    {
        [$file, $track] = $this->track('Song', ['mb_title' => 'Song!']);
        $id = 'title:'.$track->id;

        $this->postJson("/api/admin/suggestions/{$id}/accept")->assertStatus(401);

        $this->mock(LibraryFiles::class, fn ($m) => $m->shouldReceive('retag')->once()
            ->withArgs(fn (File $f, array $tags) => $f->id === $file->id && $tags === ['title' => 'Song!']));

        $this->postJson("/api/admin/suggestions/{$id}/accept", [], $this->adminHeaders())->assertOk()->assertJson(['status' => 'applied', 'id' => $id]);
    }

    public function test_a_failing_tag_writer_is_a_502_and_an_unknown_suggestion_a_422(): void
    {
        [, $track] = $this->track('Song', ['mb_title' => 'Song!']);
        $this->mock(LibraryFiles::class, fn ($m) => $m->shouldReceive('retag')->andThrow(new \RuntimeException('metaflac is not installed')));

        $this->postJson('/api/admin/suggestions/title:'.$track->id.'/accept', [], $this->adminHeaders())->assertStatus(502)->assertJsonPath('error', 'storage_error');
        $this->postJson('/api/admin/suggestions/title:999/accept', [], $this->adminHeaders())->assertStatus(422);
    }

    public function test_dismissing_hides_the_value_and_a_new_one_comes_back(): void
    {
        [, $track] = $this->track('Song', ['mb_title' => 'Song!']);

        $this->postJson('/api/admin/suggestions/title:'.$track->id.'/dismiss', [], $this->adminHeaders())->assertOk()->assertJson(['status' => 'dismissed']);
        $this->assertSame([], Suggestions::all());
        $this->assertSame(0, $this->getJson('/api/library/health/suggestions')->json('count'));

        Metadata::where('metadatable_id', $track->id)->where('key', 'mb_title')->update(['value' => 'Song?']);
        $this->assertSame(['title:'.$track->id], array_column(Suggestions::all(), 'id'));
    }

    public function test_a_poor_cover_with_a_release_gets_a_cover_suggestion_that_queues_the_archive_fetch(): void
    {
        Queue::fake();
        $this->track('Song', ['mb_title' => 'Song']);
        Metadata::create(['metadatable_type' => Album::class, 'metadatable_id' => $this->album->id, 'key' => 'mbid', 'value' => 'rel-1', 'type' => 'string', 'source' => 'musicbrainz']);

        $s = collect(Suggestions::all())->firstWhere('kind', 'cover');
        $this->assertSame('cover:'.$this->album->id, $s['id']);

        $this->postJson('/api/admin/suggestions/'.$s['id'].'/accept', [], $this->adminHeaders())->assertOk()->assertJson(['status' => 'queued']);
        Queue::assertPushed(ReplaceCoverFromArchive::class, fn ($job) => $job->album->id === $this->album->id);
    }

    public function test_the_archive_job_sets_the_front_image_and_leaves_an_album_without_one_alone(): void
    {
        Queue::fake();
        Metadata::create(['metadatable_type' => Album::class, 'metadatable_id' => $this->album->id, 'key' => 'mbid', 'value' => 'rel-1', 'type' => 'string', 'source' => 'musicbrainz']);
        Http::fake(['coverartarchive.org/release/rel-1' => Http::sequence()
            ->push(['images' => [['front' => true, 'image' => 'http://caa.test/front.jpg', 'thumbnails' => ['1200' => 'http://caa.test/front-1200.jpg']]]])
            ->push([], 404)]);

        (new ReplaceCoverFromArchive($this->album))->handle();
        $this->assertSame('https://caa.test/front-1200.jpg', $this->album->fresh()->images[0]['url']);

        $this->album->update(['images' => [['url' => 'https://keep.test/a.jpg']]]);
        (new ReplaceCoverFromArchive($this->album))->handle();
        $this->assertSame('https://keep.test/a.jpg', $this->album->fresh()->images[0]['url']);
    }

    public function test_inconsistent_track_numbers_in_an_album_are_reported_but_not_applicable(): void
    {
        foreach (['A' => '1', 'B' => '1', 'C' => null] as $name => $number) {
            [, $track] = $this->track($name, []);
            $number && Metadata::create(['metadatable_type' => Track::class, 'metadatable_id' => $track->id, 'key' => 'track_number', 'value' => $number, 'type' => 'int', 'source' => 'files']);
        }

        $s = collect(Suggestions::all())->firstWhere('kind', 'album');
        $this->assertFalse($s['can_apply']);
        $this->assertStringContainsString('1 of 3 tracks have no track number', $s['reason']);
        $this->assertStringContainsString('track number 1 is used more than once', $s['reason']);

        $this->postJson('/api/admin/suggestions/'.$s['id'].'/accept', [], $this->adminHeaders())->assertStatus(422);
    }

    public function test_the_job_keeps_what_musicbrainz_calls_the_recording(): void
    {
        Queue::fake();
        Http::fake(['musicbrainz.org/ws/2/recording/*' => Http::response([
            'id' => self::RECORDING, 'title' => 'Song', 'first-release-date' => '1977-05-01',
            'artist-credit' => [['name' => 'Band', 'joinphrase' => ' feat. ', 'artist' => ['id' => 'a1', 'name' => 'Band']], ['name' => 'Guest', 'joinphrase' => '', 'artist' => ['id' => 'a2', 'name' => 'Guest']]],
            'releases' => [], 'relations' => [],
        ])]);
        [, $track] = $this->track('Song', []);
        Metadata::where('metadatable_id', $track->id)->delete();
        Metadata::create(['metadatable_type' => Track::class, 'metadatable_id' => $track->id, 'key' => 'mbid', 'value' => self::RECORDING, 'type' => 'string', 'source' => 'acoustid']);

        (new EnrichTrackMusicBrainz($track))->handle();

        $get = fn ($k) => Metadata::where('metadatable_id', $track->id)->where('metadatable_type', Track::class)->where('key', $k)->where('source', 'musicbrainz')->value('value');
        $this->assertSame(['Song', 'Band feat. Guest', '1977'], [$get('mb_title'), $get('mb_artist'), $get('mb_year')]);
    }

    public function test_the_page_lists_suggestions_and_the_component_accepts_and_dismisses(): void
    {
        [$file, $track] = $this->track('Song', ['mb_title' => 'Song!']);
        $this->actingAs(User::factory()->create());

        $this->get('/settings/library-health/suggestions')->assertOk()->assertSee('Song!')->assertSee('Accept');

        $this->mock(LibraryFiles::class, fn ($m) => $m->shouldReceive('retag')->once());
        Livewire::test(TagSuggestions::class)->call('accept', 'title:'.$track->id)->assertSee('Written into the file.');

        Livewire::test(TagSuggestions::class)->call('dismiss', 'title:'.$track->id)->assertSee('Dismissed.')->assertSee('Nothing to review');
    }

    public function test_standard_has_no_report_but_the_accept_route_is_open_for_covers(): void
    {
        config(['profile.current' => 'standard']);

        $this->getJson('/api/library/health/suggestions')->assertStatus(422)->assertJsonPath('error', 'not_available_in_profile');
        $this->postJson('/api/admin/suggestions/cover:1/accept', [], $this->adminHeaders())->assertStatus(422)->assertJsonPath('error', 'invalid');

        config(['profile.current' => 'control']);
        $this->postJson('/api/admin/suggestions/cover:1/accept', [], $this->adminHeaders())->assertStatus(422)->assertJsonPath('error', 'not_available_in_profile');
    }

    public function test_the_mcp_tool_lists_through_library_health_and_acts_only_for_the_admin(): void
    {
        [, $track] = $this->track('Song', ['mb_title' => 'Song!']);

        ReMomentServer::tool(Tools\LibraryHealth::class, ['report' => 'suggestions'])->assertOk()->assertSee('title:'.$track->id);
        ReMomentServer::tool(Tools\LibrarySuggestions::class, ['action' => 'dismiss', 'id' => 'title:'.$track->id])->assertNotRegistered();

        User::factory()->create();
        [, $plain] = AdminToken::issue('test');
        request()->headers->set('Authorization', 'Bearer '.$plain);

        ReMomentServer::tool(Tools\LibrarySuggestions::class, ['action' => 'accept', 'id' => 'title:'.$track->id])->assertHasErrors(['confirmation_required']);
        ReMomentServer::tool(Tools\LibrarySuggestions::class, ['action' => 'dismiss', 'id' => 'title:'.$track->id])->assertOk()->assertSee('dismissed');
        $this->assertSame([], Suggestions::all());
    }
}
