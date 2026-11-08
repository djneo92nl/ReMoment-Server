<?php

namespace App\Domain\Developer;

use App\Domain\Analysis\Tools;
use App\Domain\Storage\Proxies;
use App\Domain\Storage\StreamUrl;
use Illuminate\Support\Facades\Process;

/** Generated test signals for /settings/developer, made by ffmpeg once and kept on the proxy disk under `devtones/`. */
final class Tones
{
    private const SECONDS = 30;

    /** @return array<string, array{label: string, filter: string[]}> */
    public static function all(): array
    {
        $s = self::SECONDS;

        return [
            'sine-1k' => ['label' => '1 kHz sine (level check)', 'filter' => ['-f', 'lavfi', '-i', "sine=frequency=1000:duration={$s}", '-af', 'volume=0.3']],
            'sweep' => ['label' => 'Sweep 20 Hz to 20 kHz', 'filter' => ['-f', 'lavfi', '-i', "aevalsrc=0.3*sin(2*PI*(20*t+333*t*t)):d={$s}:s=44100"]],
            'left-right' => ['label' => 'Left, right, left, right (channel check)', 'filter' => ['-f', 'lavfi', '-i', "aevalsrc=0.3*sin(2*PI*440*t)*lt(mod(t\\,4)\\,2)|0.3*sin(2*PI*440*t)*gte(mod(t\\,4)\\,2):d={$s}:s=44100"]],
            'pink-noise' => ['label' => 'Pink noise', 'filter' => ['-f', 'lavfi', '-i', "anoisesrc=color=pink:amplitude=0.3:duration={$s}"]],
        ];
    }

    public static function known(string $name): bool
    {
        return isset(self::all()[$name]);
    }

    public static function path(string $name): string
    {
        return "devtones/{$name}.wav";
    }

    /** Makes the file when it is not there yet; throws with ffmpeg's message when it fails. */
    public static function ensure(string $name): string
    {
        if (!self::known($name)) {
            throw new \InvalidArgumentException("Unknown tone {$name}.");
        }

        $disk = Proxies::disk();
        $path = self::path($name);

        if ($disk->exists($path)) {
            return $path;
        }

        $temp = tempnam(sys_get_temp_dir(), 'remoment-tone-').'.wav';

        try {
            $result = Process::timeout(60)->run([Tools::ffmpeg(), '-y', '-nostdin', '-v', 'error', ...self::all()[$name]['filter'], '-ac', '2', '-ar', '44100', '-c:a', 'pcm_s16le', $temp]);

            if (!$result->successful() || !is_file($temp) || filesize($temp) === 0) {
                throw new \RuntimeException('ffmpeg failed: '.trim($result->errorOutput() ?: 'no output'));
            }

            $stream = fopen($temp, 'rb');
            $disk->put($path, $stream);
            is_resource($stream) && fclose($stream);
        } finally {
            @unlink($temp);
        }

        return $path;
    }

    public static function url(string $name): string
    {
        return StreamUrl::base()."/dev/tone/{$name}.wav";
    }
}
