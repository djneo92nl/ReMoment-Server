<?php

namespace Tests\Feature;

use App\Domain\Analysis\Tools;
use App\Domain\Export\ExportRetention;
use App\Jobs\ExportLibrarySelection;
use App\Livewire\ExportDialog;
use App\Livewire\ExportList;
use App\Models\Export;
use App\Models\File;
use App\Models\Media\Album;
use App\Models\Media\Artist;
use App\Models\Media\Track;
use App\Models\StorageLocation;
use App\Models\TrackFile;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Tests\TestCase;

/** The export web dialog and list (13.4) and retention (13.5). */
class ExportDialogTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        Tools::forget();
        config(['analysis.ffmpeg' => '/bin/echo', 'export.nice' => 0]);
    }

    protected function tearDown(): void
    {
        Tools::forget();
        parent::tearDown();
    }

    private function album(): Album
    {
        $artist = Artist::create(['name' => 'Band']);
        $album = Album::create(['artist_id' => $artist->id, 'name' => 'LP']);
        $location = StorageLocation::create(['name' => 'Music', 'driver' => 'local', 'config' => ['root' => sys_get_temp_dir()], 'role' => 'library']);
        foreach (['One' => 'mp3', 'Two' => 'flac', 'Streamed' => null] as $name => $codec) {
            $t = Track::create(['album_id' => $album->id, 'artist_id' => $artist->id, 'external_id' => uniqid('local:'), 'name' => $name, 'duration' => 200, 'source' => 'files']);
            if ($codec) {
                $f = File::create(['storage_location_id' => $location->id, 'path' => uniqid().'.'.$codec, 'hash' => uniqid(), 'size' => 4000, 'mtime' => 1, 'extension' => $codec, 'codec' => $codec, 'bitrate' => $codec === 'mp3' ? 128 : 900, 'sample_rate' => 44100, 'channels' => 2, 'duration' => 200, 'status' => File::PRESENT]);
                TrackFile::create(['track_id' => $t->id, 'file_id' => $f->id, 'role' => TrackFile::ORIGINAL, 'is_primary' => true]);
            }
        }

        return $album;
    }

    public function test_a_guest_sees_no_button_and_cannot_act(): void
    {
        $album = $this->album();

        Livewire::test(ExportDialog::class, ['type' => 'album', 'id' => $album->id])->assertDontSee('Export');
        Livewire::test(ExportDialog::class, ['type' => 'album', 'id' => $album->id])->call('toggle')->assertForbidden();
        Livewire::test(ExportDialog::class, ['type' => 'album', 'id' => $album->id])->call('start')->assertForbidden();
        $this->assertSame(0, Export::count());
    }

    public function test_estimate_start_progress_download_and_cancel(): void
    {
        Queue::fake();
        $album = $this->album();
        $this->actingAs(User::factory()->create());

        $c = Livewire::test(ExportDialog::class, ['type' => 'album', 'id' => $album->id])
            ->assertSee('Export')->call('toggle')
            ->assertSeeHtml('data-test="export-estimate"')->assertSee('2 of 3 tracks can be exported')
            ->assertSeeText('1 converted to MP3')->assertSee('Skipped tracks')->assertSee('Streamed');
        $this->assertSame(0, Export::count());

        $c->set('profile', 'highest')->assertSeeText('0 converted to MP3');
        $c->set('profile', 'mp3-320')->call('start');
        Queue::assertPushed(ExportLibrarySelection::class);
        $export = Export::first();
        $this->assertSame('queued', $export->status);
        $c->assertSee('Queued')->assertSeeHtml('data-test="cancel"');

        $export->forceFill(['status' => 'running', 'files_done' => 1])->save();
        $c->call('$refresh')->assertSeeText('1 of 2 files');

        $disk = Storage::disk('local');
        $disk->put($export->directory().'/LP.zip', 'zip');
        $export->forceFill(['status' => 'done', 'part_files' => [['name' => 'LP.zip', 'size' => 3, 'files' => 2]], 'expires_at' => now()->addDay()])->save();
        $c->call('$refresh')->assertSeeHtml('data-test="download-1"')->assertSeeHtml('signature=');

        $c->call('cancelExport', $export->public_id);
        $this->assertSame(0, Export::count());
        $disk->assertMissing($export->directory().'/LP.zip');
    }

    public function test_cancel_of_a_queued_export_and_refusal(): void
    {
        Queue::fake();
        $this->actingAs(User::factory()->create());
        $album = $this->album();

        config(['export.max_gb' => 0.000001]);
        Livewire::test(ExportDialog::class, ['type' => 'album', 'id' => $album->id])->call('toggle')->call('start')
            ->assertSee('over the limit');
        $this->assertSame(0, Export::count());
    }

    public function test_the_list_and_the_control_profile(): void
    {
        $this->actingAs(User::factory()->create());
        $export = Export::create(['selection_type' => 'tracks', 'track_ids' => [], 'profile' => 'mp3-320', 'status' => 'failed', 'error' => 'boom']);

        Livewire::test(ExportList::class)->assertSee('boom')->call('cancelExport', $export->public_id)->assertSee('No exports yet');

        config(['profile.current' => 'control']);
        Livewire::test(ExportDialog::class, ['type' => 'album', 'id' => 1])->assertDontSee('Export');
        $this->get('/settings/storage')->assertRedirectContains('/not-in-profile');
    }

    public function test_pages_show_the_button_to_the_admin_only(): void
    {
        $album = $this->album();
        $this->get('/albums/'.$album->id)->assertOk()->assertDontSee('export-toggle');
        $this->actingAs(User::factory()->create());
        $this->get('/albums/'.$album->id)->assertOk()->assertSee('export-toggle', false);
    }

    private function done(string $id, \DateTimeInterface $finished, int $bytes = 1000): Export
    {
        $e = Export::create(['selection_type' => 'tracks', 'track_ids' => [], 'profile' => 'mp3-320', 'status' => 'done', 'finished_at' => $finished, 'expires_at' => (clone \Illuminate\Support\Carbon::instance($finished))->addHours(24), 'part_files' => [['name' => 'a.zip', 'size' => $bytes, 'files' => 1]]]);
        Storage::disk('local')->put($e->directory().'/a.zip', str_repeat('x', $bytes));

        return $e;
    }

    public function test_retention_expires_old_exports_and_forgets_them_later(): void
    {
        $old = $this->done('old', now()->subHours(30));
        $fresh = $this->done('fresh', now()->subHours(2));
        $running = Export::create(['selection_type' => 'tracks', 'track_ids' => [], 'profile' => 'mp3-320', 'status' => 'running']);
        Storage::disk('local')->put($running->directory().'/partial.zip', 'x');

        $r = app(ExportRetention::class)->prune();
        $this->assertSame(1, $r['expired']);
        $this->assertSame('expired', $old->fresh()->status);
        Storage::disk('local')->assertMissing($old->directory().'/a.zip');
        Storage::disk('local')->assertExists($fresh->directory().'/a.zip');
        Storage::disk('local')->assertExists($running->directory().'/partial.zip');

        $old->forceFill(['finished_at' => now()->subHours(40)])->save();
        $this->assertSame(1, app(ExportRetention::class)->prune()['forgotten']);
        $this->assertNull(Export::find($old->id));
        $this->assertNotNull($fresh->fresh());
    }

    public function test_retention_trims_the_directory_oldest_first(): void
    {
        $a = $this->done('a', now()->subHours(5), 600_000);
        $b = $this->done('b', now()->subHours(3), 600_000);
        $c = $this->done('c', now()->subHours(1), 600_000);
        config(['export.dir_max_gb' => 1_300_000 / 1024 ** 3]); // about 1.3 MB cap, 1.8 MB held: the oldest goes

        $r = app(ExportRetention::class)->prune();
        $this->assertSame(1, $r['trimmed']);
        $this->assertSame('expired', $a->fresh()->status);
        $this->assertSame('done', $b->fresh()->status);
        $this->assertSame('done', $c->fresh()->status);
    }

    public function test_retention_removes_orphan_directories_and_the_command_runs(): void
    {
        $disk = Storage::disk('local');
        $disk->put('exports/orphan/a.zip', 'x');
        touch($disk->path('exports/orphan/a.zip'), time() - 7200);
        $disk->put('exports/justnow/a.zip', 'x');

        $this->artisan('export:prune')->assertSuccessful();
        $disk->assertMissing('exports/orphan/a.zip');
        $disk->assertExists('exports/justnow/a.zip');
    }
}
