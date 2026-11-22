<?php

namespace App\Models;

use App\Domain\Podcasts\PodcastArtwork;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** A podcast the household subscribes to by RSS URL (docs/api/podcasts.md). */
class Podcast extends Model
{
    protected $fillable = [
        'feed_hash', 'feed_url', 'title', 'author', 'description', 'site_url', 'image_url', 'language', 'keep_episodes',
        'etag', 'last_modified', 'last_checked_at', 'last_success_at', 'next_check_at', 'failures', 'last_error',
    ];

    protected $casts = [
        'keep_episodes' => 'integer',
        'failures' => 'integer',
        'last_checked_at' => 'datetime',
        'last_success_at' => 'datetime',
        'next_check_at' => 'datetime',
    ];

    public function episodes(): HasMany
    {
        return $this->hasMany(PodcastEpisode::class);
    }

    /** The Podcast shape of the API; `episode_count` and `unplayed_count` come from withCount when the caller loaded them. */
    public function apiItem(): array
    {
        return [
            'id' => $this->id,
            'title' => $this->title,
            'author' => $this->author,
            'description' => $this->description,
            'site_url' => $this->site_url,
            'feed_url' => $this->feed_url,
            'language' => $this->language,
            'artwork' => PodcastArtwork::resolve($this->image_url),
            'episode_count' => (int) ($this->episodes_count ?? $this->episodes()->count()),
            'unplayed_count' => (int) ($this->unplayed_count ?? $this->episodes()->whereNull('played_at')->count()),
            'latest_episode_at' => $this->latest_episode_at ?? $this->episodes()->max('published_at'),
            'keep_episodes' => $this->keep_episodes,
            'last_checked_at' => $this->last_checked_at?->toIso8601String(),
            'last_error' => $this->failures > 0 ? $this->last_error : null,
        ];
    }
}
