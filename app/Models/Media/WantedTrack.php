<?php

namespace App\Models\Media;

use App\Domain\Library\Normalizer;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** A song the household wants to buy or rip (docs/api/availability.md, "Wished songs"). */
class WantedTrack extends Model
{
    protected $table = 'wanted_tracks';

    protected $guarded = [];

    protected $casts = ['wanted_at' => 'datetime', 'fulfilled_at' => 'datetime'];

    protected static function booted(): void
    {
        static::saving(function (self $song) {
            if ($song->isDirty('artist') || $song->artist_key === null) {
                $song->artist_key = Normalizer::artist($song->artist);
            }
            if ($song->isDirty('title') || $song->title_key === null) {
                $song->title_key = Normalizer::track($song->title);
            }
        });
    }

    public function track(): BelongsTo
    {
        return $this->belongsTo(Track::class);
    }
}
