<?php

namespace App\Livewire;

use App\Domain\Radio\StationNowPlaying;
use App\Domain\Radio\StreamTitles;
use App\Models\RadioStation;
use Livewire\Component;

/** The title a station plays now, on its tile: polling keeps the station "listened to", so it fills without a device. */
class RadioNowPlaying extends Component
{
    public int $stationId;

    /** Shown while the station has no title (its genre, "Live"). */
    public ?string $fallback = null;

    /** On a tinted card: the muted ink of the tint instead of grey. */
    public bool $tinted = false;

    public function render()
    {
        $station = RadioStation::with('meta')->find($this->stationId);
        $now = null;

        if ($station !== null) {
            StreamTitles::touch($station);
            $now = StationNowPlaying::for($station);
        }

        return view('livewire.radio-now-playing', ['now' => $now]);
    }
}
