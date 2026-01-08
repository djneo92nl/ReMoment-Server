<?php

namespace App\Domain\Media;

class Track
{
    public function __construct(
        public ?string $id = null,
        public ?string $name = null,
        public ?string $source = null,
        public ?Artist $artist = null,
        public ?int $duration = null,   // seconds
        public array $images = [],        // URLs
        public array $meta = []        // URLs
    ) {}

    public function toArray(): array
    {
        return array_filter([
            'id' => $this->id,
            'name' => $this->name,
            'duration' => $this->duration,
            'source' => $this->source,
            'artist' => $this->artist?->toArray(),
            'images' => $this->images,
            'meta' => $this->meta,
        ], fn ($value) => $value !== null);
    }
}
