<?php

namespace App\Domain\Library;

use App\Models\Media\Genre;
use Illuminate\Database\Eloquent\Model;

/**
 * Adds a record's raw genre strings, normalized, to its canonical genres (`genreables`). Additive: a genre
 * keeps its best position across sources and never disappears because a source stops reporting it.
 */
final class GenreSync
{
    /** @param  iterable<mixed>  $raw  genre strings, best first */
    public static function sync(Model $model, iterable $raw, ?string $source): void
    {
        $existing = $model->genreRelation()->get()->mapWithKeys(fn (Genre $g) => [$g->id => (int) $g->pivot->position]);

        foreach (GenreNormalizer::normalize($raw) as $position => [$key, $name]) {
            $genre = Genre::forKey($key, $name);

            if (!$existing->has($genre->id)) {
                $model->genreRelation()->attach($genre->id, ['position' => $position, 'source' => $source]);
                $existing->put($genre->id, $position);
            } elseif ($existing[$genre->id] > $position) {
                $model->genreRelation()->updateExistingPivot($genre->id, ['position' => $position, 'source' => $source]);
            }
        }
    }
}
