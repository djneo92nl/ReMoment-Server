<?php

namespace App\Livewire;

use App\Domain\Device\DeviceCapabilities;
use App\Integrations\Contracts\DigitsInterface;
use App\Integrations\Contracts\SleepTimerInterface;
use App\Models\Device;
use Livewire\Component;

/**
 * The "Also" card of a TV's page: number keys for the active TV or set-top box source and the sleep timer.
 * Each part exists only when the device reports the capability (`digits`, `sleep_timer`); the full settings
 * page has the same controls for every device.
 */
class TvExtras extends Component
{
    public Device $device;

    /** @var string[] */
    public array $capabilities = [];

    /** @var array{value: int, writable: bool}|null */
    public ?array $sleepTimer = null;

    public ?string $error = null;

    public function mount(Device $device): void
    {
        $this->device = $device;
        $this->capabilities = array_values(array_intersect(['digits', 'sleep_timer'], DeviceCapabilities::for($device)));
        $this->readSleepTimer();
    }

    public function pressDigit(int|string $digit): void
    {
        $this->run(DigitsInterface::class, fn (DigitsInterface $driver) => $driver->sendDigit((int) $digit));
    }

    public function setSleepTimer(int|string $minutes): void
    {
        $this->run(SleepTimerInterface::class, fn (SleepTimerInterface $driver) => $driver->setSleepTimer((int) $minutes));
        $this->readSleepTimer();
    }

    public function render()
    {
        return view('livewire.tv-extras');
    }

    private function readSleepTimer(): void
    {
        $this->sleepTimer = null;

        if (!in_array('sleep_timer', $this->capabilities, true)) {
            return;
        }

        try {
            $driver = $this->device->driver;
            if ($driver instanceof SleepTimerInterface) {
                $timer = $driver->getSleepTimer();
                $this->sleepTimer = ['value' => $timer->minutes->value, 'writable' => $timer->writable];
            }
        } catch (\Throwable) {
            // Unreachable or in standby: the card shows no timer rather than a wrong one.
        }
    }

    private function run(string $contract, \Closure $command): void
    {
        try {
            $driver = $this->device->driver;
            if ($driver instanceof $contract) {
                $command($driver);
            }
            $this->error = null;
        } catch (\Throwable $e) {
            $this->error = $e->getMessage();
        }
    }
}
