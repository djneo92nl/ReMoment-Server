<?php

namespace App\Domain\Media;

class Track
{
    public function __construct(
        public ?string $id = null,
        public ?string $name = null,
        public ?int $duration = null,   // seconds
        public array $images = []        // URLs
    ) {}

    public function toArray(): array
    {
        return array_filter([
            'id' => $this->id,
            'name' => $this->name,
            'duration' => $this->duration,
            'images' => $this->images,
        ], fn ($value) => $value !== null);
    }
}
