<?php

namespace Tests\Feature;

use App\Domain\Analysis\Tools;
use App\Domain\Library\Enrichment;
use App\Domain\Storage\FileIndexer;
use App\Domain\Storage\Proxies;
use App\Integrations\Philips\Streamium\MusicPlayerDriver;
use App\Integrations\Upnp\StreamProfile;
use App\Models\Device;
use App\Models\DeviceMeta;
use App\Models\File;
use App\Models\Media\Album;
use App\Models\Media\Artist;
use App\Models\Media\Track;
use App\Models\StorageLocation;
use App\Models\TrackFile;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Process\PendingProcess;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/** A renderer with limits (the Streamium) gets a proxy for a file beyond them, made before it is told to play. */
class UpnpStreamProfileTest extends TestCase
{
    use RefreshDatabase;

    private string $dir;

    /** @var string[] the SetAVTransportURI bodies sent to the renderer */
    private array $sent = [];

    private bool $ffmpegWorks = true;

    protected function setUp(): void
    {
        parent::setUp();

        $this->dir = sys_get_temp_dir().'/remoment-upnp-profile-'.uniqid();
        mkdir($this->dir);
        Tools::forget();
        Storage::fake('local');

        // ffmpeg "converts" by writing its last argument.
        Process::fake(function (PendingProcess $process) {
            $command = (array) $process->command;
            $target = end($command);

            if ($this->ffmpegWorks && is_string($target) && (str_ends_with($target, '.mp3') || str_ends_with($target, '.flac'))) {
                file_put_contents($target, 'AUDIO');

                return Process::result('ffmpeg version 7.0');
            }

            return $this->ffmpegWorks ? Process::result('ffmpeg version 7.0') : Process::result('', 'conversion failed', 1);
        });

        Http::fake(function (Request $r) {
            if (str_contains($r->url(), '/HOMESTATUS')) {
                return Http::response("{'command':'NOTHING','value':0}");
            }

            $action = trim(explode('#', $r->header('SOAPAction')[0] ?? '#')[1] ?? '', '"');
            $action === 'SetAVTransportURI' && $this->sent[] = $r->body();

            return Http::response('<s:Envelope xmlns:s="http://schemas.xmlsoap.org/soap/envelope/"><s:Body><u:'.$action.'Response xmlns:u="x">'
                .($action === 'GetTransportInfo' ? '<CurrentTransportState>NO_MEDIA_PRESENT</CurrentTransportState>' : '')
                .'</u:'.$action.'Response></s:Body></s:Envelope>');
        });
    }

    protected function tearDown(): void
    {
        (new Filesystem)->deleteDirectory($this->dir);
        Tools::forget();

        parent::tearDown();
    }

    private function player(bool $limits = true): Device
    {
        $device = Device::factory()->driver(MusicPlayerDriver::class, 'Streamium')->create(['ip_address' => '10.0.0.80']);

        foreach (['upnp_avtransport_url' => 'http://10.0.0.80:7123/UD/action?2', 'upnp_capabilities' => '["seek","volume_control"]'] as $key => $value) {
            DeviceMeta::create(['device_id' => $device->id, 'key' => $key, 'value' => $value]);
        }

        $limits && StreamProfile::setLimits($device, ['max_bit_depth' => 16, 'max_sample_rate' => 48000, 'deny_codecs' => ['aac']]);

        return $device;
    }

    /** A track of the server's own with a file of this format. */
    private function track(string $extension, string $codec, ?int $bits, ?int $rate, string $hash): Track
    {
        $location = StorageLocation::first() ?? StorageLocation::create(['name' => 'Music', 'driver' => 'local', 'config' => ['root' => $this->dir], 'role' => 'library']);
        $artist = Artist::firstOrCreate(['name' => 'Band'], ['source' => 'dlna']);
        $album = Album::firstOrCreate(['artist_id' => $artist->id, 'name' => 'LP'], ['source' => 'dlna']);

        file_put_contents("{$this->dir}/{$hash}.{$extension}", 'not really audio');
        $file = File::create([
            'storage_location_id' => $location->id, 'path' => "{$hash}.{$extension}", 'hash' => $hash, 'size' => 16, 'mtime' => 1,
            'status' => File::PRESENT, 'extension' => $extension, 'mime_type' => "audio/{$extension}", 'codec' => $codec, 'bit_depth' => $bits, 'sample_rate' => $rate,
        ]);
        $track = Track::create(['artist_id' => $artist->id, 'album_id' => $album->id, 'name' => $hash, 'duration' => 200, 'source' => 'dlna', 'external_id' => "local:file:{$file->id}"]);
        TrackFile::create(['track_id' => $track->id, 'file_id' => $file->id, 'role' => TrackFile::ORIGINAL, 'is_primary' => true]);
        Enrichment::save($track, 'dlna_url', url('/stream/'.$track->id), 'url', FileIndexer::URL_SOURCE);

        return $track;
    }

