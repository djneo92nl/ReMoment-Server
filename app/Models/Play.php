<?php

namespace App\Models;

use App\Domain\Artwork\LibraryArtwork;
use App\Domain\Artwork\LibraryItemArtwork;
use App\Models\Media\Track;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\DB;

class Play extends Model
{
    protected $fillable = [
        'device_id', 'track_id', 'track_name', 'artist_name', 'album_name', 'image_url', 'duration', 'source_type', 'radio_name', 'radio_station_id', 'source_name', 'played_at', 'ended_at', 'skipped',
    ];

    protected $casts = [
        'played_at' => 'datetime',
        'ended_at' => 'datetime',
        'skipped' => 'boolean',
    ];

    public function device(): BelongsTo
    {
        return $this->belongsTo(Device::class);
    }

    public function track(): BelongsTo
    {
        return $this->belongsTo(Track::class);
    }

    public function radioStation(): BelongsTo
    {
        return $this->belongsTo(RadioStation::class);
    }

    /** The track's name: the library track's, else what was logged for a track that isn't in the library. */
    public function trackTitle(): ?string
    {
        return $this->track?->name ?? $this->track_name;
    }

    public function artistTitle(): ?string
    {
        return $this->track?->artist?->name ?? $this->artist_name;
    }

    public function albumTitle(): ?string
    {
        return $this->track?->album?->name ?? $this->album_name;
    }

    /** True for a track play (in the library or logged as text), false for radio and source plays. */
    /** The play's cover as our processed proxy at this size (null until processed), never the original image. */
    public function artUrl(int $size = 120): ?string
    {
        $raw = ($this->track
            ? (LibraryArtwork::coverUrl($this->track->images) ?? LibraryArtwork::coverUrl($this->track->album?->images))
            : null) ?? $this->image_url;

        return LibraryItemArtwork::proxy($raw, $size);
    }

    /** Seconds listened over the given plays (all plays by default): finished plays only, summed in SQL. */
    public static function secondsListened(?Builder $plays = null): int
    {
        $seconds = DB::getDriverName() === 'sqlite'
            ? 'CAST(ROUND((julianday(ended_at) - julianday(played_at)) * 86400) AS INTEGER)'
            : 'TIMESTAMPDIFF(SECOND, played_at, ended_at)';

        return (int) ($plays ?? static::query())
            ->whereNotNull('ended_at')
            ->whereRaw('ended_at > played_at')
            ->selectRaw("SUM($seconds) as seconds")
            ->value('seconds');
    }

    public function isTrackPlay(): bool
    {
        return $this->track_id !== null || $this->track_name !== null;
    }
}
