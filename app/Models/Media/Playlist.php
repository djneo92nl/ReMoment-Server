<?php

namespace App\Models\Media;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;

class Playlist extends Model
{
    protected $table = 'playlists';

    protected $fillable = [
        'name',
        'source',
        'external_id',
        'description',
        'images',
    ];

    protected $casts = [
        'images' => 'array',
    ];

    public function tracks(): BelongsToMany
    {
        return $this->belongsToMany(Track::class, 'playlist_track')
            ->withPivot('position')
            ->orderByPivot('position');
    }

    public function metadata(): MorphMany
    {
        return $this->morphMany(Metadata::class, 'metadatable');
    }

    public function isEditable(): bool
    {
        return $this->source === 'local';
    }

    public function spotifyOwner(): ?string
    {
        return $this->metadata()->where('key', 'spotify_owner')->value('value');
    }
}
