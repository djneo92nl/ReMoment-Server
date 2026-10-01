<?php

namespace App\Models\Media;

use Illuminate\Database\Eloquent\Builder;
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
        'last_played_at',
    ];

    protected $casts = [
        'images' => 'array',
        'last_played_at' => 'datetime',
    ];

    public function tracks(): BelongsToMany
    {
        return $this->belongsToMany(Track::class, 'playlist_track')
            ->withPivot('position')
            ->orderByPivot('position');
    }

    /**
     * Playlists with at least one track, most recently played from the
     * server first (`last_played_at`), then the never-played ones by name.
     */
    public function scopeWithTracksByRecency(Builder $query): Builder
    {
        return $query
            ->whereHas('tracks')
            ->orderByRaw('CASE WHEN playlists.last_played_at IS NULL THEN 1 ELSE 0 END')
            ->orderByDesc('playlists.last_played_at')
            ->orderByRaw('LOWER(playlists.name)')
            ->orderBy('playlists.id');
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
