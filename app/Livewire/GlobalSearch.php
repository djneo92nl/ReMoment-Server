<?php

namespace App\Livewire;

use App\Domain\Library\LibraryIdentity;
use App\Domain\Library\Normalizer;
use App\Domain\Library\SpotifyCatalog;
use App\Domain\Library\SpotifyUri;
use App\Livewire\Concerns\PlaysExternal;
use App\Livewire\Concerns\WantsStreamingTracks;
use App\Models\Media\Album;
use App\Models\Media\Artist;
use App\Models\Media\Playlist;
use App\Models\Media\Track;
use Livewire\Component;

class GlobalSearch extends Component
{
    use PlaysExternal, WantsStreamingTracks;

    public string $query = '';

    public function render()
    {
        $artists = collect();
        $albums = collect();
        $tracks = collect();
        $playlists = collect();
        $spotify = null;

        $term = trim($this->query);

        if (mb_strlen($term) >= 2) {
            $artists = Artist::where('name', 'like', "%{$term}%")->orderBy('name')->limit(5)->get();
            $albums = Album::where('name', 'like', "%{$term}%")->with('artist')->orderBy('name')->limit(5)->get();
            $tracks = Track::where('name', 'like', "%{$term}%")->with('artist')->orderBy('name')->limit(5)->get();
            $playlists = Playlist::where('name', 'like', "%{$term}%")->orderBy('name')->limit(5)->get();
            $spotify = $this->spotifyResults($term);
        }

        return view('livewire.global-search', compact('artists', 'albums', 'tracks', 'playlists', 'spotify'));
    }

    /**
     * Spotify's artists and albums for the term, each with its library match (if any), so
     * the dropdown can link what the library has; the rest is played from Spotify (nothing is added to the library).
     *
     * @return array{artists: list<array>, albums: list<array>, tracks: list<array>}|null null when Spotify isn't connected or fails
     */
    private function spotifyResults(string $term): ?array
    {
        $catalog = app(SpotifyCatalog::class);

        if (mb_strlen($term) < 3 || !$catalog->available()) {
            return null;
        }

        try {
            $results = $catalog->search($term);
        } catch (\Throwable) {
            return null;
        }

        // Spotify also returns loosely related artists; keep those whose name contains the term, once each.
        $key = Normalizer::artist($term);
        $artists = collect($results['artists'])
            ->filter(fn ($a) => str_contains(Normalizer::artist($a['name']), $key))
            ->unique(fn ($a) => Normalizer::artist($a['name']))
            ->values()->all();

        return [
            'artists' => array_map(fn ($a) => $a + ['local' => SpotifyCatalog::localArtist($a['name'])], $artists),
            'albums' => array_map(fn ($a) => $a + ['local' => SpotifyCatalog::localAlbum($a['name'], $a['artist'])], $results['albums']),
            'tracks' => array_map(fn ($t) => $t + [
                'local' => LibraryIdentity::findByExternalId(SpotifyUri::track($t['id']), 'spotify'),
                'wished' => \App\Domain\Catalogue\WantedTracks::isWished($t['artist'], $t['name']),
            ], $results['tracks'] ?? []),
        ];
    }

    public function clear(): void
    {
        $this->query = '';
    }
}
