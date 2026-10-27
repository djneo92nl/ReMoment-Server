<?php

namespace Tests\Support;

use App\Integrations\Contracts\LibraryPlaybackInterface;
use App\Integrations\Contracts\RadioControlInterface;
use App\Models\Media\Playlist;
use App\Models\Media\Track;
use App\Models\RadioStation;
use Illuminate\Support\Collection;

/** FakePlayerDriver that can also be the target of a move: plays library tracks and radio stations, recording the calls. */
class FakeMovableDriver extends FakePlayerDriver implements LibraryPlaybackInterface, RadioControlInterface
{
    public function playLibraryTrack(Track $track): void
    {
        $this->record('playLibraryTrack', $track->id);
    }

    public function playLibraryTracks(Collection $tracks): void
    {
        $this->record('playLibraryTracks', $tracks->filter(fn (Track $t) => (bool) $t->getDlnaUrl())->pluck('id')->all());
    }

    public function playLibraryPlaylist(Playlist $playlist): void {}

    public function radioPlatform(): string
    {
        return 'fakeradio';
    }

    public function canPlayRadioStation(RadioStation $station): bool
    {
        return true;
    }

    public function playRadioStation(RadioStation $station): void
    {
        $this->record('playRadioStation', $station->id);
    }
}
