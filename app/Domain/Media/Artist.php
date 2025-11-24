<?php

namespace App\Domain\Media;

class Artist
{
    public ?string $name;

    public array $images = [];

    public function __construct(array $data = [])
    {
        $this->name = $data['name'] ?? null;
        $this->images = $data['images'] ?? [];
    }

    public function toArray(): array
    {
        return ['
            name' => $this->name,
        ];
    }
}
