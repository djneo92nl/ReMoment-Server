<?php

namespace App\Listeners\Device;

use App\Domain\Artwork\ArtworkCache;
use App\Domain\Artwork\NowPlayingArtwork;
use App\Events\Device\NowPlayingUpdated;
use App\Jobs\ProcessArtwork;

class DispatchArtworkProcessing
{
    public function handle(NowPlayingUpdated $event): void
    {
        // Also covers a radio station's image from /radio, and logos (normally
        // already rendered by PublishNowPlayingToMqtt).
        $url = NowPlayingArtwork::source($event->nowPlaying)['url'];

        if (ArtworkCache::has($url)) {
            return;
        }

        ProcessArtwork::dispatch($url);
    }
}
