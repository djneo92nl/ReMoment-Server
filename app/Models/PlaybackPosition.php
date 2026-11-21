<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** A bookmark in a long track or a podcast episode (docs/api/playback-positions.md). */
class PlaybackPosition extends Model
{
    public const TRACK = 'track';

    public const EPISODE = 'episode';

    public const KINDS = [self::TRACK, self::EPISODE];

    protected $fillable = ['kind', 'item_id', 'position', 'duration', 'device_id'];

    protected $casts = ['position' => 'integer', 'duration' => 'integer', 'item_id' => 'integer'];
}
