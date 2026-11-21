<?php

namespace App\Livewire;

use App\Domain\Library\LibraryPlayback;
use App\Domain\Resume\PlaybackPositions;
use App\Domain\Resume\PositionItems;
use App\Models\Media\Track;
use App\Models\PlaybackPosition;
use App\Support\SelectedDevice;
use Livewire\Component;

/**
 * "Continue listening" on the library page (workstream 8.3, docs/frontend/continue-listening.md): the audiobooks, long tracks
 * and podcast episodes that have a bookmark, with Resume (the server seeks the pinned player to the position) and a way to
 * forget one. Household-wide, no login: it is playback, like the rest of the page. Renders nothing without bookmarks.
 */
class ContinueListening extends Component
{
    public ?string $notice = null;

    public function resume(string $kind, int $id): void
    {
        $this->notice = null;
        $device = app(SelectedDevice::class)->device();

        if ($device === null) {
            $this->notice = 'Pick a player in the bar below first.';

            return;
        }

        try {
            if ($kind === PlaybackPosition::TRACK && ($track = Track::find($id))) {
                app(LibraryPlayback::class)->playTrack($device, $track);   // resumes at the bookmark by itself
            }
        } catch (\Throwable $e) {
            $this->notice = $e->getMessage();
        }
    }

    public function clear(string $kind, int $id): void
    {
        if (in_array($kind, PlaybackPosition::KINDS, true)) {
            PlaybackPositions::clear($kind, $id);
        }
    }

    public function render()
    {
        return view('livewire.continue-listening', ['rows' => PositionItems::recent(12)]);
    }
}
