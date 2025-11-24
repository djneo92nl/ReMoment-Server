<?php

namespace App\Domain\Media;

class Track
{
    public ?string $id;

    public ?string $name;

    public ?int $duration;          // seconds

    public array $images = [];      // URLs

    public function __construct(array $data = [])
    {
        $this->id = $data['id'] ?? null;
        $this->name = $data['name'] ?? null;
        $this->duration = $data['duration'] ?? null;
        $this->images = $data['images'] ?? [];
    }
}
