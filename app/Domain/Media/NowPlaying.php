<?php

namespace App\Domain\Media;

class NowPlaying
{
    public ?Track $track = null;

    public ?Artist $artist = null;

    public ?Album $album = null;

    public ?string $state = null;       // playing, paused, stopped

    public ?int $position = null;

    public ?string $platform = null;

    public function __construct(array $data = [])
    {
        $this->track = $data['track'] ?? null;
        $this->artist = $data['artist'] ?? null;
        $this->album = $data['album'] ?? null;
        $this->state = $data['state'] ?? null;
        $this->position = $data['position'] ?? null;
        $this->platform = $data['platform'] ?? null;
    }

    public function toArray(): array
    {
        return [
            'track' => $this->track->toArray(),
            'artist' => $this->artist->toArray(),
            'album' => $this->album->toArray(),
            'state' => $this->state,
            'position' => $this->position,
            'platform' => $this->platform,
        ];
    }
}
