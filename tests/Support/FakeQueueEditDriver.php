<?php

namespace Tests\Support;

use App\Integrations\Contracts\LibraryPlaybackInterface;
use App\Integrations\Contracts\QueueAddInterface;
use App\Integrations\Contracts\QueueClearInterface;
use App\Integrations\Contracts\QueueMoveInterface;
use App\Integrations\Contracts\QueuePlayNextInterface;
use App\Integrations\Contracts\QueueRemoveInterface;
use App\Models\Media\Playlist;
use App\Models\Media\Track;
use Illuminate\Support\Collection;

/** FakePlayerDriver that can also edit its queue; records the calls like the rest. */
class FakeQueueEditDriver extends FakePlayerDriver implements LibraryPlaybackInterface, QueueAddInterface, QueueClearInterface, QueueMoveInterface, QueuePlayNextInterface, QueueRemoveInterface
{
    public function addToQueue(Collection $tracks): void
    {
        $this->record('addToQueue', $tracks->pluck('id')->all());
    }

    public function playNext(Collection $tracks): void
    {
        $this->record('playNext', $tracks->pluck('id')->all());
    }

    public function removeFromQueue(int $position, ?string $expect = null): void
    {
        $this->record('removeFromQueue', [$position, $expect]);
    }

    public function moveInQueue(int $from, int $to, ?string $expect = null): void
    {
        $this->record('moveInQueue', [$from, $to, $expect]);
    }

    public function clearQueue(): void
    {
        $this->record('clearQueue');
    }

    public function playLibraryTrack(Track $track): void
    {
        $this->record('playLibraryTrack', $track->id);
    }

    public function playLibraryTracks(Collection $tracks): void
    {
        $this->record('playLibraryTracks', $tracks->pluck('id')->all());
    }

    public function playLibraryPlaylist(Playlist $playlist): void
    {
        $this->record('playLibraryPlaylist', $playlist->id);
    }
}
