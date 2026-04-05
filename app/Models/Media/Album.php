<?php

namespace App\Models\Media;

use App\Models\Play;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasManyThrough;

class Album extends Model
{
    protected $table = 'albums';

    protected $fillable = [
        'artist_id',
        'name',
        'source',
        'images',
        'colors',
        'released_at',
    ];

    protected $casts = [
        'images' => 'array',
        'colors' => 'array',
        'released_at' => 'date',
    ];

    public function artist(): BelongsTo
    {
        return $this->belongsTo(Artist::class);
    }

    public function tracks(): HasMany
    {
        return $this->hasMany(Track::class);
    }

    public function plays(): HasManyThrough
    {
        return $this->hasManyThrough(Play::class, Track::class);
    }
}
