<?php

namespace App\Livewire;

use App\Domain\Device\DeviceCapabilities;
use App\Domain\Scenes\SceneRunner;
use App\Models\Device;
use App\Models\Scene;
use Livewire\Attributes\Locked;
use Livewire\Component;

/**
 * "Add to scene" on the album, artist and playlist pages (workstream 8.1b): appends a play step for this record
 * (`playAlbum`, `playArtist`, `playPlaylist`) on a chosen device to an existing scene, or to a new one. The step is
 * validated by `SceneRunner::validate`, the same check as the API's.
 */
class AddToScene extends Component
{
    /** type => [operation, the input that holds the id] */
    private const OPERATIONS = ['album' => ['playAlbum', 'album_id'], 'artist' => ['playArtist', 'artist_id'], 'playlist' => ['playPlaylist', 'playlist_id']];

    #[Locked]
    public string $type = 'album';

    #[Locked]
    public int $selectionId = 0;

    public bool $open = false;

    /** An existing scene's id, or "new" */
    public string $sceneChoice = '';

    public string $newName = '';

    public string $deviceId = '';

    public bool $shuffle = false;

    public ?string $message = null;

    public bool $messageIsError = false;

    public ?int $addedTo = null;

    public function mount(string $type, int $id): void
    {
        abort_unless(isset(self::OPERATIONS[$type]), 404);
        $this->type = $type;
        $this->selectionId = $id;
    }

    public function toggle(): void
    {
        $this->open = !$this->open;
        $this->message = null;
        $this->addedTo = null;
    }

    public function add(SceneRunner $runner): void
    {
        $this->message = null;
        $this->addedTo = null;

        $this->validate([
            'sceneChoice' => 'required',
            'newName' => 'required_if:sceneChoice,new|nullable|string|max:120',
            'deviceId' => 'required|integer|exists:devices,id',
        ], [
            'sceneChoice.required' => 'Choose a scene.',
            'newName.required_if' => 'Name the new scene.',
            'deviceId.required' => 'Choose a device.',
        ]);

        [$operation, $input] = self::OPERATIONS[$this->type];
        $params = [$input => $this->selectionId];
        if ($this->shuffle) {
            $params['shuffle'] = true;
        }
        $step = ['operation' => $operation, 'device_id' => (int) $this->deviceId, 'params' => $params];

        $scene = $this->sceneChoice === 'new' ? null : Scene::find((int) $this->sceneChoice);
        if ($this->sceneChoice !== 'new' && !$scene) {
            $this->fail('That scene no longer exists.');

            return;
        }

        $steps = [...($scene?->steps ?? []), $step];
        if ($errors = $runner->validate($steps)) {
            $this->fail(implode(' ', $errors));

            return;
        }

        $steps = SceneRunner::normalise($steps);
        $scene ? $scene->update(['steps' => $steps]) : $scene = Scene::create(['name' => trim($this->newName), 'steps' => $steps]);

        $this->addedTo = $scene->id;
        $this->message = "Added to \"{$scene->name}\".";
        $this->messageIsError = false;
        $this->sceneChoice = (string) $scene->id;
        $this->newName = '';
    }

    private function fail(string $message): void
    {
        $this->message = $message;
        $this->messageIsError = true;
    }

    public function render()
    {
        $devices = Device::orderBy('device_name')->get(['id', 'device_name', 'device_driver', 'device_brand_name', 'device_product_type', 'ip_address'])
            ->filter(fn (Device $d) => in_array('library_playback', DeviceCapabilities::for($d), true))
            ->values();

        return view('livewire.add-to-scene', ['scenes' => Scene::orderBy('name')->get(['id', 'name']), 'devices' => $devices]);
    }
}
