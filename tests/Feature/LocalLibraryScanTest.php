<?php

namespace Tests\Feature;

use App\Models\Media\Track;
use App\Services\Local\LocalLibraryScanner;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class LocalLibraryScanTest extends TestCase
{
    use RefreshDatabase;

    private string $dir;

    protected function setUp(): void
    {
        parent::setUp();

        $this->dir = sys_get_temp_dir().'/remoment-scan-'.uniqid();
        mkdir($this->dir.'/sub', 0777, true);
        file_put_contents($this->dir.'/sub/one.wav', $this->wav());
    }

    protected function tearDown(): void
    {
        @unlink($this->dir.'/sub/one.wav');
        @rmdir($this->dir.'/sub');
        @rmdir($this->dir);

        parent::tearDown();
    }

    /** One second of 8-bit mono silence at 8 kHz. */
    private function wav(): string
    {
        $data = str_repeat("\x80", 8000);

        return 'RIFF'.pack('V', 36 + strlen($data)).'WAVEfmt '.pack('VvvVVvv', 16, 1, 1, 8000, 8000, 1, 8).'data'.pack('V', strlen($data)).$data;
    }

    public function test_scan_indexes_a_file_and_skips_it_when_unchanged(): void
    {
        $scanner = app(LocalLibraryScanner::class);

        $this->assertSame(1, $scanner->scan($this->dir));

        $track = Track::firstOrFail();
        $this->assertSame('one', $track->name);
        $this->assertSame(1, $track->duration);
        $this->assertSame(url('/stream/'.$track->id), $track->getDlnaUrl());

        $this->assertSame(0, $scanner->scan($this->dir));
        $this->assertSame(1, Track::count());
    }

    public function test_stream_serves_the_file_with_range_support(): void
    {
        app(LocalLibraryScanner::class)->scan($this->dir);
        $track = Track::firstOrFail();

        $full = $this->get('/stream/'.$track->id);
        $full->assertOk()->assertHeader('Accept-Ranges', 'bytes');

        $part = $this->get('/stream/'.$track->id, ['Range' => 'bytes=0-9']);
        $part->assertStatus(206)->assertHeader('Content-Range', 'bytes 0-9/'.(44 + 8000));
    }

    public function test_stream_is_404_for_a_track_without_a_file(): void
    {
        $this->get('/stream/999')->assertNotFound();
    }
}
