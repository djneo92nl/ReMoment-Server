<?php

namespace App\Models\Media;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphToMany;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Str;

class Genre extends Model
{
    protected $fillable = ['name', 'slug', 'name_key'];

    public function artists(): MorphToMany
    {
        return $this->morphedByMany(Artist::class, 'genreable');
    }

    public function albums(): MorphToMany
    {
        return $this->morphedByMany(Album::class, 'genreable');
    }

    public function tracks(): MorphToMany
    {
        return $this->morphedByMany(Track::class, 'genreable');
    }

    /** The genre for a normalized key, created with $name when new (race-safe: a concurrent create is re-read). */
    public static function forKey(string $key, string $name): self
    {
        $find = fn () => static::query()->where('name_key', $key)->first();

        if ($genre = $find()) {
            return $genre;
        }

        $slug = Str::slug($name) ?: $key;
        for ($n = 2; static::query()->where('slug', $slug)->exists(); $n++) {
            $slug = Str::slug($name).'-'.$n;
        }

        try {
            return static::create(['name' => $name, 'slug' => $slug, 'name_key' => $key]);
        } catch (UniqueConstraintViolationException $e) {
            return $find() ?? throw $e;
        }
    }
}
