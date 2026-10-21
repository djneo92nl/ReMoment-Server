<?php

namespace Tests\Feature;

use App\Domain\Analysis\Tools;
use App\Domain\Device\DeviceCache;
use App\Domain\Device\State;
use App\Domain\Library\Enrichment;
use App\Domain\Storage\FileIndexer;
use App\Domain\Storage\Proxies;
use App\Integrations\ReMoment\PlayerDevice;
use App\Jobs\TranscodeProxy;
use App\Models\Device;
use App\Models\File;
use App\Models\Media\Album;
use App\Models\Media\Artist;
use App\Models\Media\Track;
use App\Models\StorageLocation;
use App\Models\TrackFile;
use App\Services\MqttService;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Process\PendingProcess;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/** Players that cannot decode the original (retro handhelds) are sent a proxy: a small MP3 made on demand. */
class PlayerProxyTest extends TestCase
{
    use RefreshDatabase;

    private string $dir;

    protected function setUp(): void
    {
        parent::setUp();

        $this->dir = sys_get_temp_dir().'/remoment-proxy-test-'.uniqid();
        mkdir($this->dir);
        Tools::forget();
        Storage::fake('local');

        // ffmpeg "converts" by writing its last argument.
        Process::fake(function (PendingProcess $process) {
            $command = (array) $process->command;
            $target = end($command);
            if (is_string($target) && str_ends_with($target, '.mp3')) {
                file_put_contents($target, 'MP3DATA');
            }

            return Process::result('ffmpeg version 7.0');
        });
    }

    protected function tearDown(): void
    {
        (new Filesystem)->deleteDirectory($this->dir);
        Tools::forget();

        parent::tearDown();
    }

    private function register(array $extra = []): array
    {
        return $this->postJson('/api/players/register', ['name' => 'Treo 650', 'kind' => 'retro'] + $extra)->json();
    }

    /** @return array{0: File, 1: Track} a FLAC file of the server's own with its track */
    private function flacTrack(string $name = 'Song', string $hash = 'abc123def456'): array
    {
        $location = StorageLocation::first() ?? StorageLocation::create(['name' => 'Music', 'driver' => 'local', 'config' => ['root' => $this->dir], 'role' => 'library']);
        $artist = Artist::firstOrCreate(['name' => 'Band'], ['source' => 'dlna']);
        $album = Album::firstOrCreate(['artist_id' => $artist->id, 'name' => 'LP'], ['source' => 'dlna']);

        file_put_contents("{$this->dir}/{$name}.flac", 'not really audio');
        $file = File::create([
            'storage_location_id' => $location->id, 'path' => "{$name}.flac", 'hash' => $hash, 'size' => 16, 'mtime' => 1,
            'status' => File::PRESENT, 'extension' => 'flac', 'mime_type' => 'audio/flac',
        ]);
        $track = Track::create(['artist_id' => $artist->id, 'album_id' => $album->id, 'name' => $name, 'duration' => 200, 'source' => 'dlna', 'external_id' => "local:file:{$file->id}"]);
        TrackFile::create(['track_id' => $track->id, 'file_id' => $file->id, 'role' => TrackFile::ORIGINAL, 'is_primary' => true]);
        Enrichment::save($track, 'dlna_url', url('/stream/'.$track->id), 'url', FileIndexer::URL_SOURCE);

        return [$file, $track];
    }

    /** @return array<int, array<string, mixed>> the commands published for a player, oldest first */
    private function commands(int $deviceId): array
    {
        return collect(app(MqttService::class)->published)
            ->where('topic', "remoment/playercmd/{$deviceId}")
            ->map(fn ($message) => json_decode($message['message'], true))
            ->values()
            ->all();
    }

    public function test_retro_player_registers_with_a_profile_and_unknown_profiles_are_ignored(): void
    {
        $retro = $this->register(['formats' => ['audio/mpeg'], 'profile' => 'mp3-lo']);
        $other = $this->register(['profile' => 'warp-9']);

        $this->assertSame('retro', PlayerDevice::kind(Device::find($retro['device_id'])));
        $this->assertSame('mp3-lo', PlayerDevice::profile(Device::find($retro['device_id'])));
        $this->assertNull(PlayerDevice::profile(Device::find($other['device_id'])));
    }

