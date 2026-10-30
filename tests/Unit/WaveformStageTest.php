<?php

namespace Tests\Unit;

use App\Domain\Analysis\Stages\WaveformStage;
use Tests\TestCase;

class WaveformStageTest extends TestCase
{
    /** Mono PCM of $buckets parts of $per samples each, every part a square wave of its amplitude. */
    private static function blocks(array $amplitudes, int $per = 100): string
    {
        $pcm = '';
        foreach ($amplitudes as $a) {
            $pcm .= pack('s*', ...array_map(fn ($i) => $i % 2 ? -$a : $a, range(0, $per - 1)));
        }

        return $pcm;
    }

    public function test_the_stage_is_version_two(): void
    {
        $this->assertSame('2', (new WaveformStage)->version());
    }

    public function test_a_flat_loud_block_with_small_variations_gets_a_wide_range(): void
    {
        // peaks all within 9 dB of the ceiling and mostly near it: the old waveform was a flat block
        $amps = [];
        for ($i = 0; $i < 40; $i++) {
            $amps[] = (int) (30000 * (1 - 0.7 * (($i % 10) / 10)));
        }
        $pcm = self::blocks($amps);
        $wave = WaveformStage::analyse($pcm, 40)['waveform'];

        $this->assertGreaterThan(40, max($wave) - min($wave));
    }

    public function test_a_dynamic_track_keeps_loud_and_quiet_halves_apart_and_ordered(): void
    {
        $pcm = self::blocks(array_merge(array_fill(0, 20, 2000), array_fill(0, 20, 20000)));
        $wave = WaveformStage::analyse($pcm, 40, 20.0)['waveform']; // PLR 20: no deviation layer

        $this->assertLessThan(10, max(array_slice($wave, 0, 20)));
        $this->assertGreaterThan(90, min(array_slice($wave, 20)));
    }

    public function test_silence_is_all_zero_without_dividing_by_zero(): void
    {
        $r = WaveformStage::analyse(str_repeat("\0\0", 1000), 10, 5.0);

        $this->assertSame(array_fill(0, 10, 0), $r['waveform']);
        $this->assertSame(array_fill(0, 10, 0), $r['peak']);
    }

    public function test_a_constant_tone_is_a_constant_not_amplified_noise(): void
    {
        $wave = WaveformStage::analyse(self::blocks(array_fill(0, 20, 32767)), 20, 5.0)['waveform'];

        $this->assertCount(1, array_unique($wave));
    }

    public function test_a_very_short_file_has_fewer_points(): void
    {
        $r = WaveformStage::analyse(pack('s*', 100, -200, 300), 400);

        $this->assertCount(3, $r['waveform']);
        $this->assertCount(3, $r['peak']);
    }

    public function test_the_percentile_mapping_on_a_known_distribution(): void
    {
        // RMS values whose dB are 0, -1, ... -20 below full scale (21 buckets): p5 = -19, p99 = -0.2
        $rms = array_map(fn ($d) => 32768 * 10 ** (-$d / 20), range(0, 20));
        $wave = WaveformStage::contrast($rms, 0.0, 1.0);

        $this->assertSame(100, $wave[0]);
        $this->assertSame(0, $wave[20]);
        $this->assertSame(0, $wave[19]);
        $this->assertSame((int) round((-10 - -19) / (19 - 0.2) * 100), $wave[10]);
        $sorted = array_map(fn ($d) => -$d, range(20, 0));
        $this->assertEqualsWithDelta(-19.0, WaveformStage::percentile($sorted, 5), 1e-9);
        $this->assertEqualsWithDelta(-0.2, WaveformStage::percentile($sorted, 99), 1e-9);
        // the mapping is monotonic
        $this->assertSame($wave, collect($wave)->sortDesc()->values()->all());
    }

    public function test_the_span_is_limited_to_thirty_db(): void
    {
        // 60 dB between loud and quiet buckets: the floor is 30 dB below the ceiling, so -15 dB maps to 50
        $rms = array_merge(array_fill(0, 50, 32768 * 10 ** (-1 / 20)), array_fill(0, 20, 32768 * 10 ** (-16 / 20)), array_fill(0, 30, 32768 * 10 ** (-61 / 20)));
        $wave = WaveformStage::contrast($rms, 0.0, 1.0);

        $this->assertSame(100, $wave[0]);
        $this->assertEqualsWithDelta(50, $wave[50], 1);
        $this->assertSame(0, $wave[99]);
    }

    public function test_the_deviation_strength_follows_the_plr(): void
    {
        $this->assertEqualsWithDelta(0.7, WaveformStage::strength(4.4), 1e-9);
        $this->assertSame(0.0, WaveformStage::strength(16.0));
        $this->assertEqualsWithDelta(0.35, WaveformStage::strength(11.0), 1e-9);
    }

    public function test_the_deviation_layer_makes_a_brickwalled_track_show_more_than_a_dynamic_one_and_is_skipped_without_loudness(): void
    {
        // a slow loudness drift with a brief dip at bucket 50
        $amps = [];
        for ($i = 0; $i < 100; $i++) {
            $amps[] = (int) (20000 + 100 * $i) - ($i === 50 ? 9000 : 0);
        }
        $pcm = self::blocks($amps);

        $brick = WaveformStage::analyse($pcm, 100, 4.4)['waveform'];
        $dynamic = WaveformStage::analyse($pcm, 100, 16.0)['waveform'];
        $none = WaveformStage::analyse($pcm, 100)['waveform'];

        $this->assertSame($dynamic, $none, 'without loudness data (or at a high PLR) there is no deviation layer');
        $this->assertNotSame($brick, $none);
        // the drift is flattened (less of it left) in the deviation-weighted version
        $this->assertLessThan($none[99] - $none[5], max(0, $brick[99] - $brick[5]) + 1);
    }

    public function test_the_peaks_are_what_the_old_stage_computed(): void
    {
        $pcm = pack('s*', 100, -200, 300, -400, 5000, -10000, 20000, -20000);

        $this->assertSame([1, 2, 50, 100], WaveformStage::analyse($pcm, 4)['peak']);
        $this->assertSame(WaveformStage::peaks($pcm, 4), WaveformStage::analyse($pcm, 4)['peak']);
    }

    public function test_every_value_is_an_integer_between_0_and_100(): void
    {
        mt_srand(7);
        $pcm = pack('s*', ...array_map(fn () => mt_rand(-32768, 32767), range(1, 5000)));

        foreach (WaveformStage::analyse($pcm, 50, 6.0) as $list) {
            foreach ($list as $v) {
                $this->assertIsInt($v);
                $this->assertTrue($v >= 0 && $v <= 100);
            }
        }
    }
}
