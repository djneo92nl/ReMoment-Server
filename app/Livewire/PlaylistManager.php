<?php

namespace App\Livewire;

use App\Models\Media\Playlist;
use App\Models\Media\Track;
use Illuminate\Support\Facades\DB;
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

    public function rename(): void
    {
        $name = trim($this->editName);
        if ($name === '') {
            return;
        }

        $this->playlist->update(['name' => $name]);
        $this->renaming = false;
    }

    public function addTrack(int $trackId): void
    {
        $this->playlist->tracks()->syncWithoutDetaching([$trackId => ['position' => $this->nextPosition()]]);
        $this->trackSearch = '';
    }

    public function removeTrack(int $trackId): void
    {
        $this->playlist->tracks()->detach($trackId);
        $this->resequence();
    }

    public function moveTrack(int $trackId, int $direction): void
    {
        $ids = DB::table('playlist_track')
            ->where('playlist_id', $this->playlist->id)
            ->orderBy('position')
            ->pluck('track_id')
            ->toArray();

        $pos = array_search($trackId, $ids);
        $target = $pos + $direction;

        if ($pos === false || $target < 0 || $target >= count($ids)) {
            return;
        }

        [$ids[$pos], $ids[$target]] = [$ids[$target], $ids[$pos]];

        foreach ($ids as $order => $id) {
            DB::table('playlist_track')
                ->where('playlist_id', $this->playlist->id)
                ->where('track_id', $id)
                ->update(['position' => $order]);
        }
    }

    private function nextPosition(): int
    {
        $max = DB::table('playlist_track')->where('playlist_id', $this->playlist->id)->max('position');

        return $max === null ? 0 : $max + 1;
    }

    private function resequence(): void
    {
        $ids = DB::table('playlist_track')
            ->where('playlist_id', $this->playlist->id)
            ->orderBy('position')
            ->pluck('track_id');

        foreach ($ids as $order => $id) {
            DB::table('playlist_track')
                ->where('playlist_id', $this->playlist->id)
                ->where('track_id', $id)
                ->update(['position' => $order]);
        }
    }
}
