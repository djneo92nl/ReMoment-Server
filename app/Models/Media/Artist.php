<?php

namespace App\Models\Media;

use App\Domain\Artwork\LibraryArtwork;
use App\Domain\Library\Normalizer;
use App\Models\Concerns\Favoritable;
use App\Models\Media\Concerns\HasGenres;
use App\Models\Media\Concerns\ReadsMetadata;
use App\Models\Play;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasManyThrough;
use Illuminate\Database\Eloquent\Relations\MorphMany;

class Artist extends Model
{
    use Favoritable, HasGenres, ReadsMetadata;

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
    /** SQL sort key: lowercase name without a leading "The ". */
    public const SORT_NAME_SQL = "LOWER(CASE WHEN LOWER(artists.name) LIKE 'the %' THEN SUBSTR(artists.name, 5) ELSE artists.name END)";

    protected static function booted(): void
    {
        static::saving(function (self $artist) {
            if ($artist->isDirty('name') || $artist->name_key === null) {
                $artist->name_key = Normalizer::artist($artist->name);
            }
            if (mb_strlen((string) $artist->name) > 255) {
                $artist->name = Normalizer::storedName($artist->name);
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
        return $this->metaValue('country');
    }

    /** The Wikipedia summary when there is one, else the Last.fm bio, else TheAudioDB's. */
    public function bio(): ?string
    {
        return $this->manualValue('bio') ?? $this->metaValue('wikipedia_extract') ?? $this->metaValue('bio') ?? $this->metaValue('audiodb_bio');
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

    /**
     * Last.fm's similar artists that exist in the library (matched on the name key, so "The National" finds
     * "National"), in Last.fm's order of relevance, never the artist itself; only artists with an album, so the
     * app has a page to open. Empty while Last.fm has not been read for this artist.
     *
     * @return \Illuminate\Support\Collection<int, static>
     */
    public function relatedArtists(int $limit = 12): \Illuminate\Support\Collection
    {
        $keys = array_values(array_unique(array_map(Normalizer::artist(...), array_filter($this->similarArtists(), 'is_string'))));
        if (!$keys) {
            return collect();
        }

        $found = static::query()->whereIn('name_key', $keys)->where('id', '!=', $this->id)->whereHas('albums')
            ->with(['metadata' => fn ($q) => $q->whereIn('key', self::PHOTO_METADATA), 'albums' => fn ($q) => $q->withCount('plays')->orderByDesc('plays_count')->orderByDesc('created_at')])
            ->get()->groupBy('name_key');

        return collect($keys)->map(fn (string $key) => $found->get($key)?->sortBy('id')->first())->filter()->take($limit)->values();
    }

    /** Metadata keys photoUrl() reads: eager load just these for a list of artist cards (ReadsMetadata uses the loaded relation). */
    public const PHOTO_METADATA = ['images', 'spotify_image'];

    /** The artist's own photo: the one picked in the settings, else TheAudioDB's, else Spotify's; null when none is known. */
    public function photoUrl(): ?string
    {
        return $this->metaValue('photo') ?? ((array) $this->metaJson('images'))['thumb'] ?? $this->metaValue('spotify_image');
    }

    /** The one image that stands for the artist in lists and rows: their photo, else the cover of their most played album. */
    public function avatarUrl(): ?string
    {
        return $this->photoUrl() ?? LibraryArtwork::coverUrl($this->coverAlbum()?->images);
    }

    /** TheAudioDB's fanart (a wide backdrop photo, remote URL); null when unknown. */
    public function fanartUrl(): ?string
    {
        return ((array) $this->metaJson('images'))['fanart'] ?? null;
    }

    /** Wide photos that may be shown as backdrop and collage: TheAudioDB's fanart 1–4 and wide thumb plus added ones, minus hidden ones. @return list<string> */
    public function galleryUrls(): array
    {
        return array_values(array_diff($this->wideImageUrls(), $this->hiddenImages()));
    }

    /** Every wide photo known, hidden or not (remote URLs, distinct). @return list<string> */
    public function wideImageUrls(): array
    {
        $images = (array) $this->metaJson('images');

        return array_values(array_unique(array_filter([
            ...array_map(fn (string $key) => $images[$key] ?? null, ['fanart', 'fanart2', 'fanart3', 'fanart4', 'wide_thumb']),
            ...$this->extraImages(),
        ])));
    }

    /** Pictures an admin added by URL or upload (pseudo URLs for uploads). @return list<string> */
    public function extraImages(): array
    {
        return array_values(array_filter((array) $this->metaJson('extra_images'), 'is_string'));
    }

    /** Pictures an admin switched off for the hero. @return list<string> */
    public function hiddenImages(): array
    {
        return array_values(array_filter((array) $this->metaJson('hidden_images'), 'is_string'));
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
