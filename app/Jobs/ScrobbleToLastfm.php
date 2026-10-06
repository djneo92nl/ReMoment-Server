<?php

namespace App\Jobs;

use App\Models\Play;
use App\Services\LastfmSessionService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class ScrobbleToLastfm implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public int $backoff = 60;

    public function __construct(public readonly Play $play) {}

    public function handle(LastfmSessionService $lastfm): void
    {
        if (!$lastfm->isConnected()) {
            return;
        }

        $play = $this->play->fresh(['track.artist', 'track.album']);

        if (!$play || !$play->ended_at || $play->skipped) {
            return;
        }

        // A play of a track that isn't in the library carries its names itself.
        $artist = $play->track?->artist?->name ?? $play->artist_name;
        $name = $play->track?->name ?? $play->track_name;
        $album = $play->track?->album?->name ?? $play->album_name;
        $duration = $play->track?->duration ?? $play->duration;

        if (!$artist || !$name) {
            return;
        }

        if (!$duration || $duration <= 30) {
            return;
        }

        // Last.fm's scrobble rule: at least half the track, capped at 4 minutes
        $listenedSeconds = $play->played_at->diffInSeconds($play->ended_at);
        $threshold = min(intdiv($duration, 2), 240);

        if ($listenedSeconds < $threshold) {
            return;
        }

        $params = [
            'method' => 'track.scrobble',
            'artist' => $artist,
            'track' => $name,
            'timestamp' => $play->played_at->timestamp,
            'duration' => $duration,
        ];

        if ($album) {
            $params['album'] = $album;
        }

        $lastfm->signedRequest($params);
    }
}
