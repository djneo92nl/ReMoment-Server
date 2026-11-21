<?php

namespace App\Livewire;

use App\Domain\Device\DeviceCapabilities;
use App\Domain\Scenes\DeviceTimers;
use App\Mcp\ApiBridge;
use App\Models\Device;
use App\Models\DeviceTimer;
use Livewire\Component;

/**
 * The sleep timer card on /scenes (docs/api/scenes.md): pick a device and minutes, the volume fades and the device
 * stops (or goes to standby) at the end, then the volume is put back. It goes through the REST endpoints
 * (`/devices/{id}/sleep`) like the MCP tool, so the capability checks and limits are the API's.
 */
class SleepTimerCard extends Component
{
    public string $deviceId = '';

    public string $minutes = '30';

    public string $fadeSeconds = '60';

    public string $end = 'stop';

    public ?string $feedback = null;

    public bool $feedbackIsError = false;

    public function start(ApiBridge $bridge): void
    {
        if (!ctype_digit($this->deviceId)) {
            $this->say('Choose a device.', true);

            return;
        }
        if (!ctype_digit($this->minutes) || !ctype_digit($this->fadeSeconds)) {
            $this->say('Minutes and fade are whole numbers.', true);

            return;
        }

        [$status, $body] = $bridge->call('POST', "devices/{$this->deviceId}/sleep", [
            'minutes' => (int) $this->minutes, 'fade_seconds' => (int) $this->fadeSeconds, 'action' => $this->end,
        ]);

        if ($status === 201) {
            $name = Device::find((int) $this->deviceId)?->device_name;
            $this->say("Sleep timer set on {$name}: {$this->minutes} minutes.");

            return;
        }

        $detail = collect($body['errors'] ?? [])->flatten()->first();
        $this->say($detail ?? ($body['message'] ?? 'The sleep timer could not be set.'), true);
    }

    public function cancel(int $deviceId, ApiBridge $bridge): void
    {
        $bridge->call('DELETE', "devices/{$deviceId}/sleep");
        $this->say('Sleep timer cancelled.');
    }

    private function say(string $message, bool $error = false): void
    {
        $this->feedback = $message;
        $this->feedbackIsError = $error;
    }

    public function render()
    {
        $devices = Device::orderBy('device_name')->get(['id', 'device_name', 'device_driver', 'device_brand_name', 'device_product_type'])
            ->filter(fn (Device $d) => in_array('media_controls', DeviceCapabilities::for($d), true));

        $active = DeviceTimer::with('device:id,device_name')->where('kind', DeviceTimers::SLEEP)->where('status', 'active')->orderBy('ends_at')->get();

        return view('livewire.sleep-timer-card', ['devices' => $devices, 'active' => $active]);
    }
}
