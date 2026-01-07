<?php

namespace App\Domain\Media;

class Radio
{
    public function __construct(
        public ?string $name = null,
        public array $images = [],
    ) {}

    public function toArray(): array
    {
        return array_filter([
            'name' => $this->name,
            'images' => $this->images,
        ], fn ($value) => $value !== null);
    }
}
