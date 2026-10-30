<?php

namespace App\Livewire;

use App\Domain\Artwork\LibraryItemArtwork;
use App\Domain\Artwork\NowPlayingArtwork;
use App\Domain\Device\Cache\Modes;
use App\Domain\Device\Cache\MultiRoom;
use App\Domain\Device\Cache\Volume;
use App\Domain\Device\DeviceCache;
use App\Domain\Device\DeviceCapabilities;
use App\Domain\Device\RepeatMode;
use App\Domain\Device\SpotifyRouting;
use App\Domain\Device\State;
use App\Domain\Library\NowPlayingSections;
use App\Domain\Library\NowPlayingWaveform;
use App\Events\Device\PlaybackModesUpdated;
use App\Integrations\Contracts\LikeInterface;
use App\Integrations\Contracts\QueueInterface;
use App\Integrations\Contracts\QueueJumpInterface;
use App\Integrations\Contracts\RepeatInterface;
use App\Integrations\Contracts\ShuffleInterface;
use App\Integrations\Contracts\VolumeControlInterface;
use App\Livewire\Concerns\ControlsPlayback;
use App\Livewire\Concerns\EditsQueue;
use App\Livewire\Concerns\ManagesMultiroom;
use App\Livewire\Concerns\MovesPlayback;
use App\Models\Device;
use App\Support\SelectedDevice;
use Illuminate\Support\Facades\Cache;
use Livewire\Component;

/** The sticky bottom player: the pinned device's now playing, in the artwork's colors, with its controls. */
class PlayerBar extends Component
{
    use ControlsPlayback;
    use EditsQueue;
    use ManagesMultiroom;
    use MovesPlayback;

    /** The panel floating above the bar: 'queue', 'players', 'multiroom' or 'move'; null while closed. */
    public ?string $panel = null;

    public function togglePanel(string $panel): void
    {
        $this->panel === $panel ? $this->closePanel() : $this->openPanel($panel);
    }

    public function openPanel(string $panel): void
    {
        if (!in_array($panel, ['queue', 'players', 'multiroom', 'move'], true)) {
            return;
        }
        // Queue and multiroom are about the pinned device: with none pinned only the picker exists.
        if ($panel !== 'players' && $this->device() === null) {
            return;
        }
        $this->panel = $panel;
        if ($panel === 'move') {
            $this->moved = $this->moveError = null;
        }
        if ($panel === 'multiroom') {
            $this->loadMultiRoomData();
        }
    }

    public function closePanel(): void
    {
        $this->panel = null;
    }

    /** Plays the queue entry `$position` places ahead (1 = the first of Up next). */
    public function jumpToQueue(int $position): void
    {
        $this->control(QueueJumpInterface::class, fn ($d) => $d->skipToQueuePosition($position));
    }

    /** @return array{items: array<int, array<string, mixed>>, error: ?string} */
    private function queue(Device $device, State $state): array
    {
        if ($state === State::Unreachable) {
            return ['items' => [], 'error' => null];
        }

        try {
            $driver = SpotifyRouting::driverFor($device, QueueInterface::class);
            $items = $driver instanceof QueueInterface
                ? array_map(function ($item) {
                    $row = $item->toArray();
                    $row['image'] = LibraryItemArtwork::proxy($row['image'], 120);

                    return $row;
                }, $driver->getUpNext(30))
                : [];

            return ['items' => $items, 'error' => null];
        } catch (\Throwable) {
            return ['items' => [], 'error' => 'Could not read the queue from the device.'];
        }
    }

    public function toggleShuffle(): void
    {
        $device = $this->device();
        if (!$device) {
            return;
        }
        $modesId = SpotifyRouting::modesDeviceId($device);
        $modes = Modes::get($modesId);
        $shuffle = !($modes->shuffle ?? false);
        $this->control(ShuffleInterface::class, fn ($d) => $d->setShuffle($shuffle));
        $this->recordModes($modesId, $modes->withShuffle($shuffle));
    }

    public function cycleRepeat(): void
    {
        $device = $this->device();
        if (!$device) {
            return;
        }
        $modesId = SpotifyRouting::modesDeviceId($device);
        $modes = Modes::get($modesId);
        $repeat = match ($modes->repeat) {
            RepeatMode::Off, null => RepeatMode::All,
            RepeatMode::All => RepeatMode::One,
            RepeatMode::One => RepeatMode::Off,
        };
        $this->control(RepeatInterface::class, fn ($d) => $d->setRepeat($repeat));
        $this->recordModes($modesId, $modes->withRepeat($repeat));
    }

    public function toggleLike(): void
    {
        $device = $this->device();
        if (!$device) {
            return;
        }
        $modesId = SpotifyRouting::modesDeviceId($device);
        $modes = Modes::get($modesId);
        $liked = !($modes->liked ?? false);
        $this->control(LikeInterface::class, fn ($d) => $d->setLiked($liked));
        $this->recordModes($modesId, $modes->withLiked($liked));
    }

