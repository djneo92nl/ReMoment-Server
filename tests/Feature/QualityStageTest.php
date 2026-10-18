<?php

namespace Tests\Feature;

use App\Domain\Analysis\Spectrum;
use App\Domain\Analysis\Stages\QualityStage;
use App\Models\File;
use PHPUnit\Framework\TestCase;

/** The quality stage's parsing and verdicts; the ffmpeg runs themselves are faked in AudioAnalysisTest. */
class QualityStageTest extends TestCase
{
    private function file(string $extension = 'flac', ?int $duration = 240): File
    {
        return new File(['extension' => $extension, 'duration' => $duration]);
    }

    /** @param array<string, string> $lines "id: name" => value */
    private function stderr(array $lines, string $extra = ''): string
    {
        $out = '';
        foreach ($lines as $key => $value) {
            [$id, $name] = explode(': ', $key);
            $out .= "[astats@{$id} @ 0x1] {$name}: {$value}\n";
        }

        return $out.$extra;
    }

    private function normal(): array
    {
        return ['all: Peak count' => '2.000000', 'all: Flat factor' => '0.000000', 'all: Bit depth' => '22/24/24/24', 'all: Number of samples' => '10584000',
            'mid: RMS level dB' => '-20.0', 'side: RMS level dB' => '-23.0'];
    }

    public function test_the_graph_is_one_pass_with_a_probe_per_question(): void
    {
        $graph = QualityStage::graph();

        $this->assertStringContainsString('asplit=4', $graph);
        foreach (['astats@all', 'astats@mid', 'astats@side', 'silencedetect', '[out]'] as $part) {
            $this->assertStringContainsString($part, $graph);
        }
    }

    public function test_the_excerpt_is_centred_and_a_short_track_starts_at_zero(): void
    {
        $this->assertSame(105, QualityStage::excerptStart(240));
        $this->assertSame(0, QualityStage::excerptStart(25));
        $this->assertSame(0, QualityStage::excerptStart(null));
    }

    public function test_a_normal_file_has_nothing_flagged(): void
    {
        $r = QualityStage::parse($this->stderr($this->normal()), 44100, $this->file());

        $this->assertSame(24, $r['measured_bit_depth']);
        $this->assertSame(2, $r['clipped_samples']);
        $this->assertFalse($r['clipping_suspect']);
        $this->assertFalse($r['is_mono']);
        $this->assertFalse($r['phase_issue']);
        $this->assertSame(0, $r['lead_silence_ms']);
        $this->assertSame(0, $r['trail_silence_ms']);
        $this->assertNull($r['decode_warnings']);
    }

    public function test_clipping_needs_flat_tops_and_many_hits(): void
    {
        $hard = QualityStage::parse($this->stderr(['all: Peak count' => '43688.0', 'all: Flat factor' => '26.9'] + $this->normal()), 44100, $this->file());
        $limited = QualityStage::parse($this->stderr(['all: Peak count' => '789.0', 'all: Flat factor' => '0.07'] + $this->normal()), 44100, $this->file());

        $this->assertTrue($hard['clipping_suspect']);
        $this->assertSame(43688, $hard['clipped_samples']);
        $this->assertFalse($limited['clipping_suspect'], 'a limiter touching its ceiling is not clipping');
    }

    public function test_mono_and_out_of_phase_stereo(): void
    {
        $mono = QualityStage::parse($this->stderr(['mid: RMS level dB' => '-20.0', 'side: RMS level dB' => '-inf'] + $this->normal()), 44100, $this->file());
        $near = QualityStage::parse($this->stderr(['mid: RMS level dB' => '-20.0', 'side: RMS level dB' => '-65.0'] + $this->normal()), 44100, $this->file());
        $anti = QualityStage::parse($this->stderr(['mid: RMS level dB' => '-inf', 'side: RMS level dB' => '-20.0'] + $this->normal()), 44100, $this->file());
        $silent = QualityStage::parse($this->stderr(['mid: RMS level dB' => '-inf', 'side: RMS level dB' => '-inf'] + $this->normal()), 44100, $this->file());

        $this->assertTrue($mono['is_mono']);
        $this->assertTrue($near['is_mono']);
        $this->assertTrue($anti['phase_issue']);
        $this->assertFalse($anti['is_mono']);
        $this->assertNull($silent['is_mono'], 'silence says nothing about the channels');
    }

    public function test_silence_at_the_start_and_end(): void
    {
        $log = "[silencedetect @ 0x1] silence_start: 0\n[silencedetect @ 0x1] silence_end: 1.5 | silence_duration: 1.5\n"
            ."[silencedetect @ 0x1] silence_start: 238.25\n";

        $r = QualityStage::parse($this->stderr($this->normal(), $log), 44100, $this->file());

        $this->assertSame(1500, $r['lead_silence_ms']);
        $this->assertSame(1750, $r['trail_silence_ms'], '240 s decoded minus the silence start');
    }

