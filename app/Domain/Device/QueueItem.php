<?php

namespace App\Domain\Device;

class QueueItem
{
    public function __construct(
        public string $name,
        public ?string $artist = null,
        public ?string $album = null,
        public ?string $image = null,
        public ?int $duration = null,
        public ?string $uri = null,
    ) {}

    public function toArray(): array
    {
        return [
            'name' => $this->name,
            'artist' => $this->artist,
            'album' => $this->album,
            'image' => $this->image,
            'duration' => $this->duration,
            'uri' => $this->uri,
        ];
    }
}
