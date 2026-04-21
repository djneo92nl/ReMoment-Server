<?php

namespace App\Domain\Media;

class AlbumData
{
    public function __construct(
        public ?string $name = null,
        public array $images = [],
        public ?ArtistData $artist = null,
        public ?string $released_at = null,
    ) {}

    public function toArray(): array
    {
        return array_filter([
            'name' => $this->name,
            'images' => $this->images,
            'artist' => $this->artist?->toArray(),
            'released_at' => $this->released_at,
        ], fn ($value) => $value !== null);
    }
}
