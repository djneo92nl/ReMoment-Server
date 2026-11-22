<?php

namespace App\Models;

use App\Models\Media\Artist;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** One upcoming event of a library artist, from MusicBrainz (App\Domain\Concerts). */
class Concert extends Model
{
    protected $fillable = ['artist_id', 'event_mbid', 'name', 'type', 'starts_on', 'ends_on', 'starts_at', 'venue', 'city', 'address', 'latitude', 'longitude'];

    protected function casts(): array
    {
        return ['starts_on' => 'date', 'ends_on' => 'date', 'latitude' => 'float', 'longitude' => 'float'];
    }

    public function artist(): BelongsTo
    {
        return $this->belongsTo(Artist::class);
    }
}
