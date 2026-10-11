<?php

namespace App\Integrations\BangOlufsen\Ase\Services;

use App\Domain\Media\NowPlaying;
use App\Domain\Media\Source;
use App\Domain\Media\TrackData;
use App\Events\Device\NowPlayingUpdated;

/**
 * The ASE listener for a BeoLink Converter NL/ML. It pushes the usual ASE notifications (SOURCE,
 * NOW_PLAYING_NET_RADIO for a Network Link stream playing out on the Master Link speakers, VOLUME,
 * PROGRESS_INFORMATION) plus what the Master Link source itself reports:
 *
 * - `NOW_PLAYING_LEGACY {"trackNumber": N}`, repeated about once a second while it plays: the tuner
 *   preset for RADIO, the track for CD / A.MEM. It is shown as "Preset N" / "Track N" for the active
 *   source, once per change. It has no artist, so it never lands in the playback history.
 * - `SOURCE_EXPERIENCE_CHANGED`: who listens to the source changed; ignored for now.
 */
class ConverterListener extends DeviceListener
{
    private ?Source $source = null;

    /** The last reported "{source id}#{number}", to drop the once-a-second repeats. */
    private ?string $lastLegacy = null;

    protected function applyNotification(array $payload, string $deviceId): void
    {
        $n = $payload['notification'] ?? [];
        $type = $n['type'] ?? null;

        if ($type === 'NOW_PLAYING_LEGACY') {
            $this->applyLegacy((int) ($n['data']['trackNumber'] ?? 0), $deviceId);

            return;
        }

        if ($type === 'SOURCE') {
            $data = $n['data'] ?? [];
            $this->source = $data === [] ? null : $this->parseSource($data)->source;
            $this->lastLegacy = null;
        }

        if ($type === 'SOURCE_EXPERIENCE_CHANGED') {
            return;
        }

        parent::applyNotification($payload, $deviceId);
    }

    private function applyLegacy(int $number, string $deviceId): void
    {
        if ($number <= 0) {
            return;
        }

        $key = ($this->source?->jid ?? '').'#'.$number;

        if ($key === $this->lastLegacy) {
            return;
        }

        $this->lastLegacy = $key;

        $isTuner = strcasecmp((string) $this->source?->sourceType, 'RADIO') === 0;

        event(new NowPlayingUpdated(
            deviceId: $deviceId,
            nowPlaying: new NowPlaying(
                track: new TrackData(name: ($isTuner ? 'Preset ' : 'Track ').$number, meta: ['trackNumber' => $number]),
                type: 'music',
                platform: 'media',
                source: $this->source,
            ),
            sourceType: 'media',
        ));
    }
}
