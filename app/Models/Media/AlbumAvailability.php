<?php

namespace App\Models\Media;

use Illuminate\Database\Eloquent\Model;

/** What a lookup provider answered for an album or catalogue album (see docs/architecture/availability-layer.md). */
class AlbumAvailability extends Model
{
    protected $table = 'album_availability';

    protected $guarded = [];

    protected $casts = ['checked_at' => 'datetime'];
}
