<?php

namespace App\Models\Media;

use App\Domain\Library\Normalizer;
use App\Models\Play;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasManyThrough;
use Illuminate\Database\Eloquent\Relations\MorphMany;

class Album extends Model
{
    protected $table = 'albums';

    protected $fillable = [
        'artist_id',
        'name',
        'source',
        'images',
        'colors',
        'released_at',
        'favorited_at',
    ];

    protected $casts = [
        'images' => 'array',
        'colors' => 'array',
        'released_at' => 'date',
        'favorited_at' => 'datetime',
    ];

    /** Keeps `name_key` (the identity used to find this album again across sources) in step with `name`. */
    protected static function booted(): void
    {
        static::saving(function (self $album) {
            if ($album->isDirty('name') || $album->name_key === null) {
                $album->name_key = Normalizer::album($album->name);
            }
        });
    }

    public function artist(): BelongsTo
    {
        return $this->belongsTo(Artist::class);
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

    public function label(): ?string
    {
        return $this->metadata()->where('key', 'label')->value('value');
    }
}
