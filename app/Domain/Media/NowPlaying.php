<?php

namespace App\Domain\Media;

class NowPlaying
{
    public function __construct(
        public ?track $track = null,
        public ?Artist $artist = null,
        public ?Album $album = null,
        public ?string $state = null,
        public ?int $position = null,
        public ?string $platform = null) {}

    public function toArray(): array
    {
        return array_filter([
            'track' => $this->track?->toArray(),
            'artist' => $this->artist?->toArray(),
            'album' => $this->album?->toArray(),
            'state' => $this->state,
            'position' => $this->position,
            'platform' => $this->platform,
        ], fn ($value) => $value !== null);
    }
}