    public function test_decoder_warnings_and_a_truncated_file_are_reported_without_ffmpegs_footer(): void
    {
        $log = "[mp3float @ 0x1] Header missing\n[out#0/null @ 0x2] video:0KiB audio:8269KiB subtitle:0KiB other streams:0KiB global headers:0KiB muxing overhead: unknown\n";

        $r = QualityStage::parse($this->stderr(['all: Number of samples' => '4410000'] + $this->normal(), $log), 44100, $this->file('flac', 240));

        $this->assertSame(['Header missing', 'decoded 100 s, the tags say 240 s'], $r['decode_warnings']);
    }

    public function test_the_measured_bit_depth_means_nothing_for_a_lossy_file(): void
    {
        $r = QualityStage::parse($this->stderr(['all: Bit depth' => '32/32/32/32'] + $this->normal()), 44100, $this->file('mp3'));

        $this->assertNull($r['measured_bit_depth']);
        $this->assertSame(16, QualityStage::parse($this->stderr(['all: Bit depth' => '13/16/16/16'] + $this->normal()), 44100, $this->file())['measured_bit_depth']);
    }

    public function test_the_cutoff_is_a_cliff_not_a_slope(): void
    {
        $slope = [14000 => -60.0, 14500 => -61.0, 15000 => -62.0, 15500 => -63.0, 16000 => -64.0, 16500 => -65.0, 17000 => -66.0, 17500 => -67.0];
        $cliff = [14000 => -60.0, 14500 => -61.0, 15000 => -62.0, 15500 => -63.0, 16000 => -64.0, 16500 => -90.0, 17000 => -140.0, 17500 => -145.0];
        $notes = [14000 => -60.0, 14500 => -61.0, 15000 => -75.0, 15500 => -62.0, 16000 => -63.0];

        $this->assertNull(QualityStage::cutoff($slope));
        $this->assertSame(16500, QualityStage::cutoff($cliff));
        $this->assertNull(QualityStage::cutoff($notes), 'a dip that recovers is music, not a low-pass');
        $this->assertNull(QualityStage::cutoff([14000 => -60.0, 14500 => -140.0]), 'too few bands to judge');
    }

    public function test_the_verdict_follows_the_cutoff_and_the_sample_rate(): void
    {
        $this->assertSame(0.0, QualityStage::suspect(null, 44100));
        $this->assertSame(0.95, QualityStage::suspect(16500, 44100));
        $this->assertSame(0.8, QualityStage::suspect(19000, 44100));
        $this->assertSame(0.3, QualityStage::suspect(20500, 44100));
        $this->assertSame(0.0, QualityStage::suspect(21500, 44100));
        $this->assertSame(0.9, QualityStage::suspect(22000, 96000), 'hi-res that ends at 22 kHz began as 44.1 kHz');
        $this->assertSame(0.0, QualityStage::suspect(30000, 96000));
    }

    public function test_a_lowpassed_signal_is_found_by_the_spectrum(): void
    {
        $pcm = AudioAnalysisTest::floats(16000, 30000);

        $r = QualityStage::spectrum($pcm, 44100, $this->file('flac'));
        $this->assertGreaterThanOrEqual(16000, $r['spectral_cutoff_hz']);
        $this->assertLessThanOrEqual(17000, $r['spectral_cutoff_hz']);
        $this->assertSame(0.95, $r['lossless_suspect']);

        $lossy = QualityStage::spectrum($pcm, 44100, $this->file('mp3'));
        $this->assertSame($r['spectral_cutoff_hz'], $lossy['spectral_cutoff_hz'], 'the cutoff is stored for lossy files too');
        $this->assertNull($lossy['lossless_suspect'], 'but only a lossless file can be a fake one');
    }

    public function test_a_full_band_signal_has_no_cutoff_and_silence_cannot_be_judged(): void
    {
        $full = QualityStage::spectrum(AudioAnalysisTest::floats(22000, 30000), 44100, $this->file('flac'));
        $this->assertNull($full['spectral_cutoff_hz']);
        $this->assertSame(0.0, $full['lossless_suspect']);

        $silence = QualityStage::spectrum(str_repeat(pack('g', 0.0), 30000), 44100, $this->file('flac'));
        $this->assertSame(['spectral_cutoff_hz' => null, 'lossless_suspect' => null], $silence);

        $this->assertSame([], Spectrum::bands('', 44100, 10000, 500));
    }

    public function test_the_fft_puts_a_sine_in_the_right_bin(): void
    {
        $n = Spectrum::FRAME;
        // 64 whole cycles over the frame: bin 64.
        $x = array_map(fn ($i) => sin(2 * M_PI * 64 * $i / $n), range(0, $n - 1));

        [$re, $im] = Spectrum::fft($x);
        $power = array_map(fn ($r, $i) => $r * $r + $i * $i, array_slice($re, 0, $n / 2), array_slice($im, 0, $n / 2));

        $this->assertSame(64, array_search(max($power), $power));
        $this->assertEqualsWithDelta($n / 2, sqrt(max($power)), 0.01);
    }
}
