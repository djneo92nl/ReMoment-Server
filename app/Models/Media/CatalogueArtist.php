<?php

namespace App\Models\Media;

use Illuminate\Database\Eloquent\Model;

/** Catalogue sync state of one library artist. */
class CatalogueArtist extends Model
{
    protected $table = 'catalogue_artists';

    protected $primaryKey = 'artist_id';

    public $incrementing = false;

    public $timestamps = false;

    protected $guarded = [];

    protected $casts = ['synced_at' => 'datetime', 'next_sync_at' => 'datetime'];
}