    private function recordModes(int $modesId, \App\Domain\Device\PlaybackModes $modes): void
    {
        if ($this->controlError === null) {
            event(new PlaybackModesUpdated((string) $modesId, $modes, routed: SpotifyRouting::routedDeviceId() === $modesId));
        }
    }

    protected function multiroomDevice(): Device
    {
        return $this->device() ?? throw new \LogicException('No player is pinned.');
    }

    protected function moveSourceDevice(): Device
    {
        return $this->device() ?? throw new \LogicException('No player is pinned.');
    }

    protected function queueDevice(): ?Device
    {
        return $this->playbackDevice();
    }

    protected function playbackDevice(): ?Device
    {
        return $this->device();
    }

    private function device(): ?Device
    {
        return app(SelectedDevice::class)->device();
    }

    public function render()
    {
        $device = $this->device();
        if ($device === null) {
            return view('livewire.player-bar', ['device' => null, 'players' => $this->panel === 'players' ? $this->players() : null]);
        }

        $state = DeviceCache::isListenerRunning($device->id) ? $device->state : State::Unreachable;
        $nowPlaying = DeviceCache::getNowPlaying($device->id);
        $capabilities = DeviceCapabilities::for($device);
        $modes = Modes::get(SpotifyRouting::modesDeviceId($device));

        return view('livewire.player-bar', [
            'device' => $device,
            'state' => $state,
            'nowPlaying' => $nowPlaying,
            'artwork' => $nowPlaying ? NowPlayingArtwork::resolve($nowPlaying) : null,
            'waveform' => $nowPlaying ? NowPlayingWaveform::for($nowPlaying) : null,
            'sections' => $nowPlaying ? (NowPlayingSections::for($nowPlaying)['sections'] ?? null) ?: null : null,
            'capabilities' => $capabilities,
            'modes' => $modes,
            'volume' => $this->volume($device, $capabilities, $state),
            'muted' => Volume::getMuted($device->id) ?? false,
            'group' => $this->multiroomGroup($device),
            'players' => $this->panel === 'players' ? $this->players() : null,
            'queue' => $this->panel === 'queue' ? $this->queue($device, $state) : null,
            'moveTargets' => $this->panel === 'move' ? $this->moveTargets() : collect(),
            'canMove' => in_array('move_playback', $capabilities, true) || $this->panel === 'move',
            'supportsMultiRoom' => in_array('multi_room', $capabilities, true) && $state !== State::Unreachable,
        ]);
    }

    /** Players to pick: the pinned one first, then the playing ones, then the other reachable ones; unreachable ones are left out. */
    private function players()
    {
        $pinned = $this->device()?->id;

        return Device::where('hidden', false)->orderBy('device_name')->get()
            ->reject(fn (Device $d) => $d->id !== $pinned && $d->liveState() === State::Unreachable)
            ->sortBy(fn (Device $d) => $d->id === $pinned ? 0 : ($d->liveState() === State::Playing ? 1 : 2))
            ->values();
    }

    /**
     * The device's volume: from the cache the listener keeps, else asked from the device once (the cache
     * expires after a few minutes without a change). Null while unknown.
     *
     * @param  string[]  $capabilities
     */
    private function volume(Device $device, array $capabilities, State $state): ?int
    {
        $volume = Volume::getVolume($device->id);
        if ($volume !== false || !in_array('volume_control', $capabilities, true) || $state === State::Unreachable) {
            return $volume !== false ? (int) $volume : null;
        }

        $missKey = "player_bar_volume_miss_{$device->id}";
        if (Cache::has($missKey)) {
            return null;
        }

        try {
            $driver = $device->driver;
            $volume = $driver instanceof VolumeControlInterface ? (int) $driver->getVolume() : null;
        } catch (\Throwable) {
            $volume = null;
        }

        if ($volume === null) {
            Cache::put($missKey, true, 30);

            return null;
        }

        Volume::updateVolume($device->id, $volume);

        return $volume;
    }

    /**
     * Names of every player in the pinned device's multiroom session (the device itself first),
     * or [] when it isn't in one. A listener knows only its host, so the rest comes from the host's status.
     *
     * @return string[]
     */
    private function multiroomGroup(Device $device): array
    {
        $status = MultiRoom::get($device->id)?->resolve($device);
        if ($status === null || $status['role'] === 'standalone') {
            return [];
        }

        $others = collect($status['listeners']);
        if ($status['host'] !== null) {
            $host = Device::find($status['host']['id']);
            $hostListeners = $host ? (MultiRoom::get($host->id)?->resolve($host)['listeners'] ?? []) : [];
            $others = collect([$status['host']])->merge($hostListeners)->reject(fn ($d) => $d['id'] === $device->id);
        }

        return $others->unique('id')->pluck('name')->prepend($device->device_name)->values()->all();
    }
}
