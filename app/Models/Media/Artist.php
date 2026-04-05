<?php

namespace App\Models\Media;

use App\Models\Play;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasManyThrough;

class Artist extends Model
{
    protected $table = 'artists';

    protected $fillable = [
        'name',
        'source',
    ];

    public function albums(): HasMany
    {
        return $this->hasMany(Album::class);
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
