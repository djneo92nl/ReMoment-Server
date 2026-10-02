<?php

namespace App\Models\Media\Concerns;

use App\Models\Media\Genre;
use Illuminate\Database\Eloquent\Relations\MorphToMany;

/** Canonical genres of an artist, album or track, best first (see GenreSync). */
trait HasGenres
{
    public function genreRelation(): MorphToMany
    {
        return $this->morphToMany(Genre::class, 'genreable')
            ->withPivot(['position', 'source'])
            ->orderByPivot('position')
            ->orderBy('genres.name');
    }

    /** @return list<string> */
    public function genres(): array
    {
        $genres = $this->relationLoaded('genreRelation') ? $this->genreRelation : $this->genreRelation()->get();

        return $genres->pluck('name')->all();
    }
}
