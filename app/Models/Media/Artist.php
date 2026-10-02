<?php

namespace App\Models\Media;

use App\Domain\Library\Normalizer;
use App\Models\Media\Concerns\HasGenres;
use App\Models\Media\Concerns\ReadsMetadata;
use App\Models\Play;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasManyThrough;
use Illuminate\Database\Eloquent\Relations\MorphMany;

class Artist extends Model
{
    use HasGenres, ReadsMetadata;

    protected $table = 'artists';

    protected $fillable = [
        'name',
        'source',
        'favorited_at',
    ];

    protected $casts = [
        'favorited_at' => 'datetime',
    ];

    /** Keeps `name_key` (the identity used to find this artist again across sources) in step with `name`. */
    protected static function booted(): void
    {
        static::saving(function (self $artist) {
            if ($artist->isDirty('name') || $artist->name_key === null) {
                $artist->name_key = Normalizer::artist($artist->name);
            }
        });
    }

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

    public function country(): ?string
    {
        return $this->metadata()->where('key', 'country')->value('value');
    }

    /** The Wikipedia summary when there is one, else the Last.fm bio, else TheAudioDB's. */
    public function bio(): ?string
    {
        return $this->metaValue('wikipedia_extract') ?? $this->metaValue('bio') ?? $this->metaValue('audiodb_bio');
    }

    /** What the web page and the library API show besides name and genres. */
    public function details(): array
    {
        return [
            'type' => $this->metaValue('artist_type'),
            'country' => $this->country(),
            'area' => $this->metaValue('area'),
            'begin_date' => $this->metaValue('begin_date'),
            'end_date' => $this->metaValue('end_date'),
            'bio' => $this->bio(),
            'wikipedia_url' => $this->metaValue('wikipedia_url'),
            'links' => (object) $this->metaJson('links'),
            'images' => (object) $this->metaJson('images'),
            'mood' => $this->metaValue('mood'),
            'style' => $this->metaValue('style'),
            'tags' => $this->metaJson('tags'),
            'listeners' => $this->metaInt('lastfm_listeners'),
            'playcount' => $this->metaInt('lastfm_playcount'),
        ];
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
