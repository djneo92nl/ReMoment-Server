<?php

namespace App\Integrations\BangOlufsen\Ase\Services;

use App\Domain\Media\NowPlaying;
use App\Domain\Media\Source;
use App\Domain\Media\TrackData;
use App\Events\Device\NowPlayingUpdated;
use App\Models\Device;
use App\Models\DeviceMeta;

/**
 * The ASE listener for a BeoLink Converter NL/ML. It pushes the usual ASE notifications (SOURCE,
 * NOW_PLAYING_NET_RADIO for a Network Link stream playing out on the Master Link speakers, VOLUME,
 * PROGRESS_INFORMATION) plus what the Master Link source itself reports:
 *
 * - `NOW_PLAYING_LEGACY {"trackNumber": N}`, repeated about once a second while it plays: the tuner
 *   preset for RADIO, the track for CD / A.MEM. It is shown as "Preset N" / "Track N" for the active
 *   source, once per change. It has no artist, so it never lands in the playback history.
 * - the `listener` list of `SOURCE` / `SOURCE_EXPERIENCE_CHANGED`: who hears the source. It holds the
 *   converter's own jid while its Master Link master outputs the source, so `Source::$playsLocally`
 *   tells that apart from "only a joined player plays it". A change of the list (a player joins or
 *   leaves, the master goes to standby) is announced again with the current preset.
 */
class ConverterListener extends DeviceListener
{
    private ?Source $source = null;

    /** The last tuner preset / track number, to show it again with a changed source. */
    private ?int $preset = null;

    /** The last reported "{source id}#{number}", to drop the once-a-second repeats. */
    private ?string $lastLegacy = null;

    /** @param ?string $ownJid the converter's own jid (device meta `converter_jid`), found in `listener` while it plays locally */
    public function __construct(string $url, private ?string $ownJid = null)
    {
        parent::__construct($url);
    }

    public static function forDevice(Device $device): ?static
    {
        return new static(
            'http://'.$device->ip_address.':8080/BeoNotify/Notifications',
            DeviceMeta::where('device_id', $device->id)->where('key', 'converter_jid')->value('value'),
        );
    }

    protected function applyNotification(array $payload, string $deviceId): void
    {
        $n = $payload['notification'] ?? [];
        $type = $n['type'] ?? null;

        if ($type === 'NOW_PLAYING_LEGACY') {
            $this->applyLegacy((int) ($n['data']['trackNumber'] ?? 0), $deviceId);

            return;
        }

        if ($type === 'SOURCE_EXPERIENCE_CHANGED') {
            $this->applyExperience($n['data'] ?? [], $deviceId);

            return;
        }

        if ($type === 'SOURCE') {
            $data = $n['data'] ?? [];
            $this->source = $data === [] ? null : $this->parseSource($data)->source;
            $this->preset = null;
            $this->lastLegacy = null;
        }

        parent::applyNotification($payload, $deviceId);
    }

    protected function parseSource(mixed $data): NowPlaying
    {
        $nowPlaying = parent::parseSource($data);
        $nowPlaying->source->playsLocally = $this->playsLocally($data['primaryExperience'] ?? []);

        return $nowPlaying;
    }

    /** Whether the converter's own jid listens: true / false, or null when the notification has no listener list. */
    private function playsLocally(array $experience): ?bool
    {
        $listeners = $experience['listener'] ?? null;
        $own = $this->ownJid ?? ($experience['source']['product']['jid'] ?? null);

        if (!is_array($listeners) || $own === null) {
            return null;
        }

        return in_array($own, $listeners, true);
    }

    /** The listeners of the playing source changed: announce the source again, with the preset when there is one. */
    private function applyExperience(array $data, string $deviceId): void
    {
        $experience = $data['primaryExperience'] ?? [];

        if (($experience['source'] ?? []) === []) {
            return;
        }

        $new = $this->parseSource(['primaryExperience' => $experience])->source;

        if ($this->source !== null && $new->jid === $this->source->jid && $new->playsLocally === $this->source->playsLocally) {
            return;
        }

        if ($this->source?->jid !== $new->jid) {
            $this->preset = null;
        }

        $this->source = $new;
        $this->lastLegacy = null;

        if ($this->preset !== null) {
            $this->applyLegacy($this->preset, $deviceId);

            return;
        }

        event(new NowPlayingUpdated(
            deviceId: $deviceId,
            nowPlaying: new NowPlaying(type: 'video', platform: 'media', source: $new),
            sourceType: 'media',
        ));
    }

    private function applyLegacy(int $number, string $deviceId): void
    {
        if ($number <= 0) {
            return;
        }

        $this->preset = $number;
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
