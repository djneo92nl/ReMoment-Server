<?php

namespace App\Models;

use App\Domain\Artwork\RadioStationArtwork;
use App\Models\Concerns\Favoritable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class RadioStation extends Model
{
    use Favoritable;

    protected $fillable = [
        'name',
        'image_url',
        'favorited_at',
        'last_played_at',
    ];

    protected $casts = [
        'favorited_at' => 'datetime',
        'last_played_at' => 'datetime',
    ];

    /** Record that the station was started through the server (feeds GET /api/library/recent-items). */
    public function markPlayed(): void
    {
        $this->forceFill(['last_played_at' => now()])->save();
    }

    /** The RadioStation shape of the API (docs/api/radio.md). */
    public function apiItem(): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            // RadioStation has no genre yet; kept in the shape for clients.
            'genre' => null,
            'favorite' => $this->favorited_at !== null,
            'artwork' => RadioStationArtwork::resolve($this),
        ];
    }

    public function plays(): HasMany
    {
        return $this->hasMany(Play::class);
    }

    public function meta(): HasMany
    {
        return $this->hasMany(RadioStationMeta::class);
    }

    public function getMeta(string $key): ?string
    {
        return $this->meta->firstWhere('key', $key)?->value;
    }

    public function setMeta(string $key, string $value): void
    {
        $this->meta()->updateOrCreate(
            ['key' => $key],
            ['value' => $value]
        );
        $this->unsetRelation('meta');
    }
}
