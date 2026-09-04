<?php

namespace App\Models\Media;

use App\Models\Play;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasManyThrough;
use Illuminate\Database\Eloquent\Relations\MorphMany;

class Artist extends Model
{
    protected $table = 'artists';

    protected $fillable = [
        'name',
        'source',
    ];

    public function albums(): HasMany
    {
        return $this->hasMany(Album::class);
    }

    public function tracks(): HasMany
    {
        return $this->hasMany(Track::class);
    }

    public function plays(): HasManyThrough
    {
        return $this->hasManyThrough(Play::class, Track::class);
    }

    public function metadata(): MorphMany
    {
        return $this->morphMany(Metadata::class, 'metadatable');
    }

    public function genres(): array
    {
        $metas = $this->metadata()->where('key', 'genres')->get();
        $meta = $metas->firstWhere('source', 'musicbrainz') ?? $metas->first();

        return ($meta?->value) ? (json_decode($meta->value, true) ?? []) : [];
    }

    public function country(): ?string
    {
        return $this->metadata()->where('key', 'country')->value('value');
    }

    public function bio(): ?string
    {
        return $this->metadata()->where('key', 'bio')->value('value');
    }

    public function similarArtists(): array
    {
        $value = $this->metadata()->where('key', 'similar_artists')->where('source', 'lastfm')->value('value');

        return $value ? (json_decode($value, true) ?? []) : [];
    }

    /**
     * Similar-artist names resolved against artists already in the library, keyed by name.
     * Names with no local match are omitted (nothing to link to).
     */
    public function similarArtistModels(): \Illuminate\Support\Collection
    {
        $names = $this->similarArtists();

        if (empty($names)) {
            return collect();
        }

        return static::query()->whereIn('name', $names)->get()->keyBy('name');
    }

    public function coverAlbum(): ?Album
    {
        if (!$this->relationLoaded('albums')) {
            $this->load(['albums' => fn ($q) => $q->withCount('plays')
                ->orderByDesc('plays_count')
                ->orderByDesc('created_at')]);
        }

        return $this->albums->first(fn (Album $album) => !empty($album->images));
    }
}
