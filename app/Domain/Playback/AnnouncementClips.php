<?php

namespace App\Domain\Playback;

use App\Domain\Analysis\Tools;
use App\Domain\Storage\StreamUrl;
use App\Models\Setting;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * The clips an announcement can play (docs/api/announcements.md): a built-in chime and what the admin uploaded.
 * Every clip is kept as an MP3 (Sonos and UPnP renderers are told the stream is MP3) under `announcements/` on the
 * local disk, with its label and length in the setting `announcement_clips`; the length is what times the restore.
 */
final class AnnouncementClips
{
    public const CHIME = 'chime';

    public const MAX_SECONDS = 30;

    private const SETTING = 'announcement_clips';

    /** @return array<string, array{name: string, label: string, duration_ms: int, builtin: bool}> */
    public static function all(): array
    {
        $clips = [self::CHIME => ['name' => self::CHIME, 'label' => 'Chime', 'duration_ms' => 2200, 'builtin' => true]];

        foreach (self::stored() as $name => $clip) {
            $clips[$name] = ['name' => $name, 'label' => (string) ($clip['label'] ?? $name), 'duration_ms' => (int) ($clip['duration_ms'] ?? 0), 'builtin' => false];
        }

        return $clips;
    }

    /** @return array{name: string, label: string, duration_ms: int, builtin: bool}|null */
    public static function find(string $name): ?array
    {
        return self::all()[$name] ?? null;
    }

    public static function url(string $name): string
    {
        return StreamUrl::base()."/announce/{$name}.mp3";
    }

    /** The clip's file on disk, making the chime when it is not there yet. */
    public static function file(string $name): string
    {
        $path = Storage::disk('local')->path(self::relative($name));

        if ($name === self::CHIME && !is_file($path)) {
            // Two soft notes, a fourth apart.
            self::encode(['-f', 'lavfi', '-i', 'aevalsrc=0.4*sin(2*PI*880*t)*exp(-3*t)*lt(t\,0.9)+0.4*sin(2*PI*659.25*(t-0.9))*exp(-2.5*(t-0.9))*gte(t\,0.9):d=2.2:s=44100'], $path);
        }

        return $path;
    }

    /**
     * Keep an uploaded sound as a clip: converted to MP3, at most MAX_SECONDS long.
     *
     * @return array{name: string, label: string, duration_ms: int, builtin: bool}
     *
     * @throws \RuntimeException when ffmpeg cannot read it
     */
    public static function add(UploadedFile $file, string $label): array
    {
        $label = trim($label) ?: pathinfo($file->getClientOriginalName(), PATHINFO_FILENAME);
        $base = Str::slug($label) ?: 'clip';
        $name = $base;
        for ($i = 2; isset(self::all()[$name]); $i++) {
            $name = "{$base}-{$i}";
        }

        $path = Storage::disk('local')->path(self::relative($name));
        self::encode(['-i', $file->getRealPath(), '-t', (string) self::MAX_SECONDS], $path);

        $duration = self::duration($path);
        if ($duration <= 0) {
            @unlink($path);

            throw new \RuntimeException('This file has no sound ffmpeg can read.');
        }

        Setting::set(self::SETTING, json_encode(self::stored() + [$name => ['label' => $label, 'duration_ms' => $duration]]));

        return self::find($name);
    }

    public static function remove(string $name): bool
    {
        $stored = self::stored();
        if (!isset($stored[$name])) {
            return false;
        }

        unset($stored[$name]);
        Setting::set(self::SETTING, json_encode($stored));
        Storage::disk('local')->delete(self::relative($name));

        return true;
    }

    private static function stored(): array
    {
        $value = Setting::get(self::SETTING);
        $clips = is_string($value) ? json_decode($value, true) : $value;

        return is_array($clips) ? $clips : [];
    }

    private static function relative(string $name): string
    {
        return "announcements/{$name}.mp3";
    }

    /** @param string[] $input ffmpeg input arguments */
    private static function encode(array $input, string $target): void
    {
        if (!is_dir(dirname($target))) {
            mkdir(dirname($target), 0775, true);
        }

        $result = Process::timeout(120)->run([Tools::ffmpeg(), '-y', '-nostdin', '-v', 'error', ...$input, '-vn', '-ac', '2', '-ar', '44100', '-c:a', 'libmp3lame', '-b:a', '192k', $target]);

        if (!$result->successful() || !is_file($target) || filesize($target) === 0) {
            @unlink($target);

            throw new \RuntimeException('ffmpeg failed: '.trim($result->errorOutput() ?: 'no output'));
        }
    }

    /** Length in milliseconds, read from ffmpeg's own report of the file. */
    private static function duration(string $path): int
    {
        $result = Process::timeout(30)->run([Tools::ffmpeg(), '-nostdin', '-i', $path, '-f', 'null', '-']);

        return preg_match_all('/time=(\d+):(\d+):(\d+(?:\.\d+)?)/', $result->errorOutput(), $m, PREG_SET_ORDER)
            ? (int) round((((int) end($m)[1]) * 3600 + ((int) end($m)[2]) * 60 + (float) end($m)[3]) * 1000)
            : 0;
    }
}
