<?php

namespace Remoment\MozartDriver;

// NOTE: this package depends directly on App\* classes rather than a
// framework-agnostic adapter interface (e.g. an AseDeviceInterface
// equivalent). docs/architecture/plugin-architecture.md proposes resolving
// this coupling for a real standalone package; that adapter doesn't exist
// for the (not-yet-extracted) ASE driver either, so building one here would
// be speculative. See that doc for the target end-state.
use App\Domain\Device\AvailableSource;
use App\Domain\Device\BatteryStatus;
use App\Domain\Device\Cache\Volume;
use App\Domain\Device\MultiRoomId;
use App\Domain\Device\RepeatMode;
use App\Domain\Device\State;
use App\Integrations\Common\UnsupportedOperationException;
use App\Integrations\Contracts\BatteryInterface;
use App\Integrations\Contracts\BluetoothInterface;
use App\Integrations\Contracts\DeviceInfoInterface;
use App\Integrations\Contracts\LibraryPlaybackInterface;
use App\Integrations\Contracts\MediaControlsInterface;
use App\Integrations\Contracts\MultiRoomInterface;
use App\Integrations\Contracts\MusicPlayerDriverInterface;
use App\Integrations\Contracts\PowerInterface;
use App\Integrations\Contracts\RadioControlInterface;
use App\Integrations\Contracts\RepeatInterface;
use App\Integrations\Contracts\SeekInterface;
use App\Integrations\Contracts\SessionHostInterface;
use App\Integrations\Contracts\ShuffleInterface;
use App\Integrations\Contracts\SoundAdjustmentInterface;
use App\Integrations\Contracts\SourceActivationInterface;
use App\Integrations\Contracts\SourcesInterface;
use App\Integrations\Contracts\VolumeControlInterface;
use App\Models\Device;
use App\Models\Media\Playlist;
use App\Models\Media\Track;
use App\Models\RadioStation;
use Djneo92nl\BeoMozart\Enums\PlaybackCommand;
use Djneo92nl\BeoMozart\MozartClient;
use Illuminate\Support\Collection;

class MusicPlayerDriver implements BatteryInterface, BluetoothInterface, DeviceInfoInterface, LibraryPlaybackInterface, MediaControlsInterface, MultiRoomInterface, MusicPlayerDriverInterface, PowerInterface, RadioControlInterface, RepeatInterface, SeekInterface, SessionHostInterface, ShuffleInterface, SoundAdjustmentInterface, SourceActivationInterface, SourcesInterface, VolumeControlInterface
{
    use SettingsControls;

    public MozartClient $client;

    public function __construct(public Device $device)
    {
        $this->client = new MozartClient(
            $device->ip_address,
            config('mozart.rest_port', 8080),
            config('mozart.ws_port', 9000),
        );
    }

    // --- BatteryInterface ---

    public function getBattery(): ?BatteryStatus
    {
        return self::batteryFromMozart($this->client->power()->getBattery());
    }

    /**
     * BatteryState { batteryLevel, isCharging, state, … } as a status; null for
     * no answer or no battery (a mains-powered product reports `BatteryNotPresent`).
     */
    public static function batteryFromMozart(?array $state): ?BatteryStatus
    {
        if ($state === null || !isset($state['batteryLevel']) || ($state['state'] ?? null) === 'BatteryNotPresent') {
            return null;
        }

        return new BatteryStatus(
            max(0, min(100, (int) $state['batteryLevel'])),
            (bool) ($state['isCharging'] ?? false) || ($state['state'] ?? null) === 'Charging',
        );
    }

    // --- MediaControlsInterface ---
    // NOTE: Mozart's command enum names next/previous as "skip"/"prev".

    public function play(): void
    {
        $this->client->playback()->sendCommand(PlaybackCommand::Play);
    }

    public function pause(): void
    {
        $this->client->playback()->sendCommand(PlaybackCommand::Pause);
    }

    public function stop(): void
    {
        $this->client->playback()->sendCommand(PlaybackCommand::Stop);
    }

    public function next(): void
    {
        $this->client->playback()->sendCommand(PlaybackCommand::Skip);
    }

    public function previous(): void
    {
        $this->client->playback()->sendCommand(PlaybackCommand::Prev);
    }

    // --- SeekInterface ---

    public function seek(int $seconds): void
    {
        $this->client->playback()->seek(max(0, $seconds) * 1000);
    }

    // --- ShuffleInterface / RepeatInterface (PlayQueueSettings) ---
    // NOTE: Mozart names repeat-off "none" and repeat-one "track".

    public function setShuffle(bool $shuffle): void
    {
        $this->client->playback()->setQueueSettings(['shuffle' => $shuffle]);
    }

    public function setRepeat(RepeatMode $mode): void
    {
        $this->client->playback()->setQueueSettings(['repeat' => self::mozartRepeat($mode)]);
    }

    public static function mozartRepeat(RepeatMode $mode): string
    {
        return match ($mode) {
            RepeatMode::Off => 'none',
            RepeatMode::All => 'all',
            RepeatMode::One => 'track',
        };
    }

    public static function repeatModeFromMozart(mixed $repeat): ?RepeatMode
    {
        return match ($repeat) {
            'none' => RepeatMode::Off,
            'all' => RepeatMode::All,
            'track' => RepeatMode::One,
            default => null,
        };
    }

    // --- PowerInterface ---
    // NOTE: the Mozart spec has a standby endpoint but no power-on one.

    public function powerOn(): void
    {
        throw new UnsupportedOperationException('This device cannot be switched on remotely.');
    }

