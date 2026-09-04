<?php

namespace App\Http\Controllers;

use App\Models\Media\Artist;
use App\Models\Media\Metadata;

class GenreController extends Controller
{
    public function index()
    {
        $genres = $this->genreArtistIds()
            ->map(fn (array $artistIds, string $genre) => [
                'name' => $genre,
                'artist_count' => count($artistIds),
            ])
            ->sortByDesc('artist_count')
            ->values();

        return view('genres.index', compact('genres'));
    }

    public function show(string $genre)
    {
        $artistIds = $this->genreArtistIds()->get($genre, []);

        abort_if(empty($artistIds), 404);

        $artists = Artist::query()
            ->whereIn('id', $artistIds)
            ->withCount('plays')
            ->with(['albums' => fn ($q) => $q->withCount('plays')->orderByDesc('plays_count')->orderByDesc('created_at')])
            ->orderByDesc('plays_count')
            ->get();

        return view('genres.show', compact('genre', 'artists'));
    }

    /**
     * @return \Illuminate\Support\Collection<string, array<int>>
     */
    private function genreArtistIds(): \Illuminate\Support\Collection
    {
        $map = collect();

        Metadata::where('key', 'genres')
            ->where('metadatable_type', Artist::class)
            ->get(['metadatable_id', 'value', 'source'])
            ->groupBy('metadatable_id')
            ->each(function ($metas, $artistId) use ($map) {
                $meta = $metas->firstWhere('source', 'musicbrainz') ?? $metas->first();
                $genres = json_decode($meta->value ?? '', true) ?: [];

                foreach ($genres as $genre) {
                    $existing = $map->get($genre, []);
                    $existing[] = (int) $artistId;
                    $map->put($genre, $existing);
                }
            });

        return $map;
    }
}
