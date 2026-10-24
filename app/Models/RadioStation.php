<?php

namespace App\Models;

use App\Domain\Artwork\RadioStationArtwork;
use App\Domain\Radio\StationNowPlaying;
use App\Jobs\ReportRadioClick;
use App\Models\Concerns\Favoritable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class RadioStation extends Model
{
    use Favoritable;

    protected $fillable = [
        'name',
        'image_url',
        'genre',
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

        if ($uuid = $this->getMeta('radio_browser_uuid')) {
            ReportRadioClick::dispatch($uuid);
        }
    }

    /** The RadioStation shape of the API (docs/api/radio.md). */
    public function apiItem(): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'genre' => $this->genre,
            'favorite' => $this->favorited_at !== null,
            'artwork' => RadioStationArtwork::resolve($this),
            'now' => StationNowPlaying::for($this)?->toArray(),
        ];
    }

    /** Up to two capital letters for the tile of a station without a logo ("NPO Radio 2" → "NR"; the "NTS" prefix is dropped). */
    public function initials(): string
    {
        $words = collect(preg_split('/\s+/u', trim(preg_replace('/^NTS\s+/i', '', $this->name))))
            ->filter(fn ($w) => preg_match('/[\p{L}\d]/u', $w))->take(2);

        return mb_strtoupper($words->map(fn ($w) => mb_substr(preg_replace('/[^\p{L}\d]/u', '', $w), 0, 1))->implode(''));
    }

    public function plays(): HasMany
    {
        return $this->hasMany(Play::class);
    }

    public function radioPlays(): HasMany
    {
        return $this->hasMany(RadioPlay::class);
    }

    public function meta(): HasMany
    {
        return $this->hasMany(RadioStationMeta::class);
    }

    public function getMeta(string $key): ?string
    {
        return $this->meta->firstWhere('key', $key)?->value;
    }

    /** The plain stream URL (identifier `url`), playable by any device that can play a stream. */
    public function streamUrl(): ?string
    {
        return $this->getMeta('url');
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
