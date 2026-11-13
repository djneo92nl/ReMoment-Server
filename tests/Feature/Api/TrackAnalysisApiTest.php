<?php

namespace Tests\Feature\Api;

use App\Models\Media\Album;
use App\Models\Media\Artist;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\MakesLibraryTracks;
use Tests\TestCase;

/** The typed track analysis surface: TrackDetails.analysis and the waveform endpoint (docs/api/library.md). */
class TrackAnalysisApiTest extends TestCase
{
    use MakesLibraryTracks;
    use RefreshDatabase;

    public function test_waveform_is_served_once_analysed_and_404_before(): void
    {
        $album = Album::create(['artist_id' => Artist::create(['name' => 'A', 'source' => 'files'])->id, 'name' => 'L', 'source' => 'files']);
        $plain = $this->makeLibraryTrack($album, 'Plain');
        $wave = $this->makeAnalysedTrack($album, 'Wave', ['waveform' => [1, 50, 100]]);

        $this->getJson("/api/library/tracks/{$plain->id}/waveform")->assertNotFound();
        $this->getJson("/api/library/tracks/{$wave->id}/waveform")->assertOk()->assertExactJson(['points' => [1, 50, 100], 'duration' => 200]);
    }

    public function test_album_track_details_carry_the_typed_analysis_block(): void
    {
        $album = Album::create(['artist_id' => Artist::create(['name' => 'A', 'source' => 'files'])->id, 'name' => 'L', 'source' => 'files']);
        $this->makeAnalysedTrack($album, 'Sound', ['bpm' => 124.04, 'key' => 'A', 'mode' => 'minor', 'arousal' => 0.5]);

        $analysis = $this->getJson("/api/library/albums/{$album->id}")->assertOk()->json('tracks.0.details.analysis');

        $this->assertEquals(124.0, $analysis['bpm']);
        $this->assertSame('8A', $analysis['camelot']);
        $this->assertSame(0.5, $analysis['arousal']);
        $this->assertNull($analysis['valence']);
        foreach (['loudness', 'plr', 'spectral_cutoff_hz', 'lossless_suspect', 'measured_bit_depth', 'clipping', 'mono', 'phase_issue', 'lead_silence_ms', 'trail_silence_ms', 'decode_warnings', 'replaygain_track_gain', 'mood', 'recording_mbid'] as $key) {
            $this->assertArrayHasKey($key, $analysis);
        }
    }
}
