<?php

namespace App\Domain\Media;

class ArtistData
{
    public function __construct(
        public ?string $name = null,
        public ?array $images = null,
        public ?string $source = null,
    ) {}

    public function toArray(): array
    {
        return array_filter([
            'name' => $this->name,
            'images' => $this->images,
            'source' => $this->source,
        ], fn ($value) => $value !== null);
    }
}
