<?php

namespace App\Livewire;

use Livewire\Component;

class Nowplaying extends Component
{
    public $device;

    public $volume = 2; // default volume 0-100

    public function mount($device)
    {
        $this->device = $device;
        $this->volume = $device->driver->getVolume();
    }

    public function render()
    {
        return view('livewire.nowplaying');
    }

    public function updatedVolume($value)
    {
        $this->device->driver->setVolume($value);
        $this->volume = $value;
    }

    public function play()
    {
        $this->device->driver->play();
    }

    public function pause()
    {
        $this->device->driver->pause();
    }

    public function next()
    {
        $this->device->driver->next();
    }

    public function previous()
    {
        $this->device->driver->previous();
    }

    public function standby()
    {
        $this->device->driver->standby();
    }
}
