<?php

namespace App\Domain\Developer;

use App\Domain\Library\LibraryPlayback;
use App\Domain\Storage\Proxies;
use App\Domain\Storage\StreamUrl;
use App\Models\Device;
use App\Models\Media\Track;

/** Plays a track on a device as the original or as a rung of the quality ladder (/settings/developer). */
final class QualityPlayer
{
    private const GROUPS = ['Player profiles', 'Lossless', 'Uncompressed', 'MP3', 'Lossy'];

    /**
     * Every profile as a rung, low to high within its group.
     *
     * @return array<int, array{name: string, label: string, group: string, rate: int, dev: bool}>
     */
    public static function ladder(): array
    {
        $rungs = [];

        foreach (Proxies::profiles() as $name => $profile) {
            $args = $profile['args'];
            $at = array_search('-ar', $args, true);

            $rungs[] = [
                'name' => $name,
                'label' => $profile['label'] ?? $name,
                'group' => $profile['group'] ?? 'Player profiles',
                'rate' => $at === false ? 44100 : (int) $args[$at + 1],
                'dev' => !empty($profile['dev']),
            ];
        }

        usort($rungs, fn (array $a, array $b) => [array_search($a['group'], self::GROUPS), strnatcasecmp($a['label'], $b['label'])]
            <=> [array_search($b['group'], self::GROUPS), 0] ?: strnatcasecmp($a['label'], $b['label']));

        return $rungs;
    }

    /** Makes the transcode now (ffmpeg); throws with a readable message when it can't. */
    public static function prepare(Track $track, string $profile): void
    {
        if (!Proxies::known($profile)) {
            throw new \RuntimeException("Unknown profile {$profile}.");
        }

        if (!Proxies::enabled()) {
            throw new \RuntimeException('Transcoding is off: PROXIES_ENABLED is false or ffmpeg is missing.');
        }

        if (!($original = Proxies::original($track))) {
            throw new \RuntimeException('This track has no file on disk.');
        }

        Proxies::generate($original, $profile);
    }

    /**
     * Plays the track on the device ($profile null = the original) and says what was sent.
     *
     * @return array{url: ?string, ms: int}
     */
    public static function play(Device $device, Track $track, ?string $profile): array
    {
        if ($profile !== null) {
            self::prepare($track, $profile);
        }

        $started = microtime(true);

        $url = StreamUrl::withProfile($profile, function () use ($track, $device) {
            $url = $track->getDlnaUrl();
            app(LibraryPlayback::class)->playTrack($device, $track, withAlbum: false);

            return $url;
        });

        $ms = (int) round((microtime(true) - $started) * 1000);
        CallTimings::record($device->id, 'play '.($profile ?? 'original'), $ms, true);

        return ['url' => $url, 'ms' => $ms];
    }
}
