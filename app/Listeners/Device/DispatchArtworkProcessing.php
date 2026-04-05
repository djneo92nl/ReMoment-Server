<?php

namespace App\Listeners\Device;

use App\Domain\Artwork\ArtworkCache;
use App\Events\Device\NowPlayingUpdated;
use App\Jobs\ProcessArtwork;

class DispatchArtworkProcessing
{
    public function handle(NowPlayingUpdated $event): void
    {
        $url = ArtworkCache::extractImageUrl($event->nowPlaying);

        if ($url === null || ArtworkCache::has($url)) {
            return;
        }

        ProcessArtwork::dispatch($url);
    }
}
