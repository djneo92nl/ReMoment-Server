<?php

namespace App\Livewire;

use App\Domain\Library\PlaylistEditor;
use App\Models\Media\Playlist;
use App\Models\Media\Track;
use Livewire\Component;

class PlaylistManager extends Component
{
    public Playlist $playlist;

    public string $trackSearch = '';

    public string $editName = '';

    public bool $renaming = false;

    public function mount(Playlist $playlist): void
    {
        $this->playlist = $playlist;
        $this->editName = $playlist->name;
    }

    public function render()
    {
        $tracks = $this->playlist->tracks()->with('artist')->get();

        $searchResults = collect();
        $query = trim($this->trackSearch);
        if (mb_strlen($query) >= 2) {
            $searchResults = Track::where('name', 'like', '%'.$query.'%')
                ->whereNotIn('id', $tracks->pluck('id'))
                ->with('artist')
                ->orderBy('name')
                ->limit(10)
                ->get();
        }

        return view('livewire.playlist-manager', compact('tracks', 'searchResults'));
    }

    public function rename(PlaylistEditor $editor): void
    {
        if ($editor->rename($this->playlist, $this->editName)) {
            $this->renaming = false;
        }
    }

    public function addTrack(int $trackId, PlaylistEditor $editor): void
    {
        $editor->addTrack($this->playlist, $trackId);
        $this->trackSearch = '';
    }

    public function removeTrack(int $trackId, PlaylistEditor $editor): void
    {
        $editor->removeTrack($this->playlist, $trackId);
    }

    public function moveTrack(int $trackId, int $direction, PlaylistEditor $editor): void
    {
        $editor->moveTrack($this->playlist, $trackId, $direction);
    }
}
