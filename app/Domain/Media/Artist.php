<?php

namespace App\Domain\Media;

class Artist
{
    public function __construct(
        public ?string $name = null,
        public ?array $images = null
    ) {}

    public function toArray(): array
    {
        return array_filter([
            'name' => $this->name,
            'images' => $this->images,
        ], fn ($value) => $value !== null);
    }
}
