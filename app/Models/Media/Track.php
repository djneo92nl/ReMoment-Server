<?php

namespace App\Models\Media;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphMany;

class Track extends Model
{
    protected $table = 'tracks';

    protected $fillable = [
        'album_id',
        'artist_id',
        'external_id',
        'name',
        'duration',
        'source',
        'images',
    ];

    protected $casts = [
        'images' => 'array',
        'duration' => 'integer',
    ];

    public function album(): BelongsTo
    {
        return $this->belongsTo(Album::class);
    }

    public function artist(): BelongsTo
    {
        return $this->belongsTo(Artist::class);
    }

    public function metadata(): MorphMany
    {
        return $this->morphMany(Metadata::class, 'metadatable');
    }
}