    public function test_source_is_the_original_a_matching_profile_or_nothing(): void
    {
        $web = Device::find($this->register(['kind' => 'web'])['device_id']);
        $mp3Only = Device::find($this->register(['formats' => ['audio/mpeg']])['device_id']);
        $wavOnly = Device::find($this->register(['formats' => ['audio/x-wav']])['device_id']);
        $lo = Device::find($this->register(['formats' => ['audio/flac', 'audio/mpeg'], 'profile' => 'mp3-lo'])['device_id']);

        $this->assertNull(PlayerDevice::source($web, 'audio/flac'));
        $this->assertNull(PlayerDevice::source($web, null));
        $this->assertSame('mp3-lo', PlayerDevice::source($mp3Only, 'audio/flac'));
        $this->assertNull(PlayerDevice::source($mp3Only, 'audio/mpeg'));
        $this->assertFalse(PlayerDevice::source($wavOnly, 'audio/flac'));
        $this->assertSame('mp3-lo', PlayerDevice::source($lo, 'audio/flac')); // asked for: used even when it could play the original
    }

    public function test_play_waits_for_the_proxy_and_is_sent_when_it_is_made(): void
    {
        Queue::fake();
        [$file, $track] = $this->flacTrack();
        $id = $this->register(['formats' => ['audio/mpeg'], 'profile' => 'mp3-lo'])['device_id'];
        DeviceCache::updateState($id, State::Standby);

        Device::find($id)->driver->playLibraryTracks(collect([$track]));

        $this->assertSame(['pause'], array_column($this->commands($id), 'op'));
        Queue::assertPushed(TranscodeProxy::class, fn (TranscodeProxy $job) => $job->file->is($file) && $job->profile === 'mp3-lo');

        (new TranscodeProxy($file, 'mp3-lo'))->handle();

        $play = $this->commands($id)[1];
        $this->assertSame('play', $play['op']);
        $this->assertSame("/stream/{$track->id}?profile=mp3-lo", $play['track']['url']);
        $this->assertSame('mp3-lo', $play['track']['profile']);
        $this->assertSame('audio/mpeg', $play['track']['mime']);
        Storage::disk('local')->assertExists('proxies/mp3-lo/abc123def456.mp3');
    }

    public function test_play_is_immediate_when_the_proxy_exists_and_stream_serves_it(): void
    {
        Queue::fake();
        [, $track] = $this->flacTrack();
        Storage::disk('local')->put('proxies/mp3-lo/abc123def456.mp3', 'MP3DATA');
        $id = $this->register(['formats' => ['audio/mpeg']])['device_id'];

        Device::find($id)->driver->playLibraryTracks(collect([$track]));

        $this->assertSame(['play'], array_column($this->commands($id), 'op'));
        Queue::assertNothingPushed();

        $response = $this->get("/stream/{$track->id}?profile=mp3-lo");
        $response->assertOk();
        $this->assertSame('audio/mpeg', $response->headers->get('Content-Type'));
        $this->assertSame('MP3DATA', file_get_contents($response->baseResponse->getFile()->getPathname()));
    }

    public function test_stream_without_a_ready_proxy_sends_the_original_and_queues_the_proxy(): void
    {
        Queue::fake();
        [, $track] = $this->flacTrack();

        $this->get("/stream/{$track->id}?profile=mp3-mid")->assertOk();

        Queue::assertPushed(TranscodeProxy::class, fn (TranscodeProxy $job) => $job->profile === 'mp3-mid');
        $this->get("/stream/{$track->id}?profile=nonsense")->assertOk();
        Queue::assertPushed(TranscodeProxy::class, 1);
    }

    public function test_failed_conversion_stops_the_player_instead_of_sending_a_format_it_cannot_play(): void
    {
        Queue::fake();
        [$file, $track] = $this->flacTrack();
        $id = $this->register(['formats' => ['audio/mpeg']])['device_id'];
        Device::find($id)->driver->playLibraryTracks(collect([$track]));

        Proxies::settle($file, 'mp3-lo', false);

        $this->assertSame(['pause', 'stop'], array_column($this->commands($id), 'op'));
    }

    public function test_prune_removes_the_oldest_proxies_over_the_limit(): void
    {
        config(['proxies.max_mb' => 1]);
        $disk = Storage::disk('local');
        $disk->put('proxies/mp3-lo/old.mp3', str_repeat('a', 700 * 1024));
        touch($disk->path('proxies/mp3-lo/old.mp3'), time() - 100);
        $disk->put('proxies/mp3-lo/new.mp3', str_repeat('b', 700 * 1024));

        $this->assertSame(1, Proxies::prune());
        $disk->assertMissing('proxies/mp3-lo/old.mp3');
        $disk->assertExists('proxies/mp3-lo/new.mp3');
    }
}
