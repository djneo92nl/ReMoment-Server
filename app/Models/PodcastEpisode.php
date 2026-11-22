<?php

namespace App\Models;

use App\Domain\Podcasts\PodcastArtwork;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** One episode of a podcast: its enclosure is streamed from the publisher, nothing is downloaded (docs/api/podcasts.md). */
class PodcastEpisode extends Model
{
    protected $fillable = [
        'podcast_id', 'guid_hash', 'guid', 'title', 'description', 'enclosure_url', 'mime', 'size', 'duration',
        'image_url', 'season', 'number', 'published_at', 'played_at',
    ];

    protected $casts = [
        'size' => 'integer',
        'duration' => 'integer',
        'season' => 'integer',
        'number' => 'integer',
        'published_at' => 'datetime',
        'played_at' => 'datetime',
    ];

    public function podcast(): BelongsTo
    {
        return $this->belongsTo(Podcast::class);
    }

    /** The PodcastEpisode shape of the API. `$position` is the bookmark in seconds when there is one. */
    public function apiItem(?int $position = null): array
    {
        $podcast = $this->podcast;

        return [
            'id' => $this->id,
            'podcast_id' => $this->podcast_id,
            'podcast_title' => $podcast?->title,
            'title' => $this->title,
            'description' => $this->description,
            'published_at' => $this->published_at?->toIso8601String(),
            'duration' => $this->duration,
            'season' => $this->season,
            'number' => $this->number,
            'played' => $this->played_at !== null,
            'played_at' => $this->played_at?->toIso8601String(),
            'position' => $position,
            'enclosure' => ['url' => $this->enclosure_url, 'type' => $this->mime, 'size' => $this->size],
            'artwork' => PodcastArtwork::resolve($this->image_url ?: $podcast?->image_url),
        ];
    }
}
