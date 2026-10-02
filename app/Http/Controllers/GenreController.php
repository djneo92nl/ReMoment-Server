<?php

namespace App\Http\Controllers;

use App\Domain\Library\GenreNormalizer;
use App\Models\Media\Genre;

class GenreController extends Controller
{
    public function index()
    {
        $genres = Genre::query()
            ->withCount(['artists', 'albums'])
            ->get()
            ->filter(fn (Genre $genre) => $genre->artists_count > 0 || $genre->albums_count > 0)
            ->sortBy([['artists_count', 'desc'], ['albums_count', 'desc'], ['name', 'asc']])
            ->values();

        return view('genres.index', compact('genres'));
    }

    public function show(string $slug)
    {
        $genre = Genre::query()->where('slug', $slug)->first();

        if ($genre === null) {
            // Links made before genres were canonical used the plain name.
            $renamed = Genre::query()->where('name_key', GenreNormalizer::key($slug))->first();
            abort_if($renamed === null, 404);

            return redirect()->route('genres.show', $renamed->slug);
        }

        $artists = $genre->artists()
            ->withCount('plays')
            ->with(['albums' => fn ($q) => $q->withCount('plays')->orderByDesc('plays_count')->orderByDesc('created_at')])
            ->orderByDesc('plays_count')
            ->get();

        $albums = $genre->albums()->with('artist')->withCount('plays')->orderByDesc('plays_count')->orderBy('albums.name')->get();

        abort_if($artists->isEmpty() && $albums->isEmpty(), 404);

        return view('genres.show', compact('genre', 'artists', 'albums'));
    }
}
