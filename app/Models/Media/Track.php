<?php

namespace App\Models\Media;

use App\Domain\Library\Normalizer;
use App\Models\Media\Concerns\HasGenres;
use App\Models\Media\Concerns\ReadsMetadata;
use App\Models\Play;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;

class Track extends Model
{
    use HasGenres, ReadsMetadata;

    protected $table = 'tracks';

    protected $fillable = [
        'album_id',
        'artist_id',
        'external_id',
        'name',
        'duration',
        'source',
        'images',
    ];

    protected $casts = [
        'images' => 'array',
        'duration' => 'integer',
    ];

    /** Keeps `name_key` (the identity used to find this track again across sources) in step with `name`. */
    protected static function booted(): void
    {
        static::saving(function (self $track) {
            if ($track->isDirty('name') || $track->name_key === null) {
                $track->name_key = Normalizer::track($track->name);
            }
        });
    }

    public function album(): BelongsTo
    {
        return $this->belongsTo(Album::class);
    }

    public function artist(): BelongsTo
    {
        return $this->belongsTo(Artist::class);
    }

    public function plays(): HasMany
    {
        return $this->hasMany(Play::class);
    }

    public function metadata(): MorphMany
    {
        return $this->morphMany(Metadata::class, 'metadatable');
    }

    public function getDlnaUrl(): ?string
    {
        if ($this->relationLoaded('metadata')) {
            return $this->metadata->firstWhere('key', 'dlna_url')?->value;
        }

        return $this->metadata()->where('key', 'dlna_url')->value('value');
    }

    public function lyricsPlain(): ?string
    {
        $value = $this->relationLoaded('metadata')
            ? $this->metadata->firstWhere('key', 'lyrics_plain')?->value
            : $this->metadata()->where('key', 'lyrics_plain')->value('value');

        return ($value !== null && $value !== '') ? $value : null;
    }

    public function lyricsSynced(): ?string
    {
        $value = $this->relationLoaded('metadata')
            ? $this->metadata->firstWhere('key', 'lyrics_synced')?->value
            : $this->metadata()->where('key', 'lyrics_synced')->value('value');

        return ($value !== null && $value !== '') ? $value : null;
    }
}
