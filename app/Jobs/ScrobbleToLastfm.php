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

        $track = $play->track;
        if (!$track || !$track->artist) {
            return;
        }

        $duration = $track->duration;
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
            'artist' => $track->artist->name,
            'track' => $track->name,
            'timestamp' => $play->played_at->timestamp,
            'duration' => $duration,
        ];

        if ($track->album) {
            $params['album'] = $track->album->name;
        }

        $lastfm->signedRequest($params);
    }
}
