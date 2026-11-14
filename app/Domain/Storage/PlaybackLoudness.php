<?php

namespace App\Domain\Storage;

use App\Domain\Export\ExportLoudness;
use App\Models\File;
use App\Models\Setting;

/**
 * Loudness-normalised playback (roadmap 12.3), only where the server itself makes the audio: a proxy transcoded for a
 * renderer that cannot decode the original (`Upnp\StreamProfile`, `Proxies`). The gain is `-af volume=XdB` in that same
 * ffmpeg run, from the stored ReplayGain tag or measured loudness (`ExportLoudness`, the export's calculation) and never
 * past the stored true peak. An original streamed as it is (direct DLNA playback, a renderer without limits, a file
 * the renderer decodes itself) is never touched, so the setting changes nothing there; no tag is written to a file and
 * no device volume is moved. Off by default (Setting `playback_loudness`, `/settings/storage` › Library, admin API).
 */
final class PlaybackLoudness
{
    public const SETTING = 'playback_loudness';

    public static function enabled(): bool
    {
        return Setting::get(self::SETTING) === '1';
    }

    public static function set(bool $on): void
    {
        Setting::set(self::SETTING, $on ? '1' : '0');
    }

    /**
     * The gain in dB the proxy of this file and profile carries, or null for none (setting off, a developer-ladder rung,
     * nothing analysed, or a change under 0.1 dB). Stable for the same stored values, so it is part of the proxy's file name.
     */
    public static function gainFor(File $original, string $profile): ?float
    {
        if (!self::enabled() || !empty(config("proxies.profiles.{$profile}.dev"))) {
            return null;
        }

        $track = $original->trackFiles()->orderByDesc('is_primary')->with('track')->get()->pluck('track')->filter()->first();
        if ($track === null) {
            return null;
        }

        $cfg = (array) config('proxies.loudness');
        $gain = (new ExportLoudness)->gains(
            [$track->id => ['track' => $track, 'file' => $original]], 'track',
            ['reference' => $cfg['reference_lufs'] ?? -18.0, 'margin' => $cfg['margin_db'] ?? 1.0, 'max' => $cfg['max_gain_db'] ?? 9.0],
        )[$track->id] ?? null;

        return $gain === null || abs($gain) < 0.1 ? null : $gain;
    }
}
