<?php

namespace App\Jobs;

use App\Models\Media\Track;
use App\Models\Play;
use App\Services\LastfmSessionService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class SendNowPlayingToLastfm implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /** A record merged away by library:merge-duplicates no longer exists; drop the job. */
    public bool $deleteWhenMissingModels = true;

    public int $tries = 3;

    public int $backoff = 60;

    /** A library track, or a play of a track that isn't in the library (names stored on the play). */
    public function __construct(public readonly Track|Play $track) {}

    public function handle(LastfmSessionService $lastfm): void
    {
        if (!$lastfm->isConnected()) {
            return;
        }

        if ($this->track instanceof Play) {
            $artist = $this->track->artist_name;
            $name = $this->track->track_name;
            $album = $this->track->album_name;
            $duration = $this->track->duration;
        } else {
            $track = $this->track->load('artist', 'album');
            $artist = $track->artist?->name;
            $name = $track->name;
            $album = $track->album?->name;
            $duration = $track->duration;
        }

        if (!$artist || !$name) {
            return;
        }

        $params = [
            'method' => 'track.updatenowplaying',
            'artist' => $artist,
            'track' => $name,
        ];

        if ($album) {
            $params['album'] = $album;
        }
        if ($duration) {
            $params['duration'] = $duration;
        }

        $lastfm->signedRequest($params);
    }
}
