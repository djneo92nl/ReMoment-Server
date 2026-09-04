<?php

namespace App\Livewire;

use App\Models\Media\Album;
use App\Models\Media\Artist;
use App\Models\Media\Playlist;
use App\Models\Media\Track;
use Livewire\Component;

class GlobalSearch extends Component
{
    public string $query = '';

    public function render()
    {
        $artists = collect();
        $albums = collect();
        $tracks = collect();
        $playlists = collect();

        $term = trim($this->query);

        if (mb_strlen($term) >= 2) {
            $artists = Artist::where('name', 'like', "%{$term}%")->orderBy('name')->limit(5)->get();
            $albums = Album::where('name', 'like', "%{$term}%")->with('artist')->orderBy('name')->limit(5)->get();
            $tracks = Track::where('name', 'like', "%{$term}%")->with('artist')->orderBy('name')->limit(5)->get();
            $playlists = Playlist::where('name', 'like', "%{$term}%")->orderBy('name')->limit(5)->get();
        }

        return view('livewire.global-search', compact('artists', 'albums', 'tracks', 'playlists'));
    }

    public function clear(): void
    {
        $this->query = '';
    }
}
