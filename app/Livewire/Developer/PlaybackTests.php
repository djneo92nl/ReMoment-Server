<?php

namespace App\Livewire\Developer;

use App\Domain\Developer\CallTimings;
use App\Domain\Developer\QualityPlayer;
use App\Domain\Developer\Tones;
use App\Domain\Device\DeviceCache;
use App\Domain\Device\DeviceCapabilities;
use App\Domain\Library\LibraryPlayback;
use App\Domain\Storage\Proxies;
use App\Domain\Storage\StreamUrl;
use App\Integrations\Contracts\PlayStreamUrlInterface;
use App\Integrations\Contracts\SeekInterface;
use App\Models\Device;
use App\Models\Media\Track;
use Livewire\Component;

/** /settings/developer: test signals, seeking and a gapless pair on one player. */
class PlaybackTests extends Component
{
    use PicksTrack;

    public ?int $deviceId = null;

    public string $profile = '';

    public int $seconds = 60;

    public ?array $result = null;

    public function tone(string $name): void
    {
        $device = $this->device();

        if (!$device || !Tones::known($name)) {
            $this->fail('Choose a device and a tone.');

            return;
        }

        $this->timed($device, "tone {$name}", function () use ($device, $name) {
            $driver = $device->driver;

            if (!$driver instanceof PlayStreamUrlInterface) {
                throw new \RuntimeException(class_basename($driver).' cannot play a stream URL.');
            }

            Tones::ensure($name);
            $url = Tones::url($name);
            $driver->playStreamUrl($url, Tones::all()[$name]['label']);

            return "Sent {$url}";
        });
    }

    public function seekPercent(int $percent): void
    {
        $duration = $this->device() ? DeviceCache::getNowPlaying($this->deviceId)?->track?->duration : null;

        if (!$duration) {
            $this->fail('The device reports no track duration; start something first.');

            return;
        }

        $this->seekTo((int) round($duration * $percent / 100));
    }

    public function seekTo(int $seconds): void
    {
        $device = $this->device();

        if (!$device) {
            $this->fail('Choose a device first.');

            return;
        }

        $this->timed($device, "seek {$seconds}s", function () use ($device, $seconds) {
            $driver = $device->driver;

            if (!$driver instanceof SeekInterface) {
                throw new \RuntimeException(class_basename($driver).' cannot seek.');
            }

            $driver->seek(max(0, $seconds));

            return "Seek to {$seconds} s sent.";
        });
    }

    /** The chosen track and the one after it on its album, as one queue: listen for a gap or a glitch at the join. */
    public function gapless(): void
    {
        $device = $this->device();
        $track = $this->track();

        if (!$device || !$track) {
            $this->fail('Choose a track and a device first.');

            return;
        }

        $pair = Track::where('album_id', $track->album_id)->where('id', '!=', $track->id)->orderBy('disc_number')->orderBy('track_number')->orderBy('id')->get()
            ->filter(fn (Track $t) => $t->isOwnFile())
            ->prepend($track)->take(2)->values();

        if ($pair->count() < 2) {
            $this->fail('That track has no other own-file track on its album.');

            return;
        }

        $profile = $this->profile !== '' ? $this->profile : null;

        $this->timed($device, 'gapless pair '.($profile ?? 'original'), function () use ($device, $pair, $profile) {
            if ($profile !== null) {
                $pair->each(fn (Track $t) => QualityPlayer::prepare($t, $profile));
            }

            StreamUrl::withProfile($profile, fn () => app(LibraryPlayback::class)->playTracks($device, $pair, $pair->first()));

            return 'Queued "'.$pair[0]->name.'" then "'.$pair[1]->name.'". '.($profile ? 'Only drivers that build every URL at once (not UPnP, which preloads the next one later) carry the quality to the second track.' : '');
        });
    }

    private function timed(Device $device, string $action, \Closure $call): void
    {
        $started = microtime(true);

        try {
            $message = $call();
            $ms = (int) round((microtime(true) - $started) * 1000);
            CallTimings::record($device->id, $action, $ms, true);
            $this->result = ['ok' => true, 'message' => "{$message} ({$ms} ms)"];
        } catch (\Throwable $e) {
            CallTimings::record($device->id, $action, (int) round((microtime(true) - $started) * 1000), false);
            $this->fail(class_basename($e).': '.$e->getMessage());
        }
    }

    private function fail(string $message): void
    {
        $this->result = ['ok' => false, 'message' => $message];
    }

    private function device(): ?Device
    {
        return $this->deviceId ? Device::find($this->deviceId) : null;
    }

    public function render()
    {
        return view('livewire.developer.playback-tests', [
            'tracks' => $this->foundTracks(),
            'track' => $this->track(),
            'devices' => Device::orderBy('device_name')->get(),
            'canStreamUrl' => ($d = $this->device()) ? in_array('stream_url', DeviceCapabilities::for($d), true) : false,
            'canSeek' => ($d = $this->device()) ? in_array('seek', DeviceCapabilities::for($d), true) : false,
            'tones' => Tones::all(),
            'profiles' => Proxies::profiles(),
            'ffmpeg' => Proxies::enabled(),
        ]);
    }
}
