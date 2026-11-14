<?php

namespace App\Domain\Media;

class Radio
{
    public function __construct(
        public ?string $name = null,
        public ?string $genre = null,
        public array $images = [],
        public ?string $id = null,
        public bool $relay = false,
    ) {}

    public function toArray(): array
    {
        return array_filter([
            'name' => $this->name,
            'images' => $this->images,
            'genre' => $this->genre,
            'relay' => $this->relay ?: null,
        ], fn ($value) => $value !== null);
    }
}