    public function standby(): void
    {
        $this->client->power()->standby();
    }

    // --- VolumeControlInterface ---

    public function setVolume(int $volume): int
    {
        $this->client->volume()->setLevel($volume);

        return $this->getVolume();
    }

    public function getVolume(): int
    {
        return Volume::remember($this->device->id, function () {
            $level = $this->client->volume()->getState()['level']['level'] ?? null;

            return $level === null ? null : (int) $level;
        });
    }

    public function incrementVolume(): void
    {
        $this->stepVolume(config('mozart.volume_step', 2));
    }

    public function decrementVolume(): void
    {
        $this->stepVolume(-config('mozart.volume_step', 2));
    }

    private function stepVolume(int $delta): void
    {
        $state = $this->client->volume()->getState() ?? [];
        $current = $state['level']['level'] ?? $this->getVolume();
        $maximum = $state['maximum']['level'] ?? 100;

        $target = max(0, min($maximum, $current + $delta));

        $this->setVolume($target);
    }

    public function mute(): void
    {
        $this->client->volume()->setMuted(true);
    }

    public function unmute(): void
    {
        $this->client->volume()->setMuted(false);
    }

    public function isMuted(): bool
    {
        return (bool) ($this->client->volume()->getState()['muted']['muted'] ?? false);
    }

    // --- SourcesInterface / SourceActivationInterface ---

    public function getSources(): array
    {
        $items = $this->client->sources()->list();
        $activeSourceId = $this->client->playback()->getState()['source']['id'] ?? null;

        return collect($items)
            ->map(fn (array $source) => new AvailableSource(
                sourceId: $source['id'] ?? '',
                friendlyName: $source['name'] ?? ($source['id'] ?? 'Unknown'),
                sourceType: $source['type']['value'] ?? 'unknown',
                // Mozart's Source schema has no per-source category field.
                category: 'MUSIC',
                inUse: $activeSourceId !== null && ($source['id'] ?? null) === $activeSourceId,
                borrowed: false,
                providerJid: null,
                providerName: null,
            ))
            ->values()
            ->all();
    }

    public function activateSource(string $sourceId): void
    {
        $this->client->sources()->activate($sourceId);
    }

    // --- MultiRoomInterface (Beolink) ---

    public function multiRoomMetaKey(): string
    {
        return 'mozart_jid';
    }

    public function getMultiRoomId(): ?string
    {
        return MultiRoomId::remember($this->device, 'mozart_jid', fn () => $this->client->beolink()->self()['jid'] ?? null);
    }

    public function getCurrentPeerIds(): array
    {
        return array_column($this->client->beolink()->listeners(), 'jid');
    }

    public function getJoinablePeerIds(): array
    {
        $available = array_column($this->client->beolink()->availableListeners(), 'jid');
        $current = $this->getCurrentPeerIds();

        return array_values(array_diff($available, $current));
    }

    public function addListener(string $peerId): void
    {
        $this->client->beolink()->expand($peerId);
    }

    public function joinSession(Device $hostDevice): void
    {
        $hostJid = $hostDevice->meta()->where('key', 'mozart_jid')->value('value');

        if (!$hostJid && $hostDevice->driver instanceof MultiRoomInterface) {
            $hostJid = $hostDevice->driver->getMultiRoomId();
        }

        if (!$hostJid) {
            return;
        }

        // Join is asynchronous server-side (confirmed via completion over
        // WebSocketEventBeolinkJoinResult); fire-and-forget for v1.
        $this->client->beolink()->join($hostJid);
    }

    public function leaveSession(): void
    {
        $this->client->beolink()->leave();
    }

    // --- LibraryPlaybackInterface ---

    public function playLibraryTrack(Track $track): void
    {
        $url = $track->getDlnaUrl();

        if (!$url) {
            throw new \RuntimeException("Track {$track->id} has no DLNA URL.");
        }

        $this->client->playback()->playUri($url);
    }

    public function playLibraryTracks(Collection $tracks): void
    {
        $playable = $tracks->filter(fn (Track $track) => (bool) $track->getDlnaUrl())->values();

        if ($playable->isEmpty()) {
            throw new \RuntimeException('No tracks with a playable DLNA URL.');
        }

        foreach ($playable as $i => $track) {
            $url = $track->getDlnaUrl();

            if ($i === 0) {
                $this->client->playback()->playUri($url);
            } else {
                $this->client->playback()->enqueue('track', 'dlna', $url);
            }
        }
    }

    public function playLibraryPlaylist(Playlist $playlist): void
    {
        $tracks = $playlist->tracks()->get();

        if ($tracks->isEmpty()) {
            throw new \RuntimeException("Playlist {$playlist->id} has no tracks.");
        }

        $this->playLibraryTracks($tracks);
    }

    // --- RadioControlInterface ---
    // NOTE: TuneIn is no longer used anywhere in the B&O ecosystem — all B&O
    // radio (ASE and Mozart alike) is unified under "BeoRadio", so this
    // reuses the same 'beoradio' RadioStationMeta key the ASE driver uses.

    public function radioPlatform(): string
    {
        return 'beoradio';
    }

    public function canPlayRadioStation(RadioStation $station): bool
    {
        return $station->getMeta('beoradio') !== null;
    }

    public function playRadioStation(RadioStation $station): void
    {
        $this->client->playback()->enqueue(
            type: 'track',
            providerValue: 'radio',
            uri: $station->getMeta('beoradio'),
            startNowFromPosition: 0,
        );
    }
}
