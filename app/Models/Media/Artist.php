<?php

namespace App\Models\Media;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

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
}
