<?php

namespace App\Domain\Export;

use App\Domain\Analysis\Tools;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Process;

/**
 * The transcode formats of an export (`config('export.formats')`: mp3, aac, opus) and which of them this
 * machine's ffmpeg can really make. `ffmpeg -encoders` is read once and cached for an hour, keyed by the binary.
 */
final class ExportFormats
{
    /** @var array<string, list<string>> */
    private static array $probed = [];

    /** @return list<string> */
    public static function ids(): array
    {
        return array_keys(config('export.formats'));
    }

    /** The format a profile transcodes to; null for `highest`. */
    public static function ofProfile(string $profile): ?string
    {
        return config("export.profiles.{$profile}.target");
    }

    public static function extension(string $format): string
    {
        return (string) config("export.formats.{$format}.extension", $format);
    }

    /** The encoder ffmpeg would use for the format (first listed one it has), or null when it has none. */
    public static function encoder(string $format): ?string
    {
        $have = self::encoders();
        foreach (config("export.formats.{$format}.encoders", []) as $encoder) {
            if (in_array($encoder, $have, true)) {
                return $encoder;
            }
        }

        return null;
    }

    public static function available(string $format): bool
    {
        return self::encoder($format) !== null;
    }

    /** True when ffmpeg listed its encoders and has none for the format (an unreadable list is not a "no"). */
    public static function missing(string $format): bool
    {
        return self::encoders() !== [] && !self::available($format);
    }

    /** @return list<string> the formats ffmpeg can encode now, in config order */
    public static function availableIds(): array
    {
        return array_values(array_filter(self::ids(), fn (string $f) => self::available($f)));
    }

    /** The description of a format for the estimate and the dialog. @return array<string, mixed> */
    public static function describe(string $format): array
    {
        return [
            'id' => $format,
            'label' => config("export.formats.{$format}.label"),
            'extension' => self::extension($format),
            'mime' => config("export.formats.{$format}.mime"),
            'encoder' => self::encoder($format),
            'available' => self::available($format),
            'note' => config("export.formats.{$format}.note"),
        ];
    }

    /** Bytes per kbit/s·s of the format's container, as a factor on duration × bitrate. */
    public static function overhead(string $format): float
    {
        return (float) config("export.formats.{$format}.overhead", 1.0);
    }

    public static function forget(): void
    {
        self::$probed = [];
        Cache::forget(self::cacheKey());
    }

    /** @return list<string> */
    private static function encoders(): array
    {
        $key = self::cacheKey();

        if (isset(self::$probed[$key])) {
            return self::$probed[$key];
        }
        $cached = Cache::get($key);
        if (!is_array($cached)) {
            $cached = self::probe();
            if ($cached !== []) {
                Cache::put($key, $cached, 3600); // a failed probe (no ffmpeg yet) is not remembered
            }
        }

        return $cached === [] ? $cached : (self::$probed[$key] = $cached);
    }

    private static function cacheKey(): string
    {
        return 'export:encoders:'.md5(Tools::ffmpeg());
    }

    /** @return list<string> audio encoder names from `ffmpeg -encoders`; empty when ffmpeg can't run */
    private static function probe(): array
    {
        try {
            $result = Process::timeout(15)->run([Tools::ffmpeg(), '-hide_banner', '-encoders']);
        } catch (\Throwable) {
            return [];
        }
        if (!$result->successful()) {
            return [];
        }

        preg_match_all('/^\s*A[A-Z.]{5}\s+(\S+)/m', $result->output(), $m);

        return array_values(array_unique($m[1]));
    }
}