    public function test_a_renderer_without_limits_always_gets_the_original(): void
    {
        $hires = $this->track('flac', 'flac', 24, 96000, 'aaa111');

        $this->assertNull(StreamProfile::for($this->player(limits: false), $hires));
    }

    public function test_the_profile_follows_the_limits_of_the_player(): void
    {
        $device = $this->player();

        $this->assertNull(StreamProfile::for($device, $this->track('flac', 'flac', 16, 44100, 'cd0001')), 'CD quality plays as it is');
        $this->assertNull(StreamProfile::for($device, $this->track('flac', 'flac', 16, 48000, 'cd0048')), '16-bit/48 kHz plays as it is');
        $this->assertSame('flac-16', StreamProfile::for($device, $this->track('flac', 'flac', 24, 44100, 'hi0024')), '24-bit is too deep');
        $this->assertSame('flac-16', StreamProfile::for($device, $this->track('flac', 'flac', 16, 96000, 'hi0096')), '96 kHz is too fast');
        $this->assertSame('flac-16', StreamProfile::for($device, $this->track('wav', 'pcm_s24le', 24, 48000, 'wav024')), 'hi-res WAV becomes FLAC');
        $this->assertSame('mp3-high', StreamProfile::for($device, $this->track('m4a', 'aac', null, 44100, 'aac001')), 'AAC is denied');
        $this->assertNull(StreamProfile::for($device, $this->track('mp3', 'mp3', null, 44100, 'mp3001')), 'MP3 plays as it is');
    }

    public function test_a_track_that_is_not_the_servers_own_file_is_left_alone(): void
    {
        $artist = Artist::create(['name' => 'Band', 'source' => 'dlna']);
        $track = Track::create(['artist_id' => $artist->id, 'name' => 'Remote', 'source' => 'dlna', 'external_id' => '1:abc']);

        $this->assertNull(StreamProfile::for($this->player(), $track));
    }

    public function test_the_proxy_address_has_its_own_extension_and_names_the_profile(): void
    {
        $url = StreamProfile::url(42, 'mp3-high');

        $this->assertStringEndsWith('/stream/42.mp3?profile=mp3-high', $url);
        $this->assertSame('mp3-high', StreamProfile::profileOf($url));
        $this->assertNull(StreamProfile::profileOf('http://x/stream/42.flac'));
        $this->assertNull(StreamProfile::profileOf('http://x/stream/42.flac?profile=warp-9'));
    }

    public function test_a_hires_track_is_played_from_a_proxy_made_before_the_player_is_told(): void
    {
        $track = $this->track('flac', 'flac', 24, 44100, 'hires1');
        $device = $this->player();

        $device->driver->playLibraryTrack($track);

        $this->assertCount(1, $this->sent);
        $this->assertStringContainsString("/stream/{$track->id}.flac?profile=flac-16", html_entity_decode($this->sent[0]));
        $this->assertTrue(Proxies::disk()->exists(Proxies::path(Proxies::original($track), 'flac-16')), 'the proxy exists when the player is told');
        $this->assertStringContainsString('protocolInfo=&quot;http-get:*:audio/flac:*&quot;', $this->sent[0]);
    }

    public function test_aac_is_played_from_an_mp3_proxy_and_told_so(): void
    {
        $track = $this->track('m4a', 'aac', null, 44100, 'aac002');

        $this->player()->driver->playLibraryTrack($track);

        $this->assertStringContainsString("/stream/{$track->id}.mp3?profile=mp3-high", html_entity_decode($this->sent[0]));
        $this->assertStringContainsString('http-get:*:audio/mpeg:*', html_entity_decode($this->sent[0]));
    }

    public function test_a_track_within_the_limits_is_played_as_it_is(): void
    {
        $track = $this->track('flac', 'flac', 16, 44100, 'cd0002');

        $this->player()->driver->playLibraryTrack($track);

        $this->assertStringNotContainsString('profile=', $this->sent[0]);
        $this->assertStringContainsString("/stream/{$track->id}.flac", html_entity_decode($this->sent[0]));
    }

    public function test_when_the_proxy_cannot_be_made_the_original_is_sent_instead(): void
    {
        $this->ffmpegWorks = false;
        $track = $this->track('flac', 'flac', 24, 44100, 'hires2');

        $this->player()->driver->playLibraryTrack($track);

        $this->assertCount(1, $this->sent);
        $this->assertStringNotContainsString('profile=', $this->sent[0]);
    }

    public function test_enabling_the_streamium_records_what_it_decodes(): void
    {
        $device = Device::factory()->create(['ip_address' => '10.0.0.80']);

        $this->artisan('streamium:enable', ['device' => $device->id])->assertSuccessful();

        $this->assertSame(['max_bit_depth' => 16, 'max_sample_rate' => 48000, 'deny_codecs' => ['aac'], 'no_tls' => true], StreamProfile::limits($device));
        $this->assertSame('always', \App\Domain\Radio\Relay\RelayDecision::mode($device), 'its radio goes through the relay');
    }
}
