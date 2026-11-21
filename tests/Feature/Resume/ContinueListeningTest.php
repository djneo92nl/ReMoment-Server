<?php

namespace Tests\Feature\Resume;

use App\Domain\Library\LibraryIdentity;
use App\Domain\Resume\PlaybackPositions;
use App\Livewire\ContinueListening;
use App\Models\Device;
use App\Models\Media\Metadata;
use App\Models\Media\Track;
use App\Support\SelectedDevice;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;
use Tests\Support\FakeSeekingLibraryDriver;
use Tests\TestCase;

/** The "Continue listening" block of the library page (docs/frontend/continue-listening.md). */
class ContinueListeningTest extends TestCase
{
    use RefreshDatabase;

    private function bookmarked(): Track
    {
        $artist = LibraryIdentity::artist('Rob Inglis', 'files');
        $album = LibraryIdentity::album($artist, 'The Hobbit', 'files');
        $track = LibraryIdentity::track($artist, $album, 'Chapter 3', 'local:file:1', 'files', ['duration' => 3600]);
        Metadata::create(['metadatable_type' => Track::class, 'metadatable_id' => $track->id, 'key' => 'dlna_url', 'value' => 'http://nas.test/1.mp3', 'type' => 'url', 'source' => 'files']);
        PlaybackPositions::set('track', $track->id, 1800, 3600);

        return $track;
    }

    public function test_nothing_is_rendered_without_bookmarks(): void
    {
        Livewire::test(ContinueListening::class)->assertDontSee('Continue listening');
    }

    public function test_a_bookmark_shows_with_its_progress_and_the_library_page_includes_the_block(): void
    {
        $this->bookmarked();

        Livewire::test(ContinueListening::class)
            ->assertSee('Continue listening')->assertSee('Chapter 3')->assertSee('Rob Inglis')->assertSee('30:00')->assertSee('30:00 left');

        $this->get(route('library.index'))->assertOk()->assertSeeHtml('data-test="continue-listening"')->assertSee('Chapter 3');
    }

    public function test_resume_plays_on_the_pinned_player_and_schedules_the_seek(): void
    {
        Queue::fake();
        FakeSeekingLibraryDriver::reset();
        $track = $this->bookmarked();
        $device = Device::factory()->driver(FakeSeekingLibraryDriver::class)->create();

        Livewire::withCookie(SelectedDevice::COOKIE, (string) $device->id);
        Livewire::test(ContinueListening::class)->call('resume', 'track', $track->id)->assertSet('notice', null);

        $this->assertSame('playLibraryTrack', FakeSeekingLibraryDriver::$calls[0]['method']);
        Queue::assertPushed(\App\Jobs\ResumePlayback::class, fn ($job) => $job->position === 1800);
    }

    public function test_resume_without_a_pinned_player_says_so_and_clear_forgets(): void
    {
        $track = $this->bookmarked();

        Livewire::test(ContinueListening::class)->call('resume', 'track', $track->id)->assertSet('notice', 'Pick a player in the bar below first.')
            ->call('clear', 'track', $track->id)->assertDontSee('Chapter 3');

        $this->assertDatabaseCount('playback_positions', 0);
    }
}
