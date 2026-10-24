<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** A song heard on a station's stream (station-level log, whether or not a device played it; see docs/architecture/radio.md). */
class RadioPlay extends Model
{
    public $timestamps = false;

    protected $fillable = ['radio_station_id', 'artist', 'title', 'source', 'started_at'];

    protected $casts = ['started_at' => 'datetime'];

    public function station(): BelongsTo
    {
        return $this->belongsTo(RadioStation::class, 'radio_station_id');
    }
}
