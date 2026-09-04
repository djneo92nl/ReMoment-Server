<?php

namespace App\Jobs;

use App\Models\Media\Track;
use App\Services\LastfmSessionService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class SendNowPlayingToLastfm implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public int $backoff = 60;

    public function __construct(public readonly Track $track) {}

    public function handle(LastfmSessionService $lastfm): void
    {
        if (!$lastfm->isConnected()) {
            return;
        }

        $track = $this->track->load('artist', 'album');

        if (!$track->artist) {
            return;
        }

        $params = [
            'method' => 'track.updatenowplaying',
            'artist' => $track->artist->name,
            'track' => $track->name,
        ];

        if ($track->album) {
            $params['album'] = $track->album->name;
        }
        if ($track->duration) {
            $params['duration'] = $track->duration;
        }

        $lastfm->signedRequest($params);
    }
}
