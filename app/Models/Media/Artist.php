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
}
