<?php

namespace App\Domain\Media;

class AlbumData
{
    public function __construct(
        public ?string $name = null,
        public array $images = [],
        public ?ArtistData $artist = null
    ) {}

    public function toArray(): array
    {
        return array_filter([
            'name' => $this->name,
            'images' => $this->images,
            'artist' => $this->artist?->toArray(),
        ], fn ($value) => $value !== null);
    }
}
