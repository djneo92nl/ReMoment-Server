<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class RadioStationMeta extends Model
{
    public $timestamps = false;

    protected $table = 'radio_station_meta';

    protected $fillable = [
        'radio_station_id',
        'key',
        'value',
    ];

    public function station(): BelongsTo
    {
        return $this->belongsTo(RadioStation::class, 'radio_station_id');
    }
}
