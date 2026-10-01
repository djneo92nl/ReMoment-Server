<?php

namespace Tests\Feature;

use App\Domain\Artwork\ArtworkCache;
use App\Domain\Artwork\SdCardExport;
use App\Domain\Artwork\SourceLogo;
use App\Jobs\BuildSdCardExport;
use App\Models\Device;
use App\Models\Media\Album;
use App\Models\Media\Artist;
use App\Models\Media\Track;
use App\Models\Play;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\Support\FakePlayerDriver;
use Tests\TestCase;
use ZipArchive;

/** The SD card artwork zip (docs/architecture/sd-card-export.md). */
class SdCardExportTest extends TestCase
{
    use RefreshDatabase;

    private Device $device;

    private Artist $artist;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
        $this->device = Device::create([
            'ip_address' => '10.0.0.10', 'device_name' => 'Living Room', 'device_brand_name' => 'Test',
            'device_product_type' => 'Speaker', 'device_driver' => FakePlayerDriver::class, 'device_driver_name' => 'Fake',
        ]);
        $this->artist = Artist::create(['name' => 'A', 'source' => 'spotify']);

        // Logos are rendered for real in one test; elsewhere stub their files to keep tests quick.
        foreach (SourceLogo::KEYS as $key) {
            $this->processed(SourceLogo::url($key));
        }
    }

    /** Fake processed files + a complete cache entry for a cover URL. */
    private function processed(string $url): string
    {
        $hash = md5($url);
        foreach (['320.jpg', '120.jpg', '512.jpg', 'bg_1024x600.jpg', 'bg_320x480.jpg'] as $file) {
            Storage::disk('public')->put("artwork/{$hash}/{$file}", "{$file} of {$url}");
        }
        ArtworkCache::put($url, array_merge(array_fill_keys(ArtworkCache::REQUIRED_KEYS, '/storage/x.jpg'), ['colors' => ['#111111'], 'safe_colors' => ['#999999']]));

        return $hash;
    }

    private function playedAlbum(string $url, string $at): Album
    {
        $album = Album::create(['artist_id' => $this->artist->id, 'name' => $url, 'source' => 'spotify', 'images' => [['url' => $url]]]);
        $track = Track::create(['album_id' => $album->id, 'artist_id' => $this->artist->id, 'name' => 'T', 'source' => 'spotify']);
        Play::create(['device_id' => $this->device->id, 'track_id' => $track->id, 'source_type' => 'spotify', 'played_at' => $at]);

        return $album;
    }

    private function openZip(string $size = '1024x600'): ZipArchive
    {
        $zip = new ZipArchive;
        $this->assertTrue($zip->open(Storage::disk('local')->path(SdCardExport::zipPath($size))));

        return $zip;
    }

    /** @return array<int, string> */
    private function entries(ZipArchive $zip): array
    {
        $names = [];
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $names[] = $zip->getNameIndex($i);
        }
        sort($names);

        return $names;
    }

    public function test_zip_has_the_exact_client_sd_layout_with_byte_copies(): void
    {
        $recent = $this->processed('https://x.test/recent.jpg');
        $this->playedAlbum('https://x.test/recent.jpg', '2026-09-02 10:00:00');
        $this->playedAlbum('https://x.test/unprocessed.jpg', '2026-09-01 10:00:00');
        $this->processed('https://x.test/never-played.jpg');
        Album::create(['artist_id' => $this->artist->id, 'name' => 'Never played', 'source' => 'spotify', 'images' => [['url' => 'https://x.test/never-played.jpg']]]);

        $meta = (new BuildSdCardExport)->handle();

        $zip = $this->openZip();
        $expected = ['README.txt'];
        foreach ([$recent, ...array_map(fn (string $key) => md5(SourceLogo::url($key)), SourceLogo::KEYS)] as $hash) {
            $expected[] = "remoment/covers/{$hash}.jpg";
            $expected[] = "remoment/backgrounds/{$hash}.jpg";
        }
        sort($expected);
        $this->assertSame($expected, $this->entries($zip));

        $this->assertSame('320.jpg of https://x.test/recent.jpg', $zip->getFromName("remoment/covers/{$recent}.jpg"));
        $this->assertSame('bg_1024x600.jpg of https://x.test/recent.jpg', $zip->getFromName("remoment/backgrounds/{$recent}.jpg"));
        $this->assertSame(ZipArchive::CM_STORE, $zip->statName("remoment/covers/{$recent}.jpg")['comp_method']);
        $this->assertStringContainsString('ROOT of the SD card', $zip->getFromName('README.txt'));

        $this->assertStringContainsString('1024x600 blurred background', $zip->getFromName('README.txt'));

        $this->assertSame(['albums' => 1, 'logos' => 8, 'skipped' => 1, 'limit' => 500, 'size' => '1024x600'], array_intersect_key($meta, array_flip(['albums', 'logos', 'skipped', 'limit', 'size'])));
        $this->assertSame($meta, SdCardExport::meta());
        $this->assertSame('exports/remoment-sd-artwork-1024x600.zip', SdCardExport::zipPath());
        $this->assertSame(filesize(Storage::disk('local')->path(SdCardExport::zipPath())), $meta['bytes']);
    }

    public function test_the_portrait_size_puts_its_backgrounds_in_the_same_folder_beside_the_landscape_zip(): void
    {
        $recent = $this->processed('https://x.test/recent.jpg');
        $this->playedAlbum('https://x.test/recent.jpg', '2026-09-02 10:00:00');

        $this->artisan('artwork:export-sd')->assertSuccessful();
        $this->artisan('artwork:export-sd', ['--size' => '320x480'])->assertSuccessful();

        $portrait = $this->openZip('320x480');
        $this->assertSame('bg_320x480.jpg of https://x.test/recent.jpg', $portrait->getFromName("remoment/backgrounds/{$recent}.jpg"));
        $this->assertSame('320.jpg of https://x.test/recent.jpg', $portrait->getFromName("remoment/covers/{$recent}.jpg"));
        $this->assertStringContainsString('320x480 blurred background', $portrait->getFromName('README.txt'));
        $this->assertSame('bg_1024x600.jpg of https://x.test/recent.jpg', $this->openZip()->getFromName("remoment/backgrounds/{$recent}.jpg"));

        $this->assertSame('320x480', SdCardExport::meta('320x480')['size']);
        $this->assertSame('1024x600', SdCardExport::meta()['size']);
    }

    public function test_a_cover_without_the_portrait_background_is_skipped_in_that_size_only(): void
    {
        $hash = $this->processed('https://x.test/old.jpg');
        Storage::disk('public')->delete("artwork/{$hash}/bg_320x480.jpg");
        $this->playedAlbum('https://x.test/old.jpg', '2026-09-02 10:00:00');

        $this->assertSame(1, (new BuildSdCardExport(null, '320x480'))->handle()['skipped']);
        $this->assertSame(0, (new BuildSdCardExport)->handle()['skipped']);
    }

    public function test_an_unknown_size_is_rejected(): void
    {
        Queue::fake();

        $this->artisan('artwork:export-sd', ['--size' => '800x480'])->assertFailed();

        Queue::assertNothingPushed();
        $this->assertNull(SdCardExport::meta('800x480'));
    }

    public function test_it_takes_only_the_last_n_albums(): void
    {
        $older = $this->processed('https://x.test/older.jpg');
        $newer = $this->processed('https://x.test/newer.jpg');
        $this->playedAlbum('https://x.test/older.jpg', '2026-09-01 10:00:00');
        $this->playedAlbum('https://x.test/newer.jpg', '2026-09-02 10:00:00');

        $this->artisan('artwork:export-sd', ['--limit' => 1])->assertSuccessful();

        $entries = $this->entries($this->openZip());
        $this->assertContains("remoment/covers/{$newer}.jpg", $entries);
        $this->assertNotContains("remoment/covers/{$older}.jpg", $entries);
    }

    public function test_missing_logos_are_rendered_into_the_zip(): void
    {
        foreach (SourceLogo::KEYS as $key) {
            ArtworkCache::forget(SourceLogo::url($key));
            Storage::disk('public')->deleteDirectory('artwork/'.md5(SourceLogo::url($key)));
        }

        (new BuildSdCardExport)->handle();

        $cover = $this->openZip()->getFromName('remoment/covers/'.md5(SourceLogo::url('line_in')).'.jpg');
        $this->assertSame([320, 320], array_slice(getimagesizefromstring($cover), 0, 2));
    }

    public function test_the_command_can_queue_the_build(): void
    {
        Queue::fake();

        $this->artisan('artwork:export-sd', ['--queue' => true])->assertSuccessful();

        Queue::assertPushed(BuildSdCardExport::class, fn (BuildSdCardExport $job) => $job->size === '1024x600');
        $this->assertNotNull(SdCardExport::pendingSince());

        $this->artisan('artwork:export-sd', ['--queue' => true, '--size' => '320x480'])->assertSuccessful();
        Queue::assertPushed(BuildSdCardExport::class, fn (BuildSdCardExport $job) => $job->size === '320x480');
        $this->assertNotNull(SdCardExport::pendingSince('320x480'));
    }

    public function test_admin_can_queue_a_build_and_download_the_zip(): void
    {
        Queue::fake();
        $admin = User::factory()->create();

        $this->actingAs($admin)->get('/settings/clients')->assertOk()->assertSee('Never built');

        $this->actingAs($admin)->post('/settings/clients/artwork-export')->assertRedirect();
        Queue::assertPushed(BuildSdCardExport::class);
        $this->actingAs($admin)->get('/settings/clients')->assertSee('Building');

        (new BuildSdCardExport)->handle();

        $this->actingAs($admin)->get('/settings/clients')
            ->assertOk()
            ->assertSee('Last built')
            ->assertSee('8 logos')
            ->assertDontSee('Building');

        $this->actingAs($admin)->get('/settings/clients/artwork-export/download')
            ->assertOk()
            ->assertDownload('remoment-sd-artwork-1024x600.zip');
        $this->actingAs($admin)->get('/settings/clients/artwork-export/download?size=320x480')->assertNotFound();
    }

    public function test_admin_picks_the_size_and_the_card_shows_each_built_zip(): void
    {
        Queue::fake();
        $admin = User::factory()->create();

        $this->actingAs($admin)->get('/settings/clients')
            ->assertOk()
            ->assertSee('<option value="320x480">', false)
            ->assertSee('3.5" portrait');

        $this->actingAs($admin)->post('/settings/clients/artwork-export', ['size' => '320x480'])->assertRedirect();
        Queue::assertPushed(BuildSdCardExport::class, fn (BuildSdCardExport $job) => $job->size === '320x480');
        $this->actingAs($admin)->post('/settings/clients/artwork-export', ['size' => 'nope'])->assertSessionHasErrors('size');
        Queue::assertPushed(BuildSdCardExport::class, 1);

        (new BuildSdCardExport(null, '320x480'))->handle();
        (new BuildSdCardExport)->handle();

        $this->actingAs($admin)->get('/settings/clients')
            ->assertOk()
            ->assertSee('artwork-export/download?size=320x480', false)
            ->assertSee('artwork-export/download?size=1024x600', false)
            ->assertDontSee('Building');

        $this->actingAs($admin)->get('/settings/clients/artwork-export/download?size=320x480')
            ->assertOk()
            ->assertDownload('remoment-sd-artwork-320x480.zip');
    }

    public function test_builds_of_different_sizes_are_unique_per_size(): void
    {
        $this->assertNotSame((new BuildSdCardExport)->uniqueId(), (new BuildSdCardExport(null, '320x480'))->uniqueId());
    }

    public function test_download_is_404_before_the_first_build(): void
    {
        $this->actingAs(User::factory()->create())->get('/settings/clients/artwork-export/download')->assertNotFound();
    }

    public function test_guests_cannot_build_or_download(): void
    {
        Queue::fake();

        $this->post('/settings/clients/artwork-export')->assertRedirect('/login');
        $this->get('/settings/clients/artwork-export/download')->assertRedirect('/login');
        Queue::assertNothingPushed();
    }
}
