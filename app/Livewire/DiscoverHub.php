<?php

namespace App\Livewire;

use App\Domain\Library\Discover;
use App\Domain\Library\LeadingSource;
use App\Domain\Library\LibraryPlayback;
use App\Models\Media\Track;
use App\Support\SelectedDevice;
use Livewire\Component;

/**
 * The Discover hub (`/library/discover`, docs/frontend/discover.md): every shelf of `Domain\Library\Discover` on one page, the
 * same data as `GET /api/library/discover`. Rendered by the section's `kind`, so a new section needs no change here. Track
 * rows play on the player pinned in this browser; the random picks have their own seed, so "Another pick" rolls again without
 * touching the other shelves.
 */
class DiscoverHub extends Component
{
    public string $scope = 'local';

    /** Seed of the random picks. */
    public int $seed = 0;

    public ?string $notice = null;

    public function mount(string $scope = 'local'): void
    {
        $this->scope = $scope;
        $this->seed = random_int(1, 1_000_000);
    }

    public function anotherPick(): void
    {
        $this->seed = random_int(1, 1_000_000);
    }

    /** Plays a library track on the player pinned in this browser. */
    public function playTrack(int $trackId): void
    {
        $this->notice = null;
        $device = app(SelectedDevice::class)->device();
        $track = Track::find($trackId);

        if ($device === null) {
            $this->notice = 'Pick a player in the bar below first.';

            return;
        }
        if ($track === null) {
            return;
        }

        try {
            app(LibraryPlayback::class)->playTrack($device, $track);
        } catch (\Throwable $e) {
            $this->notice = $e->getMessage();
        }
    }

    public function render()
    {
        return view('livewire.discover-hub', [
            'sections' => Discover::sections(LeadingSource::hidden($this->scope), seed: $this->seed),
        ]);
    }
}
